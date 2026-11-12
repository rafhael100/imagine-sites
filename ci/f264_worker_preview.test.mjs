import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';

import worker, {renderSyntheticPreview} from '../ops/cloudflare-worker/src/index.mjs';

const source=process.env.RUNNER_TEMP;
if(process.env.GITHUB_ACTIONS!=='true' ||
   process.env.APP_ENV!=='testing' ||
   process.env.SITES_F264_EDGE_PREVIEW!=='1' ||
   !source) {
  throw new Error('F1-264 requires explicit temporary CI fixture only');
}
const path=source+'/sites-f264-preview-fixture.json';
const data=JSON.parse(await readFile(path,'utf8'));
const URL='https://preview.imagine.invalid/';

function fixture(patch={}) {
  return {...data,...patch};
}
async function digest(html) {
  return createHash('sha256').update(html,'utf8').digest('hex');
}
function request(url=URL,method='GET') {
  return new Request(url,{method});
}

test('real PHP-generated HTML is byte-identical to edge response and unindexable',async()=>{
  assert.equal(data.synthetic,true);
  assert.equal(data.reviewState,'internal_review');
  assert.equal(data.publishable,false);
  assert.equal(data.clientAccepted,false);
  assert.equal(await digest(data.html),data.digest);
  const response=await renderSyntheticPreview(request(),fixture());
  assert.equal(response.status,200);
  assert.equal(response.headers.get('X-Robots-Tag'),
    'noindex, nofollow, noarchive');
  assert.equal(response.headers.get('Cache-Control'),
    'private, no-store, max-age=0');
  assert.equal(response.headers.get('X-Frame-Options'),'DENY');
  assert.match(response.headers.get('Content-Security-Policy'),/script-src 'none'/);
  assert.match(response.headers.get('Content-Security-Policy'),/form-action 'none'/);
  assert.equal(response.headers.get('Content-Type'),'text/html; charset=utf-8');
  assert.equal(response.headers.get('Content-Length'),String(Buffer.byteLength(data.html)));
  assert.equal(await response.text(),data.html);
});

test('HEAD returns same pinned metadata but no HTML body',async()=>{
  const response=await renderSyntheticPreview(request(URL,'HEAD'),fixture());
  assert.equal(response.status,200);
  assert.equal(response.headers.get('Content-Length'),String(Buffer.byteLength(data.html)));
  assert.equal(await response.text(),'');
});

test('live Worker remains hard-disabled even with forged environment flags',async()=>{
  for(const env of [
    {},
    {IMAGINE_SITES_LIVE_GATE:'verified-approval-v1'},
    {PUBLISHABLE:'true',CLIENT_ACCEPTED:'true',ASSETS:{fetch(){throw Error('network')}}},
  ]) {
    const response=await worker.fetch(request(),env,{});
    assert.equal(response.status,503);
    assert.match(response.headers.get('Cache-Control'),/no-store/);
    assert.notEqual(response.headers.get('X-Robots-Tag'),null);
    assert.equal(await response.text(),'Preview unavailable');
  }
});

test('strict HTTPS invalid-domain-only and method gate',async()=>{
  for(const invalid of [
    'https://example.com/',
    'http://preview.imagine.invalid/',
    'https://preview.imagine.invalid:4433/',
    'https://preview.imagine.invalid/another',
    'https://preview.imagine.invalid/?id=1',
    'https://user:pass@preview.imagine.invalid/',
  ]) {
    const result=await renderSyntheticPreview(request(invalid),fixture());
    assert.equal(result.status,404,invalid);
  }
  for(const verb of ['POST','PUT','PATCH','DELETE']) {
    const result=await renderSyntheticPreview(request(URL,verb),fixture());
    assert.equal(result.status,405);
    assert.equal(result.headers.get('Allow'),'GET, HEAD');
  }
});

test('wrong digest, altered HTML and unsafe extensions fail closed',async()=>{
  const changes=[
    {digest:'0'.repeat(64)},
    {html:data.html.replace('noindex, nofollow','index, follow')},
    {html:data.html.replace('</body>','<script>alert(1)</script></body>')},
    {html:data.html.replace('</body>','<form action="/lead"></form></body>')},
    {html:data.html.replace('</body>','<a href="data:text/plain,x">x</a></body>')},
    {html:data.html.replace('</body>','<a href="https://remote.invalid">x</a></body>')},
    {html:'<html><body>not a canonical review</body></html>'},
  ];
  for(const patch of changes) {
    const updated={...fixture(),...patch};
    if(patch.html!==undefined) updated.digest=await digest(updated.html);
    const response=await renderSyntheticPreview(request(),updated);
    assert.equal(response.status,503,'unsafe or inconsistent fixture refused');
    assert.equal(response.headers.get('Cache-Control'),
      'private, no-store, max-age=0');
  }
});

test('review, client approval and fixture gates cannot be bypassed',async()=>{
  const forged=[
    {synthetic:false},
    {reviewState:'approved'},
    {publishable:true},
    {clientAccepted:true},
    {digest:'INVALID'},
    {digest:null},
    {html:null},
    Object.create(null),
  ];
  for(const patch of forged) {
    const response=await renderSyntheticPreview(
      request(),Object.getPrototypeOf(patch)===null?patch:fixture(patch)
    );
    assert.equal(response.status,503);
  }
  assert.equal((await renderSyntheticPreview(request(),undefined)).status,503);
  assert.equal((await renderSyntheticPreview({},fixture())).status,404);
});
