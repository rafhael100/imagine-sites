#!/usr/bin/env python3
"""F1-163: visually inspect the ACTUAL Laravel rich-review offline gallery.

The gallery is created by the existing CLI-only F1-162 export. Chromium
runs headless against local document content via CDP; no web server, external
driver/provider, screenshot upload, customer data, or production action.
"""
from __future__ import annotations

import hashlib
import html as html_lib
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

OUTER = r"""(() => {
 const links=[...document.querySelectorAll('a[href]')].map(a=>a.getAttribute('href'));
 const iframe=document.querySelector('iframe');
 return {
   width:innerWidth,
   scroll:document.documentElement.scrollWidth,
   bodyScroll:document.body.scrollWidth,
   lang:document.documentElement.lang,
   main:document.querySelectorAll('main').length,
   h1:document.querySelectorAll('h1').length,
   browser:!!document.querySelector('.browser'),
   iframeCount:document.querySelectorAll('iframe').length,
   sandbox:iframe?.getAttribute('sandbox'),
   tabindex:iframe?.getAttribute('tabindex'),
   referrer:iframe?.getAttribute('referrerpolicy'),
   skip:document.querySelector('a.skip-preview')?.getAttribute('href'),
   skipTarget:document.getElementById('apos-previa')?.getAttribute('tabindex'),
   staffSkip:document.querySelector('a.skip-shell')?.getAttribute('href'),
   staffMainTarget:document.getElementById('sites-review-main')?.getAttribute('tabindex'),
   iframeTitle:iframe?.getAttribute('title'),
   richInfo:document.body.textContent.includes('Conteúdo da página ampliado') &&
            document.body.textContent.includes('2 soluções e 2 perguntas frequentes'),
   approvalPending:document.body.textContent.includes('Aceite oficial pendente no Portal'),
   controls:document.querySelectorAll('script,form,input,button,object,embed').length,
   links,
   selected:[...document.querySelectorAll('a[aria-current="page"]')].length,
   viewport:!!document.querySelector('meta[name="viewport"]'),
   noindex:!!document.querySelector('meta[name="robots"][content="noindex, nofollow"]'),
   csp:!!document.querySelector('meta[http-equiv="Content-Security-Policy"]')
 };
})()"""

INNER = r"""(() => {
 const faq=[...document.querySelectorAll('section.faq h3')];
 const highlights=[...document.querySelectorAll('section.highlights h3')];
 const cards=[...document.querySelectorAll('section.faq li,section.highlights li')];
 const headings=[...faq,...highlights];
 const samples=[];
 for(const item of document.querySelectorAll(
     'section.faq h3,section.faq p,section.highlights h3,section.highlights p')) {
   let background=null,parent=item;
   while(parent) {
     const color=getComputedStyle(parent).backgroundColor;
     if(color && color!=='transparent' &&
       !/^rgba?\(0,\s*0,\s*0,\s*0(?:\.0+)?\)$/.test(color)) {
       background=color;break;
     }
     parent=parent.parentElement;
   }
   samples.push({
     color:getComputedStyle(item).color,
     background:background,
   });
 }
 const links=[...document.querySelectorAll('a[href]')];
 return {
   width:innerWidth,
   scroll:document.documentElement.scrollWidth,
   bodyScroll:document.body.scrollWidth,
   lang:document.documentElement.lang,
   main:document.querySelectorAll('main').length,
   h1:document.querySelectorAll('h1').length,
   faq:faq.length,
   highlights:highlights.length,
   faqSection:document.querySelectorAll('section.faq').length,
   highlightSection:document.querySelectorAll('section.highlights').length,
   noActive:document.querySelectorAll('script,form,button,input,iframe,object,embed').length===0,
   anchorsLocal:links.every(a=>a.getAttribute('href').startsWith('#') &&
                   !!document.getElementById(a.getAttribute('href').slice(1))),
   wrapAnywhere:headings.every(h=>getComputedStyle(h).overflowWrap==='anywhere'),
   cardsInside:cards.every(c=>c.getBoundingClientRect().left>=-2 &&
                 c.getBoundingClientRect().right<=innerWidth+2 &&
                 c.getBoundingClientRect().height>20),
   headingsInside:headings.every(h=>h.getBoundingClientRect().left>=-2 &&
                 h.getBoundingClientRect().right<=innerWidth+2),
   samples
 };
})()"""

