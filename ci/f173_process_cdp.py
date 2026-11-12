#!/usr/bin/env python3
"""F1-173: actual local Chromium layout on 12 inert numbered-process HTML pages.

Only local CDP on ephemeral loopback; no public HTTP, screenshots, uploads,
browser installation, customer identity, PII, CRM or publishing.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
from urllib.request import urlopen

from f43_cdp import CDP, read_local_devtools_port
from f44_contrast import ratio

INSPECT = r"""(() => {
 const body=document.body;
 const anchors=[...document.querySelectorAll('a[href]')];
 const faq=[...document.querySelectorAll('section.faq h3')];
 const services=[...document.querySelectorAll('section.highlights h3')];
 const cells=[...document.querySelectorAll('section.faq li,section.highlights li,section.process li')];
 const process=[...document.querySelectorAll("section.process ol li")];
 const steps=[...document.querySelectorAll("section.process h3")];
 const headings=faq.concat(services,steps);
 const contrast=[];
 for(const element of document.querySelectorAll(
   'section.faq h3, section.faq p, section.highlights h3, section.highlights p, section.process h3, section.process p')) {
   let parent=element,background=null;
   while(parent) {
     const bg=getComputedStyle(parent).backgroundColor;
     if(bg && bg!=='transparent' &&
        !/^rgba?\(0,\s*0,\s*0,\s*0(?:\.0+)?\)$/.test(bg)) {
        background=bg;break;
     }
     parent=parent.parentElement;
   }
   contrast.push({fg:getComputedStyle(element).color,
                  bg:background,tag:element.tagName});
 }
 return {
   width:innerWidth,scroll:document.documentElement.scrollWidth,
   bodyScroll:body.scrollWidth,
   viewport:!!document.querySelector('meta[name="viewport"]'),
   noindex:!!document.querySelector('meta[name="robots"][content="noindex, nofollow"]'),
   csp:!!document.querySelector('meta[http-equiv="Content-Security-Policy"]'),
   lang:document.documentElement.lang,
   main:document.querySelectorAll('main').length,
   h1:document.querySelectorAll('h1').length,
   faq:faq.length,highlights:services.length,
   process:process.length,orderedLists:document.querySelectorAll("section.process ol").length,
   faqSection:document.querySelectorAll('section.faq').length,
   highlightSection:document.querySelectorAll('section.highlights').length,
   controls:document.querySelectorAll('script,form,input,button,iframe,object,embed').length,
   allLinksLocal:anchors.every(a=>a.getAttribute('href').startsWith('#') &&
                   document.getElementById(a.getAttribute('href').slice(1))),
   links:anchors.length,
   cardsVisible:cells.every(e=>e.getBoundingClientRect().height>20),
   cardsInside:cells.every(e=>e.getBoundingClientRect().left>=-2 &&
                    e.getBoundingClientRect().right<=innerWidth+2),
   headingsInside:headings.every(e=>e.getBoundingClientRect().left>=-2 &&
                    e.getBoundingClientRect().right<=innerWidth+2),
   wrapAnywhere:headings.every(e=>getComputedStyle(e).overflowWrap==='anywhere'),
   contrast
 };
})()"""

def main(root: Path, browser: str) -> None:
    manifest = json.loads((root/"gallery-manifest.json").read_text("utf-8"))
    assert manifest["schemaVersion"] == "sites.synthetic.process-chrome-gallery.v1"
    assert manifest["syntheticOnly"] and not manifest["publishable"]
    assert manifest["officialClientAcceptance"] is False
    expected = {f"{p}-{style}-{variant}.html"
       for p in ("onepage","landing_page")
       for style in ("classic","modern","warm")
       for variant in ("process","complete")}
    assert len(manifest["artifacts"]) == 12
    assert {a["filename"] for a in manifest["artifacts"]} == expected

    with tempfile.TemporaryDirectory(prefix="sites-f173-cdp-",ignore_cleanup_errors=True) as temp:
        profile=Path(temp)/"profile"
        chrome=subprocess.Popen(
            [browser,"--headless=new","--no-sandbox","--disable-gpu",
             "--disable-background-networking","--disable-extensions",
             "--disable-sync","--no-first-run","--no-default-browser-check",
             "--remote-debugging-port=0",f"--user-data-dir={profile}","about:blank"],
            stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL
        )
        try:
            portfile=profile/"DevToolsActivePort"
            port=None
            for _ in range(200):
                if chrome.poll() is not None:
                    raise RuntimeError("Chrome failed before CDP started")
                port=read_local_devtools_port(portfile)
                if port is not None:break
                time.sleep(.1)
            if port is None:raise TimeoutError("Local CDP never became ready")
            tabs=None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list",timeout=3) as resp:
                        candidate=json.loads(resp.read())
                    if isinstance(candidate,list) and any(
                        t.get("type")=="page" for t in candidate if isinstance(t,dict)
                    ):
                        tabs=candidate
                        break
                except (OSError,ValueError):
                    pass
                time.sleep(.1)
            if tabs is None:raise TimeoutError("Chrome page endpoint unavailable")
            page=next(t for t in tabs if t.get("type")=="page")
            endpoint=page["webSocketDebuggerUrl"].split(f":{port}",1)[1]
            client=CDP(port,endpoint)
            try:
                client.call("Page.enable")
                client.call("Runtime.enable")
                visited=0
                for info in manifest["artifacts"]:
                    path=root/info["filename"]
                    html=path.read_bytes()
                    assert hashlib.sha256(html).hexdigest()==info["sha256"]
                    assert len(html)==info["bytes"]
                    text=html.decode("utf-8")
                    assert '<meta name="robots" content="noindex, nofollow">' in text
                    assert not any(token in text.lower() for token in
                        ("<script","<form","https://","http://","@import","url("))
                    expected_faq=1 if info["variant"]=="complete" else 0
                    expected_highlights=1 if info["variant"]=="complete" else 0
                    for width,height in ((1365,900),(390,844),(320,740)):
                        client.call("Emulation.setDeviceMetricsOverride",{
                            "width":width,"height":height,
                            "deviceScaleFactor":1,"mobile":width<1000,
                        })
                        frame=client.call("Page.navigate",{"url":"about:blank"})["frameId"]
                        client.call("Page.setDocumentContent",{
                            "frameId":frame,"html":text,
                        })
                        for _ in range(90):
                            if client.evaluate("document.readyState==='complete' && "
                                "document.querySelector('main')!==null"):break
                            time.sleep(.05)
                        else:raise RuntimeError("Chrome did not finish rendering "+info["filename"])
                        data=client.evaluate(INSPECT)
                        assert data["width"]==width,(info["filename"],width,data)
                        assert data["scroll"]<=width+2,(info["filename"],"horizontal overflow",data)
                        assert data["bodyScroll"]<=width+2,(info["filename"],"body overflow",data)
                        assert data["cardsInside"] and data["headingsInside"],(info["filename"],width,data)
                        assert data["wrapAnywhere"],(info["filename"],"long words don't wrap",data)
                        assert data["cardsVisible"],(info["filename"],"card collapsed",data)
                        assert data["process"]==3 and data["orderedLists"]==1
                        assert data["faq"]==expected_faq
                        assert data["highlights"]==expected_highlights
                        assert data["faqSection"]==(1 if expected_faq else 0)
                        assert data["highlightSection"]==(1 if expected_highlights else 0)
                        assert data["viewport"] and data["noindex"] and data["csp"]
                        assert data["lang"]=="pt-BR"
                        assert data["main"]==1 and data["h1"]==1
                        assert data["controls"]==0 and data["allLinksLocal"]
                        assert data["links"]==(3 if info["product"]=="onepage" else 1)
                        assert len(data["contrast"])>=6
                        for sample in data["contrast"]:
                            assert sample["bg"] is not None, (info["filename"],sample)
                            assert ratio(sample["fg"],sample["bg"])>=4.5,(
                                info["filename"],width,"low text contrast",sample)
                        visited+=1
                        print("PASS F1-173",info["filename"],width,
                              "no horizontal overflow; static process/optional sections contrast >=4.5:1")
                assert visited==36,visited
            finally:
                client.close()
        finally:
            chrome.terminate()
            try:chrome.wait(timeout=5)
            except subprocess.TimeoutExpired:
                chrome.kill()
                chrome.wait(timeout=5)
    print("PASS F1-173: 12 fictional pages x 3 viewports; no public network or uploads")

if __name__=="__main__":
    if len(sys.argv)!=3:
        raise SystemExit("usage: f173_process_cdp.py <private-gallery> <local-chromium-binary>")
    main(Path(sys.argv[1]).resolve(strict=True),sys.argv[2])
