#!/usr/bin/env python3
"""F1-192: verify actual Chromium colors/contrast on 18 inert local pages.

Only ephemeral CDP loopback and content-addressed fictional HTML.
No HTTP server, external service, screenshot upload or publication.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
from urllib.request import urlopen

from f43_cdp import CDP, read_local_devtools_port
from f44_contrast import ratio

INSPECT = r"""(() => {
  const hero=document.querySelector('.hero');
  const eyebrow=hero?.querySelector('.eyebrow');
  const btn=hero?.querySelector('.btn');
  const anchors=[...document.querySelectorAll('a[href]')];
  const g=element=>getComputedStyle(element);
  return {
    width:innerWidth,
    scroll:document.documentElement.scrollWidth,
    bodyScroll:document.body.scrollWidth,
    viewport:!!document.querySelector('meta[name="viewport"]'),
    noindex:!!document.querySelector('meta[name="robots"][content="noindex, nofollow"]'),
    csp:!!document.querySelector('meta[http-equiv="Content-Security-Policy"]'),
    lang:document.documentElement.lang,
    main:document.querySelectorAll('main').length,
    h1:document.querySelectorAll('h1').length,
    heroGradient:hero?g(hero).backgroundImage:null,
    heroText:hero?g(hero).color:null,
    eyebrowText:eyebrow?g(eyebrow).color:null,
    ctaBackground:btn?g(btn).backgroundColor:null,
    ctaText:btn?g(btn).color:null,
    heroInside:!!hero && hero.getBoundingClientRect().width<=innerWidth+2,
    buttonInside:!!btn && btn.getBoundingClientRect().width>30 &&
       btn.getBoundingClientRect().right<=innerWidth+2,
    anchorsLocal:anchors.every(a=>a.getAttribute('href').startsWith('#') &&
       !!document.getElementById(a.getAttribute('href').slice(1))),
    sections:['highlights','process','faq'].map(
       n=>document.querySelectorAll('section.'+n).length),
    controls:document.querySelectorAll(
       'script,form,input,button,iframe,object,embed').length
  };
})()"""


def rgb(hex_color: str) -> str:
    assert re.fullmatch(r"#[0-9a-f]{6}",hex_color)
    return "rgb("+", ".join(str(int(hex_color[i:i+2],16))
                             for i in (1,3,5))+")"


def load(root: Path) -> list[dict]:
    manifest=json.loads((root/"gallery-manifest.json").read_text("utf-8"))
    assert manifest["schemaVersion"]=="sites.synthetic.brand-palette-chrome.v1"
    assert manifest["syntheticOnly"] and not manifest["publishable"]
    assert manifest["officialClientAcceptance"] is False
    expected={f"{p}-{s}-{c}.html"
        for p in ("onepage","landing_page")
        for s in ("classic","modern","warm")
        for c in ("ocean","plum","forest")}
    records=manifest["artifacts"]
    assert len(records)==18
    assert {a["filename"] for a in records}==expected
    return records


def inspect(root: Path, binary: str) -> None:
    records=load(root)
    with tempfile.TemporaryDirectory(prefix="sites-f192-browser-",
                                     ignore_cleanup_errors=True) as temp:
        profile=Path(temp)/"profile"
        chrome=subprocess.Popen(
            [binary,"--headless=new","--no-sandbox","--disable-gpu",
             "--disable-background-networking","--disable-extensions",
             "--disable-sync","--no-first-run","--no-default-browser-check",
             "--remote-debugging-port=0",f"--user-data-dir={profile}","about:blank"],
            stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL
        )
        try:
            port=None
            for _ in range(200):
                if chrome.poll() is not None:
                    raise RuntimeError("Chromium exited unexpectedly")
                port=read_local_devtools_port(profile/"DevToolsActivePort")
                if port is not None:break
                time.sleep(.1)
            if port is None:raise TimeoutError("Chromium CDP port unavailable")
            tabs=None
            for _ in range(60):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list",timeout=3) as response:
                        candidate=json.loads(response.read())
                    if isinstance(candidate,list) and any(
                        isinstance(t,dict) and t.get("type")=="page" for t in candidate
                    ):
                        tabs=candidate
                        break
                except (OSError,ValueError):pass
                time.sleep(.1)
            if tabs is None:raise TimeoutError("Local-only browser page unavailable")
            tab=next(t for t in tabs if t.get("type")=="page")
            endpoint=tab["webSocketDebuggerUrl"].split(f":{port}",1)[1]
            client=CDP(port,endpoint)
            try:
                client.call("Page.enable")
                client.call("Runtime.enable")
                checks=0
                for info in records:
                    path=root/info["filename"]
                    raw=path.read_bytes()
                    assert len(raw)==info["bytes"],info["filename"]
                    assert hashlib.sha256(raw).hexdigest()==info["sha256"]
                    html=raw.decode("utf-8")
                    assert '<meta name="robots" content="noindex, nofollow">' in html
                    assert '<meta http-equiv="Content-Security-Policy"' in html
                    assert ".hero .eyebrow { color: #ffffff; opacity: 1; }" in html
                    assert not re.search(
                        r"<(?:script|form|iframe|input)\b|https?://|javascript:|@import|url\(",
                        html,re.I
                    ),info["filename"]
                    white=rgb("#ffffff")
                    first=rgb(info["heroStart"])
                    last=rgb(info["heroEnd"])
                    button_bg=rgb(info["buttonBackground"])
                    button_fg=rgb(info["buttonText"])
                    for width,height in ((1365,900),(390,844),(320,740)):
                        client.call("Emulation.setDeviceMetricsOverride",{
                            "width":width,"height":height,
                            "deviceScaleFactor":1,"mobile":width<1000,
                        })
                        frame=client.call("Page.navigate",{"url":"about:blank"})["frameId"]
                        client.call("Page.setDocumentContent",{
                            "frameId":frame,"html":html,
                        })
                        for _ in range(90):
                            if client.evaluate("document.readyState==='complete' && "
                                               "document.querySelector('.hero')!==null"):
                                break
                            time.sleep(.05)
                        else:raise RuntimeError("Brand page never rendered "+info["filename"])
                        data=client.evaluate(INSPECT)
                        key=(info["filename"],width)
                        assert data["width"]==width,key
                        assert data["scroll"]<=width+2,(key,"horizontal overflow",data)
                        assert data["bodyScroll"]<=width+2,(key,"body overflow",data)
                        assert data["heroInside"] and data["buttonInside"],(key,data)
                        assert data["lang"]=="pt-BR" and data["main"]==1 and data["h1"]==1,key
                        assert data["viewport"] and data["noindex"] and data["csp"],key
                        assert data["sections"]==[1,1,1] and data["controls"]==0,key
                        assert data["anchorsLocal"],(key,"external/missing anchor")
                        assert data["heroText"]==white and data["eyebrowText"]==white,key
                        gradient=data["heroGradient"]
                        assert "linear-gradient(" in gradient,(key,gradient)
                        normalized=re.sub(r"\s+","",gradient)
                        assert re.sub(r"\s+","",first) in normalized,(key,"first",gradient)
                        assert re.sub(r"\s+","",last) in normalized,(key,"last",gradient)
                        assert data["ctaBackground"]==button_bg,(key,"button bg",data)
                        assert data["ctaText"]==button_fg,(key,"button fg",data)
                        assert ratio(white,first)>=4.5,(key,"hero start contrast")
                        assert ratio(white,last)>=4.5,(key,"hero end contrast")
                        assert ratio(button_bg,button_fg)>=4.5,(key,"button contrast")
                        checks+=1
                        print("PASS F1-192",info["filename"],width,
                              "real CSS gradient/button, >=4.5:1, no overflow")
                assert checks==54,checks
            finally:
                client.close()
        finally:
            chrome.terminate()
            try:chrome.wait(timeout=5)
            except subprocess.TimeoutExpired:
                chrome.kill()
                chrome.wait(timeout=5)
    print("PASS F1-192: 18 fictional palettes x 3 viewports, Chromium CSS verified.")


if __name__=="__main__":
    if len(sys.argv)!=3:
        raise SystemExit("usage: f192_brand_palette_cdp.py <private-gallery> <chromium>")
    inspect(Path(sys.argv[1]).resolve(strict=True),sys.argv[2])
