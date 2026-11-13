#!/usr/bin/env python3
"""F1-291: screenshot staff-only Imagine design in offline Chromium.

Inputs come solely from in-process Laravel tests with synthetic data.
No public HTTP server, remote URL, downloads, client content or deploy.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
from pathlib import Path
import struct
import subprocess
import sys
import tempfile
import time
from urllib.request import urlopen

from f43_cdp import CDP, read_local_devtools_port

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "apps/sites/storage/app/private/f291-imagine-shell"
OUTPUT = ROOT / "apps/sites/storage/app/private/f291-imagine-screenshots"
SIZES = {
    "desktop": (1365, 900, False),
    "tablet": (820, 900, False),
    "mobile": (390, 844, True),
    "narrow": (320, 740, True),
}

def fail(message: str) -> None:
    raise RuntimeError("F1-291 " + message)

def main(browser: str) -> None:
    if (os.environ.get("GITHUB_ACTIONS") != "true"
        or os.environ.get("APP_ENV") != "testing"
        or os.environ.get("SITES_F291_BROWSER_EXPORT") != "1"
        or not Path(browser).is_file()
        or not SOURCE.is_dir()
        or SOURCE.is_symlink()
        or OUTPUT.exists()
        or OUTPUT.is_symlink()):
        fail("requires an isolated CI-only private screenshot lab")

    manifest=json.loads((SOURCE/"manifest.json").read_text())
    if (manifest.get("schema")!="sites.f291.admin-shell-screenshots.v1"
        or manifest.get("syntheticOnly") is not True
        or manifest.get("publishable") is not False
        or manifest.get("officialClientAcceptance") is not False
        or manifest.get("hasClientData") is not False
        or set(manifest.get("files",{}))!={x+".html" for x in SIZES}):
        fail("unverified or non-synthetic input manifest")

    pages={}
    for label in SIZES:
        name=label+".html"
        path=SOURCE/name
        if path.is_symlink() or not path.is_file():
            fail("symlink/missing source refused")
        data=path.read_bytes()
        expected=manifest["files"][name]
        if (len(data)!=expected["bytes"]
            or hashlib.sha256(data).hexdigest()!=expected["sha256"]
            or b'data:image/png;base64,' not in data
            or b'noindex, nofollow' not in data
            or b'sandbox=""' not in data
            or b'<script' in data.lower()
            or b'<form' in data.lower()):
            fail("fixture integrity or privacy failed")
        pages[label]=data.decode("utf-8")

    OUTPUT.mkdir(mode=0o700)
    results={}
    with tempfile.TemporaryDirectory(prefix="sites-f291-chrome-", ignore_cleanup_errors=True) as tmp:
        profile=Path(tmp)/"profile"
        proc=subprocess.Popen([
            browser,"--headless=new","--no-sandbox","--disable-gpu",
            "--disable-dev-shm-usage","--disable-background-networking",
            "--disable-extensions","--disable-sync","--no-first-run",
            "--no-default-browser-check","--remote-debugging-port=0",
            f"--user-data-dir={profile}","about:blank",
        ],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            port=None
            for _ in range(200):
                if proc.poll() is not None:
                    fail("Chrome exited before debug port ready")
                port=read_local_devtools_port(profile/"DevToolsActivePort")
                if port is not None:
                    break
                time.sleep(.1)
            if port is None:
                fail("Chrome debug port unavailable")
            target=None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list",timeout=3) as resp:
                        targets=json.loads(resp.read())
                    target=next((t for t in targets if t.get("type")=="page"),None)
                    if target:
                        break
                except (OSError,ValueError):
                    pass
                time.sleep(.1)
            if not target:
                fail("No isolated browser page")
            endpoint=target["webSocketDebuggerUrl"].split(f":{port}",1)[1]
            c=CDP(port,endpoint)
            try:
                c.call("Page.enable")
                c.call("Runtime.enable")
                for label,(width,height,mobile) in SIZES.items():
                    c.call("Emulation.setDeviceMetricsOverride",{
                        "width":width,"height":height,
                        "deviceScaleFactor":1,"mobile":mobile})
                    frame=c.call("Page.navigate",{"url":"about:blank"})["frameId"]
                    c.call("Page.setDocumentContent",{
                        "frameId":frame,"html":pages[label]})
                    state=None
                    for _ in range(100):
                        state=c.evaluate("""(() => {
                          const logo=document.querySelector('header img.imagine-original-logo');
                          const tab=document.querySelector('.tabs a[aria-current]');
                          const iframe=document.querySelector('iframe[sandbox]');
                          return {
                            ready:document.readyState==='complete',
                            width:innerWidth,
                            docWidth:document.documentElement.scrollWidth,
                            bodyWidth:document.body.scrollWidth,
                            logoReady:!!(logo && logo.complete && logo.naturalWidth===200),
                            logoAlt:logo?.alt,
                            token:getComputedStyle(document.documentElement).getPropertyValue('--imagine-orange').trim(),
                            header:getComputedStyle(document.querySelector('header')).backgroundColor,
                            body:getComputedStyle(document.body).backgroundColor,
                            activeTab:tab?getComputedStyle(tab).backgroundColor:null,
                            frameIsolated:iframe?.getAttribute('sandbox')==='',
                            clientIsolated:!iframe?.getAttribute('srcdoc')?.includes('--imagine-orange'),
                            robots:document.querySelector('meta[name="robots"]')?.content,
                            sideCards:document.querySelectorAll('.sidebar .card').length,
                            activeControl:!!tab,
                            forbidden:document.querySelectorAll('script,form,button,input').length
                          };
                        })()""")
                        if state.get("ready") and state.get("logoReady") and state.get("activeControl"):
                            break
                        time.sleep(.05)
                    if (state is None
                        or state["width"]!=width
                        or state["docWidth"]>width+2
                        or state["bodyWidth"]>width+2
                        or not state["logoReady"]
                        or state["logoAlt"]!="Imagine"
                        or state["token"]!="#f9941d"
                        or state["header"]!="rgb(255, 255, 255)"
                        or state["body"]!="rgb(247, 247, 248)"
                        or state["activeTab"]!="rgb(255, 241, 224)"
                        or not state["frameIsolated"]
                        or not state["clientIsolated"]
                        or state["robots"]!="noindex, nofollow"
                        or state["forbidden"]!=0
                        or state["sideCards"]<2):
                        fail("real-browser visual/security invariant failed: "+label+" "+str(state))
                    shot=c.call("Page.captureScreenshot",{
                        "format":"png","captureBeyondViewport":False,
                        "fromSurface":True})
                    data=base64.b64decode(shot["data"])
                    if len(data)<5000 or not data.startswith(b"\x89PNG\r\n\x1a\n"):
                        fail("missing/empty browser screenshot: "+label)
                    actual=struct.unpack(">II",data[16:24])
                    if actual!=(width,height):
                        fail("unexpected captured browser size: "+label+" "+str(actual))
                    (OUTPUT/(label+".png")).write_bytes(data)
                    results[label]={
                        "width":width,"height":height,
                        "pngSha256":hashlib.sha256(data).hexdigest(),
                        "previewDigest":manifest["files"][label+".html"]["sha256"],
                        "safe":True,
                    }
            finally:
                c.close()
        finally:
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait(timeout=5)
    if len(results)!=4 or len({v["pngSha256"] for v in results.values()})!=4:
        fail("four independent responsive PNGs required")
    report={
        "schema":"sites.f291.admin-shell-screenshot-report.v1",
        "syntheticOnly":True,
        "publishable":False,
        "productionReady":False,
        "officialClientAcceptance":False,
        "containsClientData":False,
        "originalLogoBlob":manifest["originalLogoBlob"],
        "snapshots":results,
    }
    (OUTPUT/"report.json").write_text(
        json.dumps(report,indent=2,sort_keys=True)+"\n",encoding="utf-8")
    print("PASS F1-291 four offline Chrome screenshots; logo original, noindex, viewport, sandbox verified")

if __name__=="__main__":
    if len(sys.argv)!=2:
        fail("Usage: f291_imagine_shell_browser.py /path/to/preinstalled/chrome")
    main(sys.argv[1])
