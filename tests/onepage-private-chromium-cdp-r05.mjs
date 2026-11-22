import { spawn } from 'node:child_process';
import { mkdtemp, readFile, cp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// All network use must be loopback; fixture identity and receipts are NOT Portal.
const [origin, dir, firstSha, secondSha] = process.argv.slice(2);
if (!origin?.startsWith('http://127.0.0.1:') ||
    !/^[a-f0-9]{64}$/.test(firstSha ?? '') ||
    !/^[a-f0-9]{64}$/.test(secondSha ?? '') || firstSha===secondSha) {
  throw new Error('R05_SCOPE_REFUSED');
}
const binary=process.env.BROWSER_BIN;
if (!binary?.startsWith('/')) throw new Error('R05_BROWSER_BINARY_REFUSED');
const profile=await mkdtemp(join(tmpdir(),'sites-r05-chrome-'));
let chrome, ws;
let stage='START_CHROME';
let checks=0;
const check=v=>{if(!v) throw new Error('R05_BROWSER_ASSERTION_REFUSED'); ++checks;};
const pause=ms=>new Promise(r=>setTimeout(r,ms));
const until=async(fn,count=70)=>{
  for(let i=0;i<count;++i){
    const value=await fn().catch(()=>false);
    if(value) return value;
    await pause(100);
  }
  throw new Error('R05_BROWSER_TIMEOUT');
};
let seq=0;
const inflight=new Map();
const cmd=async(method,params={})=>{
  if(!ws || ws.readyState!==WebSocket.OPEN) throw new Error('R05_CDP_REFUSED');
  const id=++seq;
  const promise=new Promise((resolve,reject)=>inflight.set(id,{resolve,reject}));
  ws.send(JSON.stringify({id,method,params}));
  return await promise;
};
const evaluate=async(expression)=>{
  const result=await cmd('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
  if(result.exceptionDetails || !result.result || !('value' in result.result)){
    throw new Error('R05_BROWSER_EVALUATION_REFUSED');
  }
  return result.result.value;
};
const visit=async(path)=>{
  await cmd('Page.navigate',{url:origin+path});
  await until(async()=>await evaluate('document.readyState')==='complete');
};
const readyReview=async()=>await until(async()=>{
  const result=await evaluate('({path:location.pathname,ready:window.r05Ready===true,frame:!!document.querySelector("iframe")?.contentDocument?.body})');
  return result.path==='/review' && result.ready && result.frame;
});
const snapshot=async()=>await evaluate(
  '(async()=>{'+
  'const frame=document.querySelector("iframe");'+
  'const r=await fetch("/private-onepage",{cache:"no-store"});'+
  'const bytes=await r.arrayBuffer();'+
  'const sha=Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256",bytes))).map(v=>v.toString(16).padStart(2,"0")).join("");'+
  'return {status:r.status,sha,length:bytes.byteLength,frameText:frame.contentDocument.body.textContent.length,framePath:frame.contentWindow.location.pathname};'+
  '})()'
);
const click=async()=>{
  await cmd('Runtime.evaluate',{expression:'window.r05Result=null'});
  const p=await evaluate(
    '(()=>{const b=document.querySelector("#accept");const r=b.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2,disabled:b.disabled};})()'
  );
  check(p.disabled===false && p.x>0 && p.y>0);
  await cmd('Input.dispatchMouseEvent',{type:'mousePressed',x:p.x,y:p.y,button:'left',clickCount:1});
  await cmd('Input.dispatchMouseEvent',{type:'mouseReleased',x:p.x,y:p.y,button:'left',clickCount:1});
  return await until(async()=>await evaluate('window.r05Result'));
};

try{
  chrome=spawn(binary,[
    '--headless=new','--no-sandbox','--disable-gpu','--no-first-run',
    '--no-default-browser-check','--disable-background-networking',
    '--disable-sync','--disable-extensions','--disable-features=MediaRouter',
    '--no-proxy-server',
    '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1',
    '--remote-debugging-port=0','--remote-debugging-address=127.0.0.1',
    '--user-data-dir='+profile,'about:blank'
  ],{stdio:['ignore','ignore','ignore']});
  stage='START_CDP';
  const portString=await until(async()=>{
    const value=await readFile(join(profile,'DevToolsActivePort'),'utf8');
    return value.match(/^(\d+)\n/)?.[1] ?? false;
  });
  const port=Number(portString);
  check(port>=1025 && port<=65535);
  const tabResponse=await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'});
  if(!tabResponse.ok) throw new Error('R05_TAB_REFUSED');
  const tab=await tabResponse.json();
  if(!tab.webSocketDebuggerUrl?.startsWith('ws://127.0.0.1:')) throw new Error('R05_CDP_ADDRESS_REFUSED');
  ws=new WebSocket(tab.webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{
    ws.onopen=resolve;
    ws.onerror=()=>reject(new Error('R05_CDP_CONNECT_REFUSED'));
  });
  ws.onmessage=({data})=>{
    const msg=JSON.parse(data);
    if(msg.id && inflight.has(msg.id)){
      const item=inflight.get(msg.id);
      inflight.delete(msg.id);
      if(msg.error) item.reject(new Error('R05_CDP_COMMAND_REFUSED'));
      else item.resolve(msg.result);
    }
  };
  await cmd('Page.enable');
  await cmd('Runtime.enable');
  stage='DENY_GUEST';
  await visit('/private-onepage');
  const empty=await evaluate('document.body.innerText.length');
  check(empty===0 || empty<100);
  stage='LOGIN_A';
  await visit('/fixture-enter');
  await readyReview();
  stage='REVIEW_A';
  const a=await snapshot();
  check(a.status===200 && a.sha===firstSha && a.length>0 &&
        a.frameText>0 && a.framePath==='/private-onepage');
  stage='CLICK_A';
  const first=await click();
  check(first.status===201 && first.body?.ok===true && first.body?.ref==='synthetic-r05-1');
  stage='REPLAY';
  const replay=await click();
  check(replay.status===200 && replay.body?.ok===true && replay.body?.ref==='synthetic-r05-1');
  await cp(join(dir,'next-preview.html'),join(dir,'preview.html'));
  await cp(join(dir,'next-release.json'),join(dir,'release.json'));
  stage='STALE';
  const stale=await click();
  check(stale.status===409 && stale.body?.ok===false);
  stage='LOGIN_B';
  await visit('/review');
  await readyReview();
  stage='REVIEW_B';
  const b=await snapshot();
  check(b.status===200 && b.sha===secondSha && b.sha!==a.sha &&
        b.frameText>0 && b.framePath==='/private-onepage');
  stage='CLICK_B';
  const acceptedB=await click();
  check(acceptedB.status===201 && acceptedB.body?.ok===true && acceptedB.body?.ref==='synthetic-r05-2');
  stage='DENY_OTHER';
  await visit('/fixture-other');
  const denied=await evaluate(
    '(async()=>{const r=await fetch("/private-onepage",{cache:"no-store"});return {status:r.status,length:(await r.text()).length};})()'
  );
  check(denied.status===403 && denied.length===0);
  console.log('ONEPAGE R05 CHROMIUM PASS: '+checks+' checks; NO-GO.');
}catch(error){
  const code=error instanceof Error && /^R05_[A-Z0-9_]+$/.test(error.message)
    ? error.message : 'R05_OTHER';
  const snapshot=await evaluate(
    '({blocked:location.protocol==="chrome-error:",review:location.pathname==="/review",ready:window.r05Ready===true,frame:!!document.querySelector("iframe"),secure:isSecureContext})'
  ).catch(()=>null);
  const state=snapshot===null ? 'UNKNOWN'
    : snapshot.blocked ? 'CHROME_BLOCKED'
    : !snapshot.review ? 'NOT_REVIEW'
    : !snapshot.frame ? 'NO_IFRAME'
    : !snapshot.secure ? 'INSECURE_CONTEXT'
    : !snapshot.ready ? 'OBSERVATION_INCOMPLETE' : 'REVIEW_READY';
  console.error('R05_FAIL '+stage+' '+code+' '+state);
  process.exitCode=1;
}finally{
  for(const pending of inflight.values()) pending.reject(new Error('R05_BROWSER_SHUTDOWN'));
  try{ws?.close();}catch{}
  if(chrome && chrome.exitCode===null) chrome.kill('SIGTERM');
  await pause(150);
  if(chrome && chrome.exitCode===null) chrome.kill('SIGKILL');
  await rm(profile,{force:true,recursive:true});
}
