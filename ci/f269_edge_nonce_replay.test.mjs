import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

import worker,{renderNonceFencedSyntheticPreview} from '../ops/cloudflare-worker/src/index.mjs';
import {
  syntheticCanonicalReleaseBytes,
} from '../ops/cloudflare-worker/src/release-signature.mjs';
import {
  EphemeralSyntheticNonceLedger,
  inspectSyntheticNonceReplay,
} from '../ops/cloudflare-worker/src/nonce-ledger.mjs';

if(process.env.APP_ENV!=='testing' ||
   process.env.GITHUB_ACTIONS!=='true' ||
   process.env.SITES_F264_EDGE_PREVIEW!=='1' ||
   !process.env.RUNNER_TEMP) {
  throw new Error('F1-269 runs only on disposable CI fixture');
}
const fixture=JSON.parse(await readFile(
  process.env.RUNNER_TEMP+'/sites-f264-preview-fixture.json','utf8'
));
const keys=await crypto.subtle.generateKey(
  {name:'Ed25519'},true,['sign','verify']
);
const now=1700000000;
const scope='1'.repeat(64);
const release='2'.repeat(64);
const payload=Object.freeze({
  schema:'sites.synthetic.edge-signature.v1',
  audience:'preview.imagine.invalid',
  scopeCorrelationSha256:scope,
  releaseRefSha256:release,
  htmlSha256:fixture.digest,
  issuedAt:now-10,
  expiresAt:now+100,
  nonce:'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
});
async function sign(input=payload) {
  const body=syntheticCanonicalReleaseBytes(input);
  assert.ok(body instanceof Uint8Array);
  const sig=await crypto.subtle.sign('Ed25519',keys.privateKey,body);
  return {payload:input,signature:Buffer.from(sig).toString('base64url')};
}
const envelope=await sign();
const request=()=>new Request('https://preview.imagine.invalid/');

test('a genuine test signature and nonce yield one private preview only',async()=>{
  const ledger=new EphemeralSyntheticNonceLedger();
  const first=await renderNonceFencedSyntheticPreview(
    request(),fixture,envelope,keys.publicKey,now,ledger
  );
  assert.equal(first.status,200);
  assert.equal(await first.text(),fixture.html);
  assert.equal(first.headers.get('X-Robots-Tag'),'noindex, nofollow, noarchive');
  const second=await renderNonceFencedSyntheticPreview(
    request(),fixture,envelope,keys.publicKey,now,ledger
  );
  assert.equal(second.status,503);
  assert.equal(second.headers.get('Cache-Control'),'private, no-store, max-age=0');
});

test('synthetic replay detection is atomic within one JS isolate',async()=>{
  const ledger=new EphemeralSyntheticNonceLedger();
  const results=await Promise.all(Array.from({length:12},()=>
    inspectSyntheticNonceReplay(envelope,keys.publicKey,now,ledger)
  ));
  assert.equal(results.filter(x=>x.syntheticReservation).length,1);
  for(const x of results) {
    assert.equal(x.canPublish,false);
    assert.equal(x.canServePublicHost,false);
    assert.equal(x.canAcknowledgeVisitor,false);
  }
});

test('different valid scopes may reserve same nonce without sharing cross-tenant ledger entry',async()=>{
  const ledger=new EphemeralSyntheticNonceLedger();
  const other=await sign({
    ...payload,scopeCorrelationSha256:'3'.repeat(64),
  });
  assert.equal((await inspectSyntheticNonceReplay(
    envelope,keys.publicKey,now,ledger
  )).syntheticReservation,true);
  assert.equal((await inspectSyntheticNonceReplay(
    other,keys.publicKey,now,ledger
  )).syntheticReservation,true);
  assert.equal((await inspectSyntheticNonceReplay(
    other,keys.publicKey,now,ledger
  )).syntheticReservation,false);
});

test('untrusted, malformed, expired or absent ledgers deny by default',async()=>{
  for(const ledger of [undefined,{},null,{reserveOnce:()=>true}]) {
    const status=await inspectSyntheticNonceReplay(
      envelope,keys.publicKey,now,ledger
    );
    assert.equal(status.syntheticReservation,false);
  }
  assert.equal((await inspectSyntheticNonceReplay(
    envelope,keys.publicKey,now+100,new EphemeralSyntheticNonceLedger()
  )).syntheticReservation,false);
  assert.equal((await renderNonceFencedSyntheticPreview(
    request(),fixture,envelope,keys.publicKey,now,undefined
  )).status,503);
  assert.equal((await renderNonceFencedSyntheticPreview(
    new Request('https://example.com/'),fixture,
    envelope,keys.publicKey,now,new EphemeralSyntheticNonceLedger()
  )).status,404);
});

test('bounded ledger fails closed at capacity and does not prune live entries',()=>{
  const ledger=new EphemeralSyntheticNonceLedger();
  for(let i=0;i<256;i++) {
    assert.equal(ledger.reserveOnce(
      i.toString(16).padStart(64,'0'),now,now+100
    ),true);
  }
  assert.equal(ledger.reserveOnce('f'.repeat(64),now,now+100),false);
  assert.equal(ledger.reserveOnce('0'.repeat(64),now,now+100),false);
  assert.equal(ledger.reserveOnce('f'.repeat(64),now+100,now+180),true);
  assert.equal(ledger.reserveOnce('f'.repeat(64),now+100,now+180),false);
  assert.equal(ledger.reserveOnce('not-hex',now,now+100),false);
  assert.equal(ledger.reserveOnce('f'.repeat(64),now,now+400),false);
});

test('public Worker still never serves a site or exposes nonce storage',async()=>{
  const response=await worker.fetch(
    request(),
    {APPROVED:'true',NONCE_RESERVED:true},{}
  );
  assert.equal(response.status,503);
  assert.equal(await response.text(),'Preview unavailable');
});
