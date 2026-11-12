#!/usr/bin/env python3
"""F1-284: actual browser Tab/Enter verification for offline skip-to-main HTML.

Only fixture HTML emitted and hashed by the PHP engine; CDP operates on local
about:blank. No HTTP server, public URL, client data or screenshot upload.
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


def fail(reason: str) -> None:
    raise RuntimeError("F1-284 " + reason)


def key(client: CDP, name: str, code: str, number: int) -> None:
    event = {
        "key": name, "code": code,
        "windowsVirtualKeyCode": number,
        "nativeVirtualKeyCode": number,
    }
    down = {"type": "keyDown", **event}
    if name == "Enter":
        down["text"] = "\r"
    client.call("Input.dispatchKeyEvent", down)
    client.call("Input.dispatchKeyEvent", {"type": "keyUp", **event})


def main(browser: str) -> None:
    temp=os.getenv("RUNNER_TEMP")
    if (os.getenv("GITHUB_ACTIONS") != "true"
        or os.getenv("APP_ENV") != "testing"
        or os.getenv("SITES_F284_SKIP_CHROME") != "1"
        or not temp or not Path(temp).is_dir()
        or Path(temp).is_symlink()
        or not Path(browser).is_file()):
        fail("requires isolated CI-only browser")
    source=Path(temp)/"sites-f284-native-faq.json"
    if source.is_symlink() or not source.is_file() or source.stat().st_size>330000:
        fail("private PHP-exported fixture missing")
    doc=json.loads(source.read_text("utf-8"))
    if (doc.get("schema")!="sites.synthetic.skip-chrome.v1"
        or doc.get("syntheticOnly") is not True
        or set(doc.get("pages",{}))!={"onepage","landing_page"}):
        fail("untrusted preview schema")
    pages=doc["pages"]
    for product,entry in pages.items():
        html=entry["html"]
        if (entry["publishable"] is not False
            or len(html.encode("utf-8"))>150000
            or hashlib.sha256(html.encode("utf-8")).hexdigest()!=entry["sha256"]
            or html.count('<a class="skip-to-main" href="#principal">')!=1
            or html.count('<main id="principal">')!=1
            or "noindex, nofollow" not in html
            or any(keyword in html.lower() for keyword in
                   ["<script","<form","https://","http://"])):
            fail("HTML source does not match private audited release "+product)

    with tempfile.TemporaryDirectory(prefix="sites-f284-chrome-",
                                      ignore_cleanup_errors=True) as folder:
        profile=Path(folder)/"profile"
        process=subprocess.Popen([
            browser, "--headless=new", "--no-sandbox",
            "--disable-gpu", "--disable-dev-shm-usage",
            "--disable-background-networking", "--disable-extensions",
            "--disable-sync", "--no-first-run","--no-default-browser-check",
            "--remote-debugging-port=0", f"--user-data-dir={profile}",
            "about:blank",
        ],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            port=None
            for _ in range(200):
                if process.poll() is not None:
                    fail("Chrome exited before CDP startup")
                port=read_local_devtools_port(profile/"DevToolsActivePort")
                if port is not None:break
                time.sleep(.1)
            if port is None:fail("Chrome DevTools port unavailable")
            target=None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list",timeout=3) as reply:
                        tabs=json.loads(reply.read())
                    target=next((t for t in tabs if isinstance(t,dict)
                                 and t.get("type")=="page"),None)
                    if target:break
                except (OSError,ValueError):
                    pass
                if process.poll() is not None:
                    fail("Chrome exited before page target")
                time.sleep(.1)
            if target is None:fail("Chrome DevTools page unavailable")
            endpoint=target["webSocketDebuggerUrl"].split(f":{port}",1)[1]
            client=CDP(port,endpoint)
            try:
                client.call("Page.enable")
                client.call("Runtime.enable")
                for product in ("onepage","landing_page"):
                    for device,width,height,mobile in [
                        ("desktop",1365,900,False),
                        ("mobile",390,844,True),
                        ("narrow",320,740,True),
                    ]:
                        client.call("Emulation.setDeviceMetricsOverride",{
                            "width":width,"height":height,
                            "deviceScaleFactor":1,"mobile":mobile,
                        })
                        frame=client.call("Page.navigate",{
                            "url":"about:blank",
                        })["frameId"]
                        client.call("Page.setDocumentContent",{
                            "frameId":frame,"html":pages[product]["html"],
                        })
                        for _ in range(100):
                            if client.evaluate(
                                "document.readyState==='complete' && "
                                "!!document.querySelector('a.skip-to-main')"
                            ):break
                            time.sleep(.05)
                        else:fail(product+" "+device+" not rendered")

                        initial=client.evaluate("""(() => {
                          const skip=document.querySelector('a.skip-to-main');
                          const bounds=skip.getBoundingClientRect();
                          return {
                            width:innerWidth,
                            overflow:document.documentElement.scrollWidth,
                            firstLink:document.querySelector('a[href]')===skip,
                            target:skip.getAttribute('href'),
                            initialBottom:bounds.bottom,
                            noindex:document.querySelector('meta[name="robots"]')?.content,
                            formCount:document.querySelectorAll('form,script,iframe').length,
                            main:document.querySelectorAll('main#principal').length
                          };
                        })()""")
                        if (initial["width"]!=width
                            or initial["overflow"]>width+2
                            or not initial["firstLink"]
                            or initial["target"]!="#principal"
                            or initial["initialBottom"]>1
                            or initial["noindex"]!="noindex, nofollow"
                            or initial["formCount"]!=0
                            or initial["main"]!=1):
                            fail(product+" "+device+" invalid pre-focus link: "+str(initial))

                        client.call("Page.bringToFront")
                        key(client,"Tab","Tab",9)
                        focused=client.evaluate("""(() => {
                          const skip=document.querySelector('a.skip-to-main');
                          const box=skip.getBoundingClientRect();
                          const style=getComputedStyle(skip);
                          return {
                            active:document.activeElement===skip,
                            visible:box.top>=-1 && box.bottom>0,
                            size:box.height>=44 && box.width>=44,
                            foreground:style.color,
                            background:style.backgroundColor,
                          };
                        })()""")
                        if not (focused["active"] and focused["visible"]
                                and focused["size"]):
                            fail(product+" "+device+" first Tab did not expose skip link: "+
                                 str(focused))
                        key(client,"Enter","Enter",13)
                        jumped=client.evaluate("""(() => ({
                          hash:location.hash,
                          exists:!!document.querySelector('main#principal'),
                          stillLocal:location.protocol==='about:'
                        }))()""")
                        if (jumped["hash"]!="#principal"
                            or not jumped["exists"] or not jumped["stillLocal"]):
                            fail(product+" "+device+" Enter did not bypass header: "+
                                 str(jumped))
                        print(
                            f"PASS F1-284 {product}/{device}: {width}x{height}, "
                            "first Tab visible focus + Enter #principal, no overflow"
                        )
            finally:
                client.close()
        finally:
            process.terminate()
            try:process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)


if __name__=="__main__":
    if len(sys.argv)!=2:fail("browser executable required")
    main(sys.argv[1])
