#!/usr/bin/env python3
"""F1-273: actual Chrome keyboard/viewport QA of two private native FAQ sites.

Only pre-verified fictional PHP HTML, transmitted through local CDP on
about:blank. No server, web request, screenshot upload, client or deploy.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
from urllib.request import urlopen

from f43_cdp import CDP, read_local_devtools_port


def fail(code: str) -> None:
    raise RuntimeError("F1-273 " + code)


def key(client: CDP, name: str, code: str, number: int) -> None:
    params = {
        "key": name, "code": code,
        "windowsVirtualKeyCode": number, "nativeVirtualKeyCode": number,
    }
    client.call("Input.dispatchKeyEvent", {"type": "rawKeyDown", **params})
    client.call("Input.dispatchKeyEvent", {"type": "keyUp", **params})


def main(browser: str) -> None:
    temp = os.getenv("RUNNER_TEMP")
    if (os.getenv("GITHUB_ACTIONS") != "true"
        or os.getenv("APP_ENV") != "testing"
        or os.getenv("SITES_F273_FAQ_CHROME") != "1"
        or not temp
        or not Path(temp).is_dir()
        or Path(temp).is_symlink()
        or not Path(browser).is_file()):
        fail("isolated CI-only Chrome source missing")

    source = Path(temp) / "sites-f273-native-faq.json"
    if source.is_symlink() or not source.is_file() or source.stat().st_size > 330000:
        fail("private source fixture unavailable")
    item = json.loads(source.read_text(encoding="utf-8"))
    if (item.get("schema") != "sites.synthetic.faq-chrome.v1"
        or item.get("syntheticOnly") is not True
        or set(item.get("pages", {}).keys()) != {"onepage", "landing_page"}):
        fail("unexpected preview inventory")
    pages = item["pages"]
    for product, info in pages.items():
        html = info["html"]
        if (info["publishable"] is not False
            or len(html.encode("utf-8")) > 150000
            or hashlib.sha256(html.encode("utf-8")).hexdigest() != info["sha256"]
            or html.count("<details>") != 2
            or html.count("<summary>") != 2
            or "noindex, nofollow" not in html
            or any(word in html.lower() for word in
                   ["<form", "<script", "https://", "http://"])):
            fail("untrusted or modified source " + product)

    with tempfile.TemporaryDirectory(prefix="sites-f273-chrome-",
                                      ignore_cleanup_errors=True) as scratch:
        profile = Path(scratch) / "profile"
        chrome = subprocess.Popen([
            browser, "--headless=new", "--no-sandbox", "--disable-gpu",
            "--disable-dev-shm-usage", "--disable-background-networking",
            "--disable-extensions", "--disable-sync", "--no-first-run",
            "--no-default-browser-check", "--remote-debugging-port=0",
            f"--user-data-dir={profile}", "about:blank",
        ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            port = None
            for _ in range(200):
                if chrome.poll() is not None:
                    fail("Chrome exited before local CDP started")
                port = read_local_devtools_port(profile / "DevToolsActivePort")
                if port is not None:
                    break
                time.sleep(0.1)
            if port is None:
                fail("Chrome DevTools port unavailable")
            target = None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list",
                                 timeout=3) as answer:
                        tabs = json.loads(answer.read())
                    target = next(
                        (tab for tab in tabs if isinstance(tab, dict)
                         and tab.get("type") == "page"), None
                    )
                    if target:
                        break
                except (OSError, ValueError):
                    pass
                if chrome.poll() is not None:
                    fail("Chrome terminated before CDP endpoint was ready")
                time.sleep(.1)
            if target is None:
                fail("Chrome page target unavailable")
            endpoint = target["webSocketDebuggerUrl"].split(f":{port}", 1)[1]
            client = CDP(port, endpoint)
            try:
                client.call("Page.enable")
                client.call("Runtime.enable")
                for product in ("onepage", "landing_page"):
                    for device, width, height, mobile in [
                        ("desktop", 1365, 900, False),
                        ("mobile", 390, 844, True),
                        ("narrow", 320, 740, True),
                    ]:
                        client.call("Emulation.setDeviceMetricsOverride", {
                            "width": width, "height": height,
                            "deviceScaleFactor": 1, "mobile": mobile,
                        })
                        frame = client.call("Page.navigate", {
                            "url": "about:blank"
                        })["frameId"]
                        client.call("Page.setDocumentContent", {
                            "frameId": frame, "html": pages[product]["html"],
                        })
                        for _ in range(100):
                            if client.evaluate(
                                "document.readyState==='complete' && "
                                "document.querySelectorAll('section.faq summary').length===2"
                            ):
                                break
                            time.sleep(.05)
                        else:
                            fail(product + " " + device + " document not ready")

                        metrics = client.evaluate("""(() => {
                          const nodes=[...document.querySelectorAll('section.faq details')];
                          const summaries=nodes.map(d=>d.querySelector('summary'));
                          return {
                            width:innerWidth, scroll:document.documentElement.scrollWidth,
                            body:document.body.scrollWidth,
                            headings:document.querySelectorAll('h1').length,
                            count:nodes.length, closed:nodes.every(d=>!d.open),
                            named:summaries.every(s=>s && s.textContent.trim().length>0),
                            size:summaries.map(s=>{
                              const r=s.getBoundingClientRect();
                              return {height:r.height,width:r.width,
                                left:r.left,right:r.right};
                            }),
                            noindex:document.querySelector('meta[name="robots"]')?.content,
                            scripts:document.querySelectorAll('script,form,iframe').length
                          };
                        })()""")
                        if (metrics["width"] != width
                            or metrics["scroll"] > width + 2
                            or metrics["body"] > width + 2
                            or metrics["headings"] != 1
                            or metrics["count"] != 2
                            or not metrics["closed"]
                            or not metrics["named"]
                            or metrics["noindex"] != "noindex, nofollow"
                            or metrics["scripts"] != 0
                            or not all(
                                size["height"] >= 44
                                and size["width"] >= 44
                                and size["left"] >= -1
                                and size["right"] <= width+2
                                for size in metrics["size"]
                            )):
                            fail(product + " " + device +
                                 " responsive native FAQ semantics rejected: " +
                                 str(metrics))

                        # Focus only the first summary and test genuine
                        # key events. Script assignment to .open is forbidden.
                        focused=client.evaluate("""(() => {
                          document.querySelector('section.faq summary').focus();
                          return document.activeElement.tagName;
                        })()""")
                        if focused != "SUMMARY":
                            fail(product + " first summary is not focusable")
                        key(client,"Enter","Enter",13)
                        if not client.evaluate("""(() => {
                          const all=[...document.querySelectorAll('section.faq details')];
                          return all[0].open && !all[1].open &&
                            all[0].querySelector('p').getBoundingClientRect().height>0;
                        })()"""):
                            fail(product + " Enter did not reveal FAQ answer")
                        key(client," ","Space",32)
                        if not client.evaluate(
                            "!document.querySelector('section.faq details').open"
                        ):
                            fail(product + " Space did not close FAQ answer")
                        key(client,"Tab","Tab",9)
                        if not client.evaluate("""(() => {
                          return document.activeElement ===
                            document.querySelectorAll('section.faq summary')[1];
                        })()"""):
                            fail(product + " second FAQ was not reachable by Tab")
                        key(client,"Enter","Enter",13)
                        if not client.evaluate("""(() => {
                          const all=[...document.querySelectorAll('section.faq details')];
                          return !all[0].open && all[1].open;
                        })()"""):
                            fail(product + " Enter did not open second FAQ")
                        print(
                            f"PASS F1-273 {product}/{device}: {width}x{height}, "
                            "no overflow, >=44px summary targets, Tab/Enter/Space"
                        )
            finally:
                client.close()
        finally:
            chrome.terminate()
            try:
                chrome.wait(timeout=5)
            except subprocess.TimeoutExpired:
                chrome.kill()
                chrome.wait(timeout=5)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        fail("browser executable path required")
    main(sys.argv[1])
