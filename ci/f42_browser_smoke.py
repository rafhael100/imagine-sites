#!/usr/bin/env python3
"""F1-42 synthetic browser screenshot smoke. No data or network access."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re
import struct
import sys

if len(sys.argv) != 3:
    raise SystemExit("Usage: f42_browser_smoke.py <private gallery> <private screenshots>")
gallery = Path(sys.argv[1]).resolve()
screenshots = Path(sys.argv[2]).resolve()
manifest = json.loads((gallery / "gallery-manifest.json").read_text(encoding="utf-8"))
assert manifest["schemaVersion"] == "sites.synthetic.visual-gallery.v1"
assert manifest["syntheticOnly"] is True
assert manifest["publishable"] is False
assert manifest["officialClientAcceptance"] is False

expected = {
    f"{product}-{device}.html"
    for product in ("onepage", "landing_page")
    for device in ("desktop", "mobile")
}
assert {entry["filename"] for entry in manifest["pages"]} == expected

images = {}
for entry in manifest["pages"]:
    filename = entry["filename"]
    html_bytes = (gallery / filename).read_bytes()
    html = html_bytes.decode("utf-8")
    assert len(html_bytes) == entry["bytes"], filename
    assert hashlib.sha256(html_bytes).hexdigest() == entry["sha256"], filename
    assert '<iframe ' in html and 'sandbox=""' in html, filename
    assert 'http-equiv="Content-Security-Policy"' in html, filename
    assert 'name="robots" content="noindex, nofollow"' in html, filename
    assert not re.search(r'(?:https?://|javascript:|<script\b|<form\b)', html, re.I), filename

    screenshot = screenshots / filename.replace(".html", ".png")
    data = screenshot.read_bytes()
    assert data.startswith(b"\x89PNG\r\n\x1a\n"), screenshot
    assert len(data) > 8000, f"Empty or suspiciously tiny browser screenshot: {screenshot}"
    width, height = struct.unpack(">II", data[16:24])
    assert height >= 600, (filename, width, height)
    if "-desktop" in filename:
        assert width >= 1000, (filename, width, height)
    else:
        assert 360 <= width <= 800, (filename, width, height)
    images[filename] = hashlib.sha256(data).hexdigest()
    print(f"PASS browser screenshot {filename}: {width}x{height}, {len(data)} bytes")

assert len(set(images.values())) == 4, "Browser output must vary across the 4 previews"
print("PASS F1-42 four distinct offline Chromium screenshots, no upload, no site publication")
