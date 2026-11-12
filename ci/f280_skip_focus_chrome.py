#!/usr/bin/env python3
"""F1-280: verify real Chrome first Tab, focus-visible and Enter to <main>.

Uses the exact SHA-pinned PHP fixture over loopback CDP on about:blank.
No browser download, internet page, screenshot artifact or real client.
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


def fail(message: str) -> None:
    raise RuntimeError("F1-280 " + message)


def key(client: CDP, name: str, code: str, number: int) -> None:
    params = {
        "key": name, "code": code,
        "windowsVirtualKeyCode": number,
        "nativeVirtualKeyCode": number,
    }
    down = {"type": "keyDown", **params}
    if name == "Enter":
        down["text"] = "\r"
    client.call("Input.dispatchKeyEvent", down)
    client.call("Input.dispatchKeyEvent", {"type": "keyUp", **params})


def main(browser: str) -> None:
    temp = os.getenv("RUNNER_TEMP")
    if (os.getenv("GITHUB_ACTIONS") != "true"
        or os.getenv("APP_ENV") != "testing"
        or os.getenv("SITES_F280_SKIP_CHROME") != "1"
        or not temp or not Path(temp).is_dir()
        or Path(temp).is_symlink()
        or not Path(browser).is_file()):
        fail("isolated CI-only Chromium required")

    source = Path(temp) / "sites-f280-skip-focus.json"
    if source.is_symlink() or not source.is_file() or source.stat().st_size > 330000:
        fail("private fixture unavailable")
    fixture = json.loads(source.read_text(encoding="utf-8"))
    pages = fixture.get("pages")
    if (fixture.get("schema") != "sites.synthetic.skip-focus-chrome.v1"
        or fixture.get("syntheticOnly") is not True
        or not isinstance(pages, dict)
        or set(pages) != {"onepage", "landing_page"}):
        fail("unapproved fixture inventory")
    for product, meta in pages.items():
        html = meta["html"]
        if (meta["publishable"] is not False
            or len(html.encode()) > 150000
            or hashlib.sha256(html.encode()).hexdigest() != meta["sha256"]
            or html.count('<a class="skip-content" href="#principal">') != 1
            or html.count('<main id="principal" tabindex="-1">') != 1
            or "noindex, nofollow" not in html
            or any(fragment in html.lower() for fragment in
                   ["<script", "<form", "http://", "https://"])):
            fail(product + " modified or active preview")

    with tempfile.TemporaryDirectory(prefix="sites-f280-chrome-",
                                      ignore_cleanup_errors=True) as work:
        profile = Path(work) / "profile"
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
                    fail("Chrome exited during DevTools startup")
                port = read_local_devtools_port(profile / "DevToolsActivePort")
                if port is not None:
                    break
                time.sleep(.1)
            if port is None:
                fail("Chrome CDP bootstrap port unavailable")
            target = None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list",
                                 timeout=3) as response:
                        items = json.loads(response.read())
                    target = next((i for i in items if i.get("type") == "page"), None)
                    if target:
                        break
                except (OSError, ValueError):
                    pass
                if chrome.poll() is not None:
                    fail("Chrome exited before target ready")
                time.sleep(.1)
            if target is None:
                fail("Chrome CDP target unavailable")
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
                            "url": "about:blank",
                        })["frameId"]
                        client.call("Page.setDocumentContent", {
                            "frameId": frame, "html": pages[product]["html"],
                        })
                        for _ in range(100):
                            if client.evaluate(
                                "document.readyState==='complete' && "
                                "document.querySelector('a.skip-content')!==null"
                            ):
                                break
                            time.sleep(.05)
                        else:
                            fail(product + " " + device + " HTML not ready")
                        baseline = client.evaluate("""(() => {
                          const a=document.querySelector('a.skip-content');
                          const main=document.getElementById('principal');
                          return {
                            width:innerWidth,scroll:document.documentElement.scrollWidth,
                            body:document.body.scrollWidth,
                            links:document.querySelectorAll('a.skip-content').length,
                            target:main?.getAttribute('tabindex'),
                            href:a?.getAttribute('href'),
                            robots:document.querySelector('meta[name="robots"]')?.content,
                            active:document.activeElement?.tagName,
                            forms:document.querySelectorAll('form,script,iframe').length
                          };
                        })()""")
                        if (baseline["width"] != width
                            or baseline["scroll"] > width + 2
                            or baseline["body"] > width + 2
                            or baseline["links"] != 1
                            or baseline["target"] != "-1"
                            or baseline["href"] != "#principal"
                            or baseline["robots"] != "noindex, nofollow"
                            or baseline["active"] != "BODY"
                            or baseline["forms"] != 0):
                            fail(product + " " + device + " baseline " + str(baseline))
                        key(client, "Tab", "Tab", 9)
                        focus = client.evaluate("""(() => {
                          const a=document.querySelector('a.skip-content');
                          const r=a.getBoundingClientRect();
                          return {
                            active:document.activeElement===a,
                            transform:getComputedStyle(a).transform,
                            width:r.width,height:r.height,left:r.left,right:r.right
                          };
                        })()""")
                        if (not focus["active"]
                            or focus["width"] < 44
                            or focus["height"] < 44
                            or focus["left"] < -1
                            or focus["right"] > width+2
                            or focus["transform"] != "none"):
                            fail(product + " " + device +
                                 " keyboard-focus skip link hidden or unreachable: " +
                                 str(focus))
                        key(client, "Enter", "Enter", 13)
                        target = client.evaluate("""(() => ({
                          hash:location.hash,
                          active:document.activeElement?.id,
                          main:document.getElementById('principal')?.getAttribute('tabindex'),
                          external:location.protocol.startsWith('http')
                        }))()""")
                        if (target["hash"] != "#principal"
                            or target["active"] != "principal"
                            or target["main"] != "-1"
                            or target["external"]):
                            fail(product + " " + device +
                                 " Enter did not focus main: " + str(target))
                        print(
                            f"PASS F1-280 {product}/{device}: {width}x{height}, "
                            "first Tab skip visible and Enter focuses main"
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
        fail("explicit Chrome path required")
    main(sys.argv[1])
