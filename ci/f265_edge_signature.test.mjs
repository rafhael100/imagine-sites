import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';

import worker, {renderSignedSyntheticPreview} from '../ops/cloudflare-worker/src/index.mjs';
import {
  syntheticCanonicalReleaseBytes,
  inspectSyntheticReleaseSignature,
} from '../ops/cloudflare-worker/src/release-signature.mjs';

if(process.env.GITHUB_ACTIONS!=='true' ||
   process.env.APP_ENV!=='testing' ||
   process.env.SITES_F264_EDGE_PREVIEW!=='1' ||
   !process.env.RUNNER_TEMP) {
  throw new Error('F1-265 only supports disposable signed CI fixtures');
}
const asset=JSON.parse(await readFile(
  process.env.RUNNER_TEMP+'/sites-f264-preview-fixture.json','utf8'
));
const now=1700000000;
const htmlSha=createHash('sha256').update(asset.html,'utf8').digest('hex');
assert.equal(asset.digest,htmlSha);

const payload=Object.freeze({
  schema:'sites.synthetic.edge-signature.v1',
  audience:'preview.imagine.invalid',
  scopeCorrelationSha256:'1'.repeat(64),
  releaseRefSha256:'2'.repeat(64),
  htmlSha256:htmlSha,
  issuedAt:now-30,
  expiresAt:now+120,
  nonce:'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
});
const keys=await crypto.subtle.generateKey(
  {name:'Ed25519'},true,['sign','verify']
);
const wrongKeys=await crypto.subtle.generateKey(
  {name:'Ed25519'},true,['sign','verify']
);
async function makeEnvelope(input=payload,privateKey=keys.privateKey) {
  const bytes=syntheticCanonicalReleaseBytes(input);
  if(!(bytes instanceof Uint8Array))throw Error('cannot sign invalid schema');
  const sig=new Uint8Array(await crypto.subtle.sign('Ed25519',privateKey,bytes));
  return {payload:input,signature:Buffer.from(sig).toString('base64url')};
}
const envelope=await makeEnvelope();

test('cryptographically valid fake signature is explicitly NOT release authority',async()=>{
  const report=await inspectSyntheticReleaseSignature(envelope,keys.publicKey,now);
  assert.equal(report.reasonCode,'SIGNATURE_MATCH_UNAUTHORIZED');
  assert.equal(report.signatureValid,true);
  assert.equal(report.canPublish,false);
  assert.equal(report.canServePublicHost,false);
  assert.equal(report.canApproveForClient,false);
  const reply=await renderSignedSyntheticPreview(
    new Request('https://preview.imagine.invalid/'),
    {...asset},envelope,keys.publicKey,now
  );
  assert.equal(reply.status,200);
  assert.equal(await reply.text(),asset.html);
  assert.equal(reply.headers.get('X-Robots-Tag'),
    'noindex, nofollow, noarchive');
});

test('signature cannot be replayed for changed HTML digest, scope or key',async()=>{
  const request=new Request('https://preview.imagine.invalid/');
  const modified={...asset,html:asset.html+' ',digest:awaitHash(asset.html+' ')};
  assert.equal((await renderSignedSyntheticPreview(
    request,modified,envelope,keys.publicKey,now
  )).status,503);
  assert.equal((await inspectSyntheticReleaseSignature(
    envelope,wrongKeys.publicKey,now
  )).signatureValid,false);
  for(const patch of [
    {htmlSha256:'3'.repeat(64)},
    {scopeCorrelationSha256:'4'.repeat(64)},
    {releaseRefSha256:'5'.repeat(64)},
  ]) {
    const forged={...envelope,payload:{...payload,...patch}};
    assert.equal((await inspectSyntheticReleaseSignature(
      forged,keys.publicKey,now
    )).signatureValid,false);
  }
  const other=await makeEnvelope(payload,wrongKeys.privateKey);
  assert.equal((await inspectSyntheticReleaseSignature(
    other,keys.publicKey,now
  )).signatureValid,false);
});

function awaitHash(html) {
  return createHash('sha256').update(html,'utf8').digest('hex');
}

test('expiry, clock skew and malformed envelopes fail closed',async()=>{
  for(const candidate of [
    {...payload,issuedAt:now+31,expiresAt:now+120},
    {...payload,issuedAt:now-300,expiresAt:now-5},
  ]) {
    const signed=await makeEnvelope(candidate);
    assert.equal((await inspectSyntheticReleaseSignature(
      signed,keys.publicKey,now
    )).signatureValid,false);
  }
  for(const patch of [
    {audience:'example.com'},
    {expiresAt:payload.issuedAt+301},
    {nonce:'not-a-v4-uuid'},
    {htmlSha256:'A'.repeat(64)},
    {issuedAt:'1700000000'},
    {extra:'unrecognized'},
  ]) {
    assert.equal(syntheticCanonicalReleaseBytes({...payload,...patch}),null);
  }
  assert.equal((await inspectSyntheticReleaseSignature(
    {...envelope,signature:'invalid'},keys.publicKey,now
  )).signatureValid,false);
  assert.equal((await inspectSyntheticReleaseSignature(
    {...envelope,extra:true},keys.publicKey,now
  )).signatureValid,false);
  assert.equal((await inspectSyntheticReleaseSignature(
    envelope,keys.privateKey,now
  )).signatureValid,false);
});

test('even signed preview refuses real domains and live Worker remains inert',async()=>{
  const otherHost=await renderSignedSyntheticPreview(
    new Request('https://imaginecomunica.com.br/'),
    asset,envelope,keys.publicKey,now
  );
  assert.equal(otherHost.status,404);
  const live=await worker.fetch(
    new Request('https://preview.imagine.invalid/'),
    {APPROVED:'true',RELEASE_SIGNATURE:envelope.signature},{}
  );
  assert.equal(live.status,503);
  assert.match(live.headers.get('Cache-Control'),/no-store/);
});


test('F1-268 refuses alternative base64url text encoding of same Ed25519 bytes',async()=>{
  const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  const finalChar=envelope.signature.at(-1);
  const value=alphabet.indexOf(finalChar);
  assert.ok(value>=0);
  assert.equal(value%16,0,'canonical base64url for 64-byte signature has four zero padding bits');
  const variant={...envelope,signature:
    envelope.signature.slice(0,-1)+alphabet[value+1]
  };
  assert.notEqual(variant.signature,envelope.signature);
  assert.ok(Buffer.from(variant.signature,'base64url').equals(
    Buffer.from(envelope.signature,'base64url')
  ),'decoder accepts same bytes in a noncanonical textual form');
  assert.equal((await inspectSyntheticReleaseSignature(
    variant,keys.publicKey,now
  )).signatureValid,false);
  const page=await renderSignedSyntheticPreview(
    new Request('https://preview.imagine.invalid/'),
    {...asset},variant,keys.publicKey,now
  );
  assert.equal(page.status,503);
});

test('F1-268 expiry is exclusive, even at the exact second of expiration',async()=>{
  const expiring={...payload,issuedAt:now-60,expiresAt:now};
  const signed=await makeEnvelope(expiring);
  assert.equal((await inspectSyntheticReleaseSignature(
    signed,keys.publicKey,now-1
  )).signatureValid,true);
  assert.equal((await inspectSyntheticReleaseSignature(
    signed,keys.publicKey,now
  )).signatureValid,false);
  assert.equal((await inspectSyntheticReleaseSignature(
    signed,keys.publicKey,now+1
  )).signatureValid,false);
});