def inspect_source(root: Path, record: dict) -> tuple[str,str]:
    raw=(root/record["filename"]).read_bytes()
    assert hashlib.sha256(raw).hexdigest()==record["sha256"]
    assert len(raw)==record["bytes"]
    outer=raw.decode("utf-8")
    assert '<meta name="robots" content="noindex, nofollow">' in outer
    assert '<meta http-equiv="Content-Security-Policy"' in outer
    assert 'sandbox=""' in outer and 'tabindex="0"' in outer
    assert not re.search(r'<(?:script|form)\b|https?://|javascript:|@import',outer,re.I)
    match=re.search(r'\bsrcdoc="([^"]+)"',outer,re.S)
    assert match, record["filename"]
    inner=html_lib.unescape(match.group(1))
    assert hashlib.sha256(inner.encode("utf-8")).hexdigest()==record["artifactDigest"]
    assert '<section class="faq"' in inner
    assert '<section class="highlights"' in inner
    return outer,inner

def render(client: CDP, html: str) -> None:
    frame=client.call("Page.navigate",{"url":"about:blank"})["frameId"]
    client.call("Page.setDocumentContent",{"frameId":frame,"html":html})
    for _ in range(90):
        if client.evaluate("document.readyState==='complete' && document.querySelector('main')!==null"):
            return
        time.sleep(.05)
    raise RuntimeError("Local Chrome document did not render")

