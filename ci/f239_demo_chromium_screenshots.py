#!/usr/bin/env python3
"""F1-239/F1-244: full-page Chrome screenshots at three responsive widths.

No external pages, browser downloads, customer data, uploaded source snippets,
dynamic URLs or public hosting. Chrome receives the exact audited HTML bytes
directly via CDP Page.setDocumentContent on about:blank.
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
SOURCE = ROOT / "apps/sites/storage/app/private/f238-demo-previews"
OUTPUT = ROOT / "apps/sites/storage/app/private/f239-demo-screenshots"
VIEWPORTS = {
    "desktop": (1365, 900, False),
    "mobile": (390, 844, True),
    "narrow": (320, 740, True),
}


def fail(msg: str) -> None:
    raise RuntimeError("F1-239 " + msg)


def main(browser: str) -> None:
    if (
        os.getenv("GITHUB_ACTIONS") != "true"
        or os.getenv("APP_ENV") != "testing"
        or os.getenv("SITES_F239_DEMO_SCREENSHOT") != "1"
        or not Path(browser).is_file()
        or not SOURCE.is_dir()
        or SOURCE.is_symlink()
        or OUTPUT.exists()
        or OUTPUT.is_symlink()
    ):
        fail("needs a fresh isolated GitHub Actions fictional screenshot lab")

    m = json.loads((SOURCE / "manifest.json").read_text(encoding="utf-8"))
    expected = {
        f"{product}-{preset}.html"
        for product in ("onepage", "landing_page")
        for preset in ("classic", "modern", "warm")
    }
    if (
        m["schema"] != "imagine-sites.synthetic-demo-gallery.v1"
        or m["demoOnly"] is not True
        or m["approvedForClient"] is not False
        or m["publishable"] is not False
        or m["hasContactCapture"] is not False
        or set(m["files"]) != expected
    ):
        fail("input is not the exact six fixed synthetic outputs")

    html = {}
    for name in sorted(expected):
        f = SOURCE / name
        if f.is_symlink() or not f.is_file():
            fail("unsafe input path")
        raw = f.read_bytes()
        info = m["files"][name]
        if (
            len(raw) != info["bytes"]
            or hashlib.sha256(raw).hexdigest() != info["sha256"]
            or b"noindex, nofollow" not in raw
            or any(s in raw.lower() for s in (b"<form", b"<script", b"https://", b"http://"))
        ):
            fail("preview bytes failed synthetic output audit")
        html[name] = raw.decode("utf-8")

    OUTPUT.mkdir(mode=0o700)
    captured = {}
    # Chrome may still flush an ephemeral profile from a child after its main
    # process exits. Do not invalidate VERIFIED screenshots over that CI-only
    # cleanup race; GitHub removes its runner and its browser profile.
    with tempfile.TemporaryDirectory(prefix="sites-f239-chrome-", ignore_cleanup_errors=True) as tmp:
        profile = Path(tmp) / "profile"
        chrome = subprocess.Popen(
            [
                browser,
                "--headless=new",
                "--no-sandbox",
                "--disable-gpu",
                "--disable-background-networking",
                "--disable-extensions",
                "--disable-sync",
                "--no-first-run",
                "--no-default-browser-check",
                "--remote-debugging-port=0",
                f"--user-data-dir={profile}",
                "about:blank",
            ],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            port_file = profile / "DevToolsActivePort"
            port = None
            for _ in range(200):
                if chrome.poll() is not None:
                    fail("Chromium process exited before CDP was ready")
                port = read_local_devtools_port(port_file)
                if port is not None:
                    break
                time.sleep(0.1)
            if port is None:
                fail("Chromium DevTools port unavailable")
            target = None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list", timeout=3) as r:
                        tabs = json.loads(r.read())
                    target = next(
                        (t for t in tabs if isinstance(t, dict) and t.get("type") == "page"),
                        None,
                    )
                    if target is not None:
                        break
                except (OSError, ValueError):
                    pass
                if chrome.poll() is not None:
                    fail("Chromium stopped before CDP endpoint came up")
                time.sleep(0.1)
            if target is None:
                fail("no local Chromium page target")
            endpoint = target["webSocketDebuggerUrl"].split(f":{port}", 1)[1]
            c = CDP(port, endpoint)
            try:
                c.call("Page.enable")
                c.call("Runtime.enable")
                for filename in sorted(html):
                    for device, (width, height, mobile) in VIEWPORTS.items():
                        c.call("Emulation.setDeviceMetricsOverride", {
                            "width": width, "height": height,
                            "deviceScaleFactor": 1, "mobile": mobile,
                        })
                        frame = c.call("Page.navigate", {"url": "about:blank"})["frameId"]
                        c.call("Page.setDocumentContent", {
                            "frameId": frame, "html": html[filename],
                        })
                        for _ in range(100):
                            ready = c.evaluate(
                                "document.readyState==='complete' && "
                                "document.querySelector('body[data-site-product]')!==null"
                            )
                            if ready:
                                break
                            time.sleep(0.05)
                        else:
                            fail("fixture did not render completely")
                        dims = c.evaluate("""(() => {
                          const links=[...document.querySelectorAll('a[href]')];
                          const cta=document.querySelector('.hero a.btn[href="#contato"]');
                          const bounds=cta?.getBoundingClientRect();
                          return {
                            width:innerWidth,
                            scroll:document.documentElement.scrollWidth,
                            body:document.body.scrollWidth,
                            fullHeight:Math.ceil(Math.max(
                              document.documentElement.scrollHeight,
                              document.body.scrollHeight
                            )),
                            heading:document.querySelectorAll('main h1').length,
                            form:document.querySelectorAll('script,form,iframe').length,
                            robots:document.querySelector('meta[name="robots"]')?.content,
                            anchorsLocal:links.every(a=>{
                              const href=a.getAttribute('href');
                              return href.startsWith('#') && !!document.getElementById(href.slice(1));
                            }),
                            linksNamed:links.length>0 && links.every(a=>a.textContent.trim().length>0),
                            navTargetCount:document.querySelectorAll('nav a').length,
                            navTargetsSafe:[...document.querySelectorAll('nav a')].every(a=>{
                              const rect=a.getBoundingClientRect();
                              return rect.height >= 44 && rect.width >= 24 &&
                                rect.left >= -1 && rect.right <= innerWidth+2;
                            }),
                            ctaHeight:bounds?.height||0,
                            ctaWidth:bounds?.width||0,
                            ctaLeft:bounds?.left||0,
                            ctaRight:bounds?.right||0,
                            richSections:['solucoes','etapas','perguntas'].every(
                              id=>document.getElementById(id)!==null
                            )
                          };
                        })()""")
                        if (
                            dims["width"] != width
                            or dims["scroll"] > width + 2
                            or dims["body"] > width + 2
                            or dims["heading"] != 1
                            or dims["form"] != 0
                            or dims["robots"] != "noindex, nofollow"
                            or not dims["anchorsLocal"]
                            or not dims["linksNamed"]
                            or not dims["navTargetsSafe"]
                            or dims["navTargetCount"] != (
                                5 if filename.startswith("onepage") else 0
                            )
                            or not dims["richSections"]
                            or dims["ctaHeight"] < 44
                            or dims["ctaWidth"] < 44
                            or dims["ctaLeft"] < -1
                            or dims["ctaRight"] > width + 2
                            or dims["fullHeight"] <= height
                            or dims["fullHeight"] > 12000
                        ):
                            fail("real Chromium full-page accessibility/viewport check failed: "
                                 + filename + " " + device)
                        # A screenshot of the visible viewport cannot show
                        # the optional highlights, process and FAQ underneath.
                        # Capture all verified content with a fixed, bounded
                        # CDP clip. No screenshot of a user-supplied URL.
                        page_height = dims["fullHeight"]
                        shot = c.call("Page.captureScreenshot", {
                            "format": "png", "captureBeyondViewport": True,
                            "fromSurface": True,
                            "clip": {
                                "x": 0, "y": 0, "width": width,
                                "height": page_height, "scale": 1,
                            },
                        })
                        content = base64.b64decode(shot["data"], validate=True)
                        if (
                            not content.startswith(b"\x89PNG\r\n\x1a\n")
                            or len(content) < 8000
                            or struct.unpack(">II", content[16:24]) != (width, page_height)
                        ):
                            fail("incomplete or unexpected full-page PNG dimensions")
                        # Test actual Enter activation of the first local link,
                        # not just whether an href string exists.
                        first_link = c.evaluate("""(() => {
                          const first=document.querySelector('a[href]');
                          if (!first) return null;
                          first.focus();
                          return first.getAttribute('href');
                        })()""")
                        if (not isinstance(first_link,str) or
                            not first_link.startswith("#")):
                            fail("missing keyboard-first local link")
                        enter = {
                            "key": "Enter", "code": "Enter",
                            "windowsVirtualKeyCode": 13, "nativeVirtualKeyCode": 13,
                        }
                        c.call("Input.dispatchKeyEvent", {"type": "rawKeyDown", **enter})
                        c.call("Input.dispatchKeyEvent", {"type": "keyUp", **enter})
                        if c.evaluate("location.hash") != first_link:
                            fail("first link cannot be activated using Enter")
                        # F1-251: an authentic browser reflow check with
                        # root text at 200%; no new screenshot is exported.
                        # The generated static page remains unchanged.
                        zoom = c.evaluate("""(() => {
                          const root=document.documentElement;
                          const base=parseFloat(getComputedStyle(root).fontSize);
                          root.style.fontSize='200%';
                          const increased=parseFloat(getComputedStyle(root).fontSize);
                          const links=[...document.querySelectorAll('nav a')];
                          const btn=document.querySelector('.hero a.btn');
                          const cta=btn?.getBoundingClientRect();
                          return {
                            before:base,after:increased,
                            width:innerWidth,
                            scroll:Math.max(root.scrollWidth,document.body.scrollWidth),
                            height:Math.max(root.scrollHeight,document.body.scrollHeight),
                            navSafe:links.every(el=>{
                              const r=el.getBoundingClientRect();
                              return r.width>=24 && r.height>=44 &&
                                r.left>=-1 && r.right<=innerWidth+2;
                            }),
                            ctaSafe:cta && cta.height>=44 && cta.width>=44 &&
                              cta.left>=-1 && cta.right<=innerWidth+2,
                            headings:[...document.querySelectorAll('h1,h2,h3')].every(
                              el=>getComputedStyle(el).display!=='none'
                            ),
                            pages:document.querySelectorAll('main').length
                          };
                        })()""")
                        if (
                            not isinstance(zoom,dict)
                            or zoom["after"] < zoom["before"]*1.9
                            or zoom["width"] != width
                            or zoom["scroll"] > width+2
                            or zoom["height"] > 20000
                            or not zoom["navSafe"]
                            or not zoom["ctaSafe"]
                            or not zoom["headings"]
                            or zoom["pages"] != 1
                        ):
                            fail("200 percent text reflow/CTA/nav failed: "
                                 + filename + " " + device)
                        dest_name = filename.replace(".html", f"-{device}-full.png")
                        dest = OUTPUT / dest_name
                        if dest.exists() or dest.is_symlink():
                            fail("duplicate or linked screenshot target")
                        dest.write_bytes(content)
                        dest.chmod(0o600)
                        captured[dest_name] = {
                            "page": filename,
                            "viewport": device,
                            "width": width,
                            "height": page_height,
                            "viewportHeight": height,
                            "fullPage": True,
        "textReflow200PercentVerified": True,
                            "keyboardLinkActivated": True,
                            "navTouchTargetsVerified": True,
                            "textReflow200PercentVerified": True,
                            "sha256": hashlib.sha256(content).hexdigest(),
                            "bytes": len(content),
                        }
                        print("PASS F1-244", dest_name, f"{width}x{page_height}",
                              "local links, keyboard, full-page content")
            finally:
                c.close()
        finally:
            chrome.terminate()
            try:
                chrome.wait(timeout=5)
            except subprocess.TimeoutExpired:
                chrome.kill()
                chrome.wait(timeout=5)

    if len(captured) != 18 or len({v["sha256"] for v in captured.values()}) != 18:
        fail("expected 18 distinct full-page real Chrome PNG captures")
    output_manifest = {
        "schema": "imagine-sites.synthetic-demo-screenshots.v2",
        "fullPage": True,
        "viewports": ["desktop", "mobile", "narrow"],
        "sourceManifestSha256": hashlib.sha256(
            (SOURCE / "manifest.json").read_bytes()
        ).hexdigest(),
        "demoOnly": True,
        "publishable": False,
        "containsClientData": False,
        "screenshots": captured,
    }
    (OUTPUT / "screenshot-manifest.json").write_text(
        json.dumps(output_manifest, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    if len(list(OUTPUT.iterdir())) != 19:
        fail("unexpected screenshot artifact inventory")
    print("PASS F1-244: 18 full-page responsive PNGs at 1365/390/320px, local keyboard links; no network/deploy")
    print("PASS F1-251: 18 real Chromium text reflow checks at 200%, no overflow and accessible touch targets")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: ci/f239_demo_chromium_screenshots.py <preinstalled-browser>")
    main(sys.argv[1])
