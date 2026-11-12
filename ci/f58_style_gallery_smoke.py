#!/usr/bin/env python3
"""F1-58: inspect twelve disposable browser screenshots, not public artifacts."""
import hashlib
import json
from pathlib import Path
import re
import struct
import sys

if len(sys.argv)!=3:
    raise SystemExit("usage: f58_style_gallery_smoke.py <gallery-dir> <screenshots-dir>")
root=Path(sys.argv[1]).resolve(strict=True)
shots=Path(sys.argv[2]).resolve(strict=True)
m=json.loads((root/"gallery-manifest.json").read_text("utf-8"))
assert m["schemaVersion"]=="sites.synthetic.style-gallery.v1"
assert m["syntheticOnly"] is True and m["publishable"] is False
assert m["officialClientAcceptance"] is False
expected={f"{p}-{d}-{s}.html" for p in ("onepage","landing_page")
          for d in ("desktop","mobile") for s in ("classic","modern","warm")}
assert {p["filename"] for p in m["pages"]}==expected
assert len(m["pages"])==12
distinct=set()
by_theme={}
for p in m["pages"]:
    filename=p["filename"]
    body=(root/filename).read_bytes()
    assert len(body)==p["bytes"]
    assert hashlib.sha256(body).hexdigest()==p["sha256"]
    html=body.decode("utf-8")
    assert '<meta http-equiv="Content-Security-Policy"' in html
    assert 'name="robots" content="noindex, nofollow"' in html
    assert 'sandbox=""' in html and 'tabindex="0"' in html
    assert "Aceite oficial pendente no Portal" in html
    assert not re.search(r'<(?:script|form)\b|https?://|javascript:|@import',html,re.I)
    assert 'href="/_lab/sites/v1/review?' not in html
    assert sum(html.count(f'href="{product}-') for product in ("onepage","landing_page"))==7
    for product in ("onepage","landing_page"):
        assert f'href="{product}-{p["device"]}-{p["preset"]}.html"' in html
    for device in ("desktop","mobile"):
        assert f'href="{p["product"]}-{device}-{p["preset"]}.html"' in html
    for preset in ("classic","modern","warm"):
        assert f'href="{p["product"]}-{p["device"]}-{preset}.html"' in html
    assert f'release-f38-{p["product"]}'+(
        "" if p["preset"]=="classic" else "-"+p["preset"]) in html
    inner=re.search(r'\bsrcdoc="([^"]+)"',html,re.S)
    assert inner,filename
    import html as html_lib
    source=html_lib.unescape(inner.group(1))
    assert hashlib.sha256(source.encode()).hexdigest()==p["artifactDigest"]
    if p["preset"]=="classic":
        assert "visual-preset:" not in source
    else:
        assert "visual-preset: "+p["preset"] in source
    by_theme[(p["product"],p["preset"])]=p["artifactDigest"]

    data=(shots/filename.replace(".html",".png")).read_bytes()
    assert data[:8]==b"\x89PNG\r\n\x1a\n"
    assert len(data)>8000
    width,height=struct.unpack(">II",data[16:24])
    if p["device"]=="desktop":assert width>=1000
    else:assert 360<=width<=800
    assert height>=600
    digest=hashlib.sha256(data).hexdigest()
    distinct.add(digest)
    print("PASS Chrome private",filename,f"{width}x{height}",digest[:10])
for prod in ("onepage","landing_page"):
    assert len({by_theme[prod,t] for t in ("classic","modern","warm")})==3
assert len(distinct)==12,"Unexpected identical screenshots; theme/device routing may be broken"
print("PASS F1-58 all twelve offline themed review snapshots, verified exact HTML and no upload")