def inspect(root: Path, binary: str) -> None:
    metadata=json.loads((root/"gallery-manifest.json").read_text("utf-8"))
    assert metadata["schemaVersion"]=="sites.synthetic.rich-review-gallery.v1"
    assert metadata["syntheticOnly"] and not metadata["publishable"]
    assert metadata["officialClientAcceptance"] is False
    expected={f"{p}-{d}-{s}.html"
        for p in ("onepage","landing_page")
        for d in ("desktop","mobile")
        for s in ("classic","modern","warm")}
    assert len(metadata["pages"])==12
    assert {v["filename"] for v in metadata["pages"]}==expected

    with tempfile.TemporaryDirectory(prefix="sites-f163-chromium-",ignore_cleanup_errors=True) as temp:
        profile=Path(temp)/"profile"
        chrome=subprocess.Popen(
            [binary,"--headless=new","--no-sandbox","--disable-gpu",
             "--disable-background-networking","--disable-extensions",
             "--disable-sync","--no-first-run","--no-default-browser-check",
             "--remote-debugging-port=0",f"--user-data-dir={profile}","about:blank"],
            stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
        )
        try:
            portfile=profile/"DevToolsActivePort"
            port=None
            for _ in range(200):
                if chrome.poll() is not None:
                    raise RuntimeError("Chrome closed before CDP ready")
                port=read_local_devtools_port(portfile)
                if port is not None:break
                time.sleep(.1)
            if port is None:raise TimeoutError("CDP did not become ready")
            tabs=None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list",timeout=3) as response:
                        candidate=json.loads(response.read())
                    if isinstance(candidate,list) and any(
                        isinstance(x,dict) and x.get("type")=="page"
                        for x in candidate
                    ):
                        tabs=candidate;break
                except (ValueError,OSError):
                    pass
                time.sleep(.1)
            if tabs is None:raise TimeoutError("Local Chrome page endpoint unavailable")
            tab=next(t for t in tabs if t.get("type")=="page")
            endpoint=tab["webSocketDebuggerUrl"].split(f":{port}",1)[1]
            client=CDP(port,endpoint)
            try:
                client.call("Page.enable")
                client.call("Runtime.enable")
                count=0
                for entry in metadata["pages"]:
                    outer,inner=inspect_source(root,entry)
                    # Review UI itself: desktop/mobile and a tight 320px viewport.
                    for width,height in ((1365,900),(390,844),(320,740)):
                        client.call("Emulation.setDeviceMetricsOverride",{
                            "width":width,"height":height,
                            "deviceScaleFactor":1,"mobile":width<1000,
                        })
                        render(client,outer)
                        result=client.evaluate(OUTER)
                        label=(entry["filename"],width)
                        assert result["width"]==width,label
                        assert result["scroll"]<=width+2,(label,"outer overflow",result)
                        assert result["bodyScroll"]<=width+2,(label,"outer body overflow",result)
                        assert result["lang"]=="pt-BR" and result["main"]==1 and result["h1"]==1,label
                        assert result["browser"] and result["iframeCount"]==1,label
                        assert result["sandbox"]=="" and result["tabindex"]=="0",label
                        assert result["referrer"]=="no-referrer" and result["iframeTitle"],label
                        assert result["richInfo"] and result["approvalPending"],label
                        assert result["controls"]==0 and result["selected"]==3,label
                        assert result["viewport"] and result["noindex"] and result["csp"],label
                        # F1-189: F1-183 introduced an explicit, real
                        # keyboard bypass of the sandboxed preview. It is a
                        # ninth fixed local anchor, not an external route.
                        assert len(result["links"])==10,(label,result["links"])
                        assert result["links"][0]=="#sites-review-main",(label,result["links"])
                        assert result["staffSkip"]=="#sites-review-main" and result["staffMainTarget"]=="-1",label
                        assert result["links"][-2:]==["#apos-previa","#detalhes"],(label,result["links"])
                        assert result["skip"]=="#apos-previa" and result["skipTarget"]=="-1",label
                        for href in result["links"]:
                            assert href in ("#sites-review-main","#apos-previa","#detalhes") or href in expected,(label,href)
                        count+=1
                    # Actual iframe HTML is inspected separately: no sandbox bypass.
                    for width,height in ((1365,900),(390,844),(320,740)):
                        client.call("Emulation.setDeviceMetricsOverride",{
                            "width":width,"height":height,
                            "deviceScaleFactor":1,"mobile":width<1000,
                        })
                        render(client,inner)
                        result=client.evaluate(INNER)
                        label=(entry["filename"],"inner",width)
                        assert result["width"]==width,label
                        assert result["scroll"]<=width+2,(label,"inner overflow",result)
                        assert result["bodyScroll"]<=width+2,(label,"inner body overflow",result)
                        assert result["lang"]=="pt-BR" and result["main"]==1 and result["h1"]==1,label
                        assert result["faq"]==2 and result["highlights"]==2,label
                        assert result["faqSection"]==1 and result["highlightSection"]==1,label
                        assert result["noActive"] and result["anchorsLocal"],label
                        assert result["wrapAnywhere"] and result["cardsInside"] and result["headingsInside"],label
                        assert len(result["samples"])>=8,label
                        for sample in result["samples"]:
                            assert sample["background"] is not None,(label,sample)
                            assert ratio(sample["color"],sample["background"])>=4.5,(
                                label,"static text contrast",sample)
                        count+=1
                    print("PASS F1-163",entry["filename"],
                          "outer+inner responsive, local links, sandbox, contrast")
                assert count==72,count
            finally:
                client.close()
        finally:
            chrome.terminate()
            try:chrome.wait(timeout=5)
            except subprocess.TimeoutExpired:
                chrome.kill();chrome.wait(timeout=5)
    print("PASS F1-163: 12 private Laravel pages x 6 outer/inner viewports; no network or upload.")

if __name__=="__main__":
    if len(sys.argv)!=3:
        raise SystemExit("usage: f163_rich_gallery_cdp.py <private-gallery> <chromium>")
    inspect(Path(sys.argv[1]).resolve(strict=True),sys.argv[2])
