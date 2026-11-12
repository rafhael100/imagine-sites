#!/usr/bin/env python3
"""F1-239: real local Chrome screenshots of six fixed F1-238 previews.

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
    with tempfile.TemporaryDirectory(prefix="sites-f239-chrome-") as tmp:
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
                with urlopen(f"http://127.0.0.1:{port}/json/list", timeout=3) as r:
                    tabs = json.loads(r.read())
                target = next(
                    (t for t in tabs if isinstance(t, dict) and t.get("type") == "page"),
                    None,
                )
                if target is not None:
                    break
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
                        dims = c.evaluate("""(() => ({
                          width:innerWidth,
                          scroll:document.documentElement.scrollWidth,
                          body:document.body.scrollWidth,
                          heading:document.querySelectorAll('main h1').length,
                          form:document.querySelectorAll('script,form,iframe').length,
                          robots:document.querySelector('meta[name="robots"]')?.content
                        }))()""")
                        if (
                            dims["width"] != width
                            or dims["scroll"] > width + 2
                            or dims["body"] > width + 2
                            or dims["heading"] != 1
                            or dims["form"] != 0
                            or dims["robots"] != "noindex, nofollow"
                        ):
                            fail("real Chromium page overflow or active content")
                        shot = c.call("Page.captureScreenshot", {
                            "format": "png", "captureBeyondViewport": False,
                            "fromSurface": True,
                        })
                        content = base64.b64decode(shot["data"], validate=True)
                        if (
                            not content.startswith(b"\x89PNG\r\n\x1a\n")
                            or len(content) < 8000
                            or struct.unpack(">II", content[16:24]) != (width, height)
                        ):
                            fail("incomplete or unexpected screenshot dimensions")
                        dest_name = filename.replace(".html", f"-{device}.png")
                        dest = OUTPUT / dest_name
                        if dest.exists() or dest.is_symlink():
                            fail("duplicate or linked screenshot target")
                        dest.write_bytes(content)
                        dest.chmod(0o600)
                        captured[dest_name] = {
                            "page": filename,
                            "viewport": device,
                            "width": width,
                            "height": height,
                            "sha256": hashlib.sha256(content).hexdigest(),
                            "bytes": len(content),
                        }
                        print("PASS F1-239", dest_name, f"{width}x{height}")
            finally:
                c.close()
        finally:
            chrome.terminate()
            try:
                chrome.wait(timeout=5)
            except subprocess.TimeoutExpired:
                chrome.kill()
                chrome.wait(timeout=5)

    if len(captured) != 12 or len({v["sha256"] for v in captured.values()}) != 12:
        fail("expected 12 distinct real Chrome PNG captures")
    output_manifest = {
        "schema": "imagine-sites.synthetic-demo-screenshots.v1",
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
    if len(list(OUTPUT.iterdir())) != 13:
        fail("unexpected screenshot artifact inventory")
    print("PASS F1-239: 12 offline responsive PNGs, signed by hashes only; no network/deploy")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: ci/f239_demo_chromium_screenshots.py <preinstalled-browser>")
    main(sys.argv[1])
