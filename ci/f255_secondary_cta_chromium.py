#!/usr/bin/env python3
"""F1-255: live Chrome geometry/keyboard/200% text checks for 12 CTA variants.

Only private fictitious HTML bytes are loaded via CDP into about:blank.
No browser download, remote site, customer data, or publication operation.
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
SOURCE = ROOT / "apps/sites/storage/app/private/f255-cta-previews"
OUTPUT = ROOT / "apps/sites/storage/app/private/f255-cta-screenshots"
VIEWPORTS = {
    "desktop": (1365, 900, False),
    "mobile": (390, 844, True),
    "narrow": (320, 740, True),
}


def die(message: str) -> None:
    raise RuntimeError("F1-255 " + message)


def main(browser: str) -> None:
    if (
        os.getenv("GITHUB_ACTIONS") != "true"
        or os.getenv("APP_ENV") != "testing"
        or os.getenv("SITES_F255_CTA_SCREENSHOT") != "1"
        or not Path(browser).is_file()
        or not SOURCE.is_dir()
        or SOURCE.is_symlink()
        or OUTPUT.exists()
        or OUTPUT.is_symlink()
    ):
        die("requires a fresh disposable GitHub Actions Chromium lab")

    m = json.loads((SOURCE / "manifest.json").read_text(encoding="utf-8"))
    names = {
        f"{product}-{preset}-{mode}.html"
        for product in ("onepage", "landing_page")
        for preset in ("classic", "modern", "warm")
        for mode in ("off", "on")
    }
    if (
        m.get("schema") != "imagine-sites.synthetic-cta-gallery.v1"
        or m.get("demoOnly") is not True
        or m.get("approvedForClient") is not False
        or m.get("publishable") is not False
        or m.get("hasContactCapture") is not False
        or not isinstance(m.get("files"), dict)
        or set(m["files"]) != names
    ):
        die("manifest differs from the fixed twelve fictional sources")

    pages: dict[str, str] = {}
    for filename in sorted(names):
        file = SOURCE / filename
        if file.is_symlink() or not file.is_file():
            die("unexpected source file")
        raw = file.read_bytes()
        data = m["files"][filename]
        mode = filename.removesuffix(".html").rsplit("-", 1)[1]
        if (
            not isinstance(data, dict)
            or data.get("mode") != mode
            or data.get("publishable") is not False
            or data.get("officialClientAcceptance") is not False
            or data.get("draftState") != "internal_review"
            or type(data.get("bytes")) is not int
            or data["bytes"] != len(raw)
            or hashlib.sha256(raw).hexdigest() != data.get("sha256")
            or b'noindex, nofollow' not in raw
            or raw.count(b'class="btn secondary-cta"') != (1 if mode == "on" else 0)
            or raw.count(b'href="#contato"') != (2 if mode == "on" else 1)
            or any(bad in raw.lower() for bad in (
                b"<script", b"<form", b"https://", b"http://", b"<iframe"
            ))
        ):
            die("unsafe or changed on-disk HTML: " + filename)
        pages[filename] = raw.decode("utf-8")

    OUTPUT.mkdir(mode=0o700)
    evidence = {}
    with tempfile.TemporaryDirectory(
        prefix="sites-f255-chrome-", ignore_cleanup_errors=True
    ) as tmp:
        profile = Path(tmp) / "profile"
        process = subprocess.Popen(
            [
                browser, "--headless=new", "--no-sandbox", "--disable-gpu",
                "--disable-background-networking", "--disable-extensions",
                "--disable-sync", "--no-first-run",
                "--no-default-browser-check", "--remote-debugging-port=0",
                f"--user-data-dir={profile}", "about:blank",
            ],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        try:
            port_file = profile / "DevToolsActivePort"
            port = None
            for _ in range(200):
                if process.poll() is not None:
                    die("browser process exited")
                port = read_local_devtools_port(port_file)
                if port is not None:
                    break
                time.sleep(0.1)
            if port is None:
                die("browser debug port unavailable")
            target = None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list", timeout=3) as r:
                        targets = json.loads(r.read())
                    target = next((p for p in targets if p.get("type") == "page"), None)
                    if target:
                        break
                except (OSError, ValueError):
                    pass
                time.sleep(0.1)
            if not target:
                die("browser target unavailable")
            endpoint = target["webSocketDebuggerUrl"].split(f":{port}", 1)[1]
            chrome = CDP(port, endpoint)
            try:
                chrome.call("Page.enable")
                chrome.call("Runtime.enable")
                for name in sorted(pages):
                    mode = name.removesuffix(".html").rsplit("-", 1)[1]
                    for device, (width, height, mobile) in VIEWPORTS.items():
                        chrome.call("Emulation.setDeviceMetricsOverride", {
                            "width": width, "height": height,
                            "deviceScaleFactor": 1, "mobile": mobile,
                        })
                        frame = chrome.call(
                            "Page.navigate", {"url": "about:blank"}
                        )["frameId"]
                        chrome.call("Page.setDocumentContent", {
                            "frameId": frame, "html": pages[name],
                        })
                        for _ in range(100):
                            if chrome.evaluate(
                                "document.readyState==='complete' && "
                                "document.querySelector('body[data-site-product]')!==null"
                            ):
                                break
                            time.sleep(0.05)
                        else:
                            die("page did not render")
                        dims = chrome.evaluate("""(() => {
                          const root=document.documentElement;
                          const secondary=[...document.querySelectorAll('.secondary-cta')];
                          const hero=document.querySelector('.hero a.btn');
                          const all=[...document.querySelectorAll('a[href]')];
                          const safe=el=>{
                            if(!el)return false;
                            const b=el.getBoundingClientRect();
                            return b.width>=44 && b.height>=44 &&
                                b.left>=-1 && b.right<=innerWidth+2;
                          };
                          return {
                            viewport:innerWidth,
                            overflow:Math.max(root.scrollWidth,document.body.scrollWidth),
                            height:Math.ceil(Math.max(root.scrollHeight,document.body.scrollHeight)),
                            secondaryCount:secondary.length,
                            secondarySafe:secondary.every(safe),
                            secondaryColor:secondary.map(el=>getComputedStyle(el).color),
                            secondaryBackground:secondary.map(el=>getComputedStyle(el).backgroundColor),
                            heroSafe:safe(hero),
                            contactLinks:all.filter(el=>el.getAttribute('href')==='#contato').length,
                            linksLocal:all.every(el=>{
                              const href=el.getAttribute('href');
                              return href.startsWith('#') && !!document.getElementById(href.slice(1));
                            }),
                            h1:document.querySelectorAll('main h1').length,
                            robots:document.querySelector('meta[name="robots"]')?.content,
                            forms:document.querySelectorAll('script,form,iframe').length
                          };
                        })()""")
                        if (
                            dims["viewport"] != width
                            or dims["overflow"] > width + 2
                            or not (height < dims["height"] < 14000)
                            or dims["secondaryCount"] != (1 if mode == "on" else 0)
                            or not dims["secondarySafe"]
                            or (mode == "on" and (
                                dims["secondaryColor"] != ["rgb(255, 255, 255)"]
                                or dims["secondaryBackground"] != ["rgb(23, 62, 105)"]
                            ))
                            or not dims["heroSafe"]
                            or dims["contactLinks"] != (2 if mode == "on" else 1)
                            or not dims["linksLocal"]
                            or dims["h1"] != 1
                            or dims["robots"] != "noindex, nofollow"
                            or dims["forms"] != 0
                        ):
                            die("responsive contact CTA failed: " + name + " " + device)
                        picture=chrome.call("Page.captureScreenshot",{
                            "format":"png", "captureBeyondViewport":True,
                            "fromSurface":True,
                            "clip":{"x":0,"y":0,"width":width,
                                    "height":dims["height"],"scale":1},
                        })
                        png=base64.b64decode(picture["data"], validate=True)
                        if (
                            not png.startswith(b"\x89PNG\r\n\x1a\n")
                            or len(png) < 8000
                            or struct.unpack(">II",png[16:24]) != (width,dims["height"])
                        ):
                            die("full-page screenshot integrity failure")
                        # Functional Enter activation of the secondary or original CTA.
                        expected_link = ".secondary-cta" if mode == "on" else ".hero a.btn"
                        focus = chrome.evaluate(
                            "(() => { const a=document.querySelector("
                            + json.dumps(expected_link)
                            + "); a.focus(); return a.getAttribute('href'); })()"
                        )
                        if focus != "#contato":
                            die("button did not target the fixed contact section")
                        keyboard = {
                            "key":"Enter","code":"Enter",
                            "windowsVirtualKeyCode":13,"nativeVirtualKeyCode":13,
                        }
                        chrome.call("Input.dispatchKeyEvent",{"type":"rawKeyDown",**keyboard})
                        chrome.call("Input.dispatchKeyEvent",{"type":"keyUp",**keyboard})
                        if chrome.evaluate("location.hash") != "#contato":
                            die("keyboard activation did not change the local fragment")
                        zoom=chrome.evaluate("""(() => {
                          const root=document.documentElement;
                          const before=parseFloat(getComputedStyle(root).fontSize);
                          root.style.fontSize='200%';
                          const after=parseFloat(getComputedStyle(root).fontSize);
                          const buttons=[...document.querySelectorAll('.btn')];
                          const ok=buttons.every(el=>{
                            const b=el.getBoundingClientRect();
                            return b.width>=44 && b.height>=44 &&
                                   b.left>=-1 && b.right<=innerWidth+2;
                          });
                          return {
                            before,after,ok,
                            overflow:Math.max(root.scrollWidth,document.body.scrollWidth),
                            height:Math.max(root.scrollHeight,document.body.scrollHeight)
                          };
                        })()""")
                        if (
                            zoom["after"] < zoom["before"] * 1.9
                            or zoom["overflow"] > width + 2
                            or zoom["height"] > 20000
                            or not zoom["ok"]
                        ):
                            die("200 percent text reflow failed: "+name+" "+device)
                        filename=name.removesuffix(".html")+"-"+device+".png"
                        file=OUTPUT / filename
                        file.write_bytes(png)
                        file.chmod(0o600)
                        evidence[filename]={
                            "page":name, "device":device, "mode":mode,
                            "fullPage":True, "width":width,
                            "height":dims["height"],
                            "sha256":hashlib.sha256(png).hexdigest(),
                            "keyboardLocalCtaVerified":True,
                            "textReflow200PercentVerified":True,
                            "noHorizontalOverflow":True,
                        }
                        print("PASS F1-255",filename,width,dims["height"])
            finally:
                chrome.close()
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)

    if len(evidence) != 36 or len({x["sha256"] for x in evidence.values()}) != 36:
        die("expected thirty-six unique viewport screenshots")
    report={
        "schema":"imagine-sites.synthetic-cta-screenshots.v1",
        "sourceManifestSha256":hashlib.sha256(
            (SOURCE / "manifest.json").read_bytes()
        ).hexdigest(),
        "demoOnly":True, "containsClientData":False,
        "publishable":False,
        "screenshots":evidence,
    }
    (OUTPUT/"screenshot-manifest.json").write_text(
        json.dumps(report,indent=2,ensure_ascii=False)+"\n",encoding="utf-8"
    )
    if len(list(OUTPUT.iterdir())) != 37:
        die("unexpected screenshot gallery inventory")
    print("PASS F1-255: 36 real Chrome full-page previews, touch/keyboard/reflow 200%; no deploy.")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: ci/f255_secondary_cta_chromium.py <preinstalled-chrome>")
    main(sys.argv[1])
