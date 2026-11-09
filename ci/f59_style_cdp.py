#!/usr/bin/env python3
"""F1-59: inspect 12 real-rendered, offline Site HTML pages in local Chromium.

Tests the INNER preview HTML (not just the outer operator UI) using CDP:
mobile/desktop width, local anchors, landmarks, and solid-background contrast.
Gradient/opacity contrast, complete WCAG and client visual QA remain unproven.
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

from f43_cdp import CDP
from f44_contrast import ratio

if len(sys.argv)!=3:
    raise SystemExit("usage: f59_style_cdp.py <private-gallery> <local-chrome-executable>")

GALLERY=Path(sys.argv[1]).resolve(strict=True)
BROWSER=sys.argv[2]
manifest=json.loads((GALLERY/"gallery-manifest.json").read_text("utf-8"))
assert manifest["schemaVersion"]=="sites.synthetic.style-gallery.v1"
assert manifest["syntheticOnly"] and not manifest["publishable"]
assert not manifest["officialClientAcceptance"]
assert len(manifest["pages"])==12

INSPECT=r"""(() => {
 const body=document.querySelector('body');
 const links=[...document.querySelectorAll('a[href]')];
 const styles=[getComputedStyle(document.querySelector('.hero')).backgroundImage];
 const selectors=['header .brand','.benefits h2','.benefits li',
   '.contact h2','.contact p','.btn','footer'];
 if(document.querySelector('nav a')) selectors.push('nav a');
 if(document.querySelector('.tagline')) selectors.push('.tagline');
 if(document.querySelector('.about h2')) selectors.push('.about h2');
 const samples=[];
 for (const selector of selectors) {
  const els=[...document.querySelectorAll(selector)];
  if(els.length===0) throw Error('missing '+selector);
  for(const el of els) {
   let ancestor=el, bg=null;
   while(ancestor){
     const v=getComputedStyle(ancestor).backgroundColor;
     if(v && v!=='transparent' && !/^rgba?\(0,\s*0,\s*0,\s*0(?:\.0+)?\)$/.test(v)){
       bg=v;break;
     }
     ancestor=ancestor.parentElement;
   }
   if(!bg) throw Error('missing opaque background for '+selector);
   samples.push({selector,color:getComputedStyle(el).color,background:bg});
  }
 }
 return {
  width:innerWidth,scroll:document.documentElement.scrollWidth,
  bodyScroll:document.body.scrollWidth,lang:document.documentElement.lang,
  hasViewport:!!document.querySelector('meta[name="viewport"]'),
  main:document.querySelectorAll('main').length,
  h1:document.querySelectorAll('h1').length,
  links:links.map(a=>a.getAttribute('href')),
  anchorsLocal:links.every(a=>{const href=a.getAttribute('href');
     return href.startsWith('#') && !!document.getElementById(href.slice(1));}),
  disabled:document.querySelectorAll('script,form,button,input,object,embed').length,
  product:body?.getAttribute('data-site-product'),
  themeStyle:document.querySelector('style')?.textContent || '',
  samples,gradient:styles[0]
 };
})()"""

def html_source(page):
    raw=(GALLERY/page["filename"]).read_bytes()
    assert hashlib.sha256(raw).hexdigest()==page["sha256"]
    assert len(raw)==page["bytes"]
    outer=raw.decode("utf-8")
    found=re.search(r'\bsrcdoc="([^"]+)"',outer,re.S)
    assert found,page["filename"]
    inner=html_lib.unescape(found.group(1))
    assert hashlib.sha256(inner.encode("utf-8")).hexdigest()==page["artifactDigest"]
    assert not re.search(r'<(?:script|form)\b|https?://|javascript:|@import',inner,re.I)
    return inner

def main():
    with tempfile.TemporaryDirectory(prefix="sites-f59-chrome-",ignore_cleanup_errors=True) as tmp:
        profile=Path(tmp)/"profile"
        chrome=subprocess.Popen([
            BROWSER,"--headless=new","--no-sandbox","--disable-gpu",
            "--disable-background-networking","--disable-extensions",
            "--disable-sync","--no-first-run","--no-default-browser-check",
            "--remote-debugging-port=0",f"--user-data-dir={profile}","about:blank"
        ],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            portfile=profile/"DevToolsActivePort"
            for _ in range(120):
                if portfile.exists():break
                if chrome.poll() is not None:
                    raise RuntimeError("Local Chrome exited before DevTools startup")
                time.sleep(.1)
            if not portfile.exists():raise TimeoutError("Local Chrome did not open DevTools")
            port=int(portfile.read_text().splitlines()[0])
            with urlopen(f"http://127.0.0.1:{port}/json/list",timeout=3) as reply:
                tabs=json.loads(reply.read())
            target=next(x for x in tabs if x["type"]=="page")
            endpoint=target["webSocketDebuggerUrl"].split(f":{port}",1)[1]
            client=CDP(port,endpoint)
            try:
                client.call("Page.enable")
                client.call("Runtime.enable")
                seen=set()
                for page in manifest["pages"]:
                    filename=page["filename"]
                    expected_width=390 if page["device"]=="mobile" else 1365
                    height=844 if page["device"]=="mobile" else 900
                    client.call("Emulation.setDeviceMetricsOverride",{
                        "width":expected_width,"height":height,
                        "deviceScaleFactor":1,"mobile":page["device"]=="mobile"
                    })
                    frame_id=client.call("Page.navigate",{"url":"about:blank"})["frameId"]
                    client.call("Page.setDocumentContent",{
                        "frameId":frame_id,"html":html_source(page)
                    })
                    for _ in range(90):
                        if client.evaluate(
                            "document.readyState==='complete' && "
                            "document.querySelector('[data-site-product]')!==null"
                        ):break
                        time.sleep(.05)
                    else:raise RuntimeError("Did not render "+filename)
                    v=client.evaluate(INSPECT)
                    assert v["width"]==expected_width,(filename,"viewport",v)
                    assert v["scroll"]<=expected_width+2,(filename,"overflow",v)
                    assert v["bodyScroll"]<=expected_width+2,(filename,"body overflow",v)
                    assert v["lang"]=="pt-BR" and v["hasViewport"],filename
                    assert v["main"]==1 and v["h1"]==1,filename
                    assert v["anchorsLocal"] and v["disabled"]==0,filename
                    assert v["product"]==(
                        "onepage" if page["product"]=="onepage" else "landing-page"
                    ),filename
                    assert len(v["links"])==(3 if page["product"]=="onepage" else 1),filename
                    if page["preset"]=="classic":
                        assert "visual-preset:" not in v["themeStyle"],filename
                    else:
                        assert "visual-preset: "+page["preset"] in v["themeStyle"],filename
                    assert len(v["samples"])>=7,filename
                    failures=[]
                    for sample in v["samples"]:
                        score=ratio(sample["color"],sample["background"])
                        if score<4.5:
                            failures.append((sample["selector"],round(score,2)))
                    if failures:raise AssertionError((filename,"solid contrast below 4.5:1",failures))
                    seen.add(filename)
                    print("PASS F1-59",filename,expected_width,
                          "no horizontal overflow,",len(v["samples"]),"solid text samples >= 4.5:1")
                assert len(seen)==12
            finally:
                client.close()
        finally:
            chrome.terminate()
            try:chrome.wait(timeout=5)
            except subprocess.TimeoutExpired:
                chrome.kill()
                chrome.wait(timeout=5)
    print("PASS F1-59 12 real local Chrome inspections. Not WCAG certification, no network/deploy.")

if __name__=="__main__":main()
