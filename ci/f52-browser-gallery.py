#!/usr/bin/env python3
"""F1-52: check real Chromium PNGs from six synthetic static style variants."""
import hashlib
import json
from pathlib import Path
import struct
import sys

if len(sys.argv)!=3:raise SystemExit("usage: f52-browser-gallery.py <private-gallery> <png-dir>")
root=Path(sys.argv[1]).resolve(strict=True)
pngs=Path(sys.argv[2]).resolve(strict=True)
m=json.loads((root/"gallery-manifest.json").read_text(encoding="utf-8"))
assert m["schemaVersion"]=="sites.synthetic.styles.v1"
assert m["syntheticOnly"] is True
assert m["publishable"] is False
assert m["officialClientAcceptance"] is False
expected={f"{p}-{s}.html" for p in ("onepage","landing_page") for s in ("classic","modern","warm")}
assert {x["filename"] for x in m["artifacts"]}==expected
seen=set()
for info in m["artifacts"]:
    filename=info["filename"]
    content=(root/filename).read_bytes()
    assert len(content)==info["bytes"]
    assert hashlib.sha256(content).hexdigest()==info["sha256"]
    html=content.decode("utf-8")
    assert "noindex, nofollow" in html
    assert '<meta http-equiv="Content-Security-Policy"' in html
    assert f'data-site-product="{("onepage" if info["product"]=="onepage" else "landing-page")}"' in html
    assert not any(v in html.lower() for v in ("<script","<form","https://","http://","@import","url("))
    if info["preset"]=="classic":assert "visual-preset:" not in html
    else:assert "visual-preset: "+info["preset"] in html

    img=(pngs/filename.replace(".html",".png")).read_bytes()
    assert img.startswith(b"\x89PNG\r\n\x1a\n")
    assert len(img)>8000
    width,height=struct.unpack(">II",img[16:24])
    assert width>=1000 and height>=600,(filename,width,height)
    signature=hashlib.sha256(img).hexdigest()
    assert signature not in seen,"Different themes must create distinct Chromium screenshots"
    seen.add(signature)
    print("PASS Chromium",filename, str(width)+"x"+str(height), "digest",signature[:10])
assert len(seen)==6
print("PASS F1-52 all six private visual combinations; no screenshot uploads or public hosting")
