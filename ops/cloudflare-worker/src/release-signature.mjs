/**
 * F1-265: offline Ed25519 signature inspector for synthetic edge previews.
 *
 * A valid signature only proves that supplied bytes match the supplied
 * verification key. It DOES NOT authenticate the key's administrator, prove
 * Portal consent/acceptance, prevent replay, or authorize publication.
 *
 * No network, persistence, signing secrets, Cloudflare binding or live route.
 */
const encoder=new TextEncoder();
const FIELDS=[
  'schema','audience','scopeCorrelationSha256','releaseRefSha256',
  'htmlSha256','issuedAt','expiresAt','nonce',
];
const SHA=/^[0-9a-f]{64}$/;
const NONCE=/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

function exactKeys(record,fields) {
  if(!record || Object.getPrototypeOf(record)!==Object.prototype) return false;
  const actual=Object.keys(record).sort();
  return actual.length===fields.length &&
    actual.every((key,index)=>key===[...fields].sort()[index]);
}

export function syntheticCanonicalReleaseBytes(payload) {
  if(!exactKeys(payload,FIELDS) ||
     payload.schema!=='sites.synthetic.edge-signature.v1' ||
     payload.audience!=='preview.imagine.invalid' ||
     !SHA.test(payload.scopeCorrelationSha256) ||
     !SHA.test(payload.releaseRefSha256) ||
     !SHA.test(payload.htmlSha256) ||
     !NONCE.test(payload.nonce) ||
     !Number.isSafeInteger(payload.issuedAt) ||
     !Number.isSafeInteger(payload.expiresAt) ||
     payload.issuedAt<=0 ||
     payload.expiresAt<=payload.issuedAt ||
     payload.expiresAt-payload.issuedAt>300) {
    return null;
  }
  // Newline canonicalization is unambiguous: every non-numeric field is a
  // strict fixed token or lowercase hexadecimal string. No URL/PII/copy.
  return encoder.encode(FIELDS.map(key=>String(payload[key])).join('\n'));
}

const deny=()=>Object.freeze({
  reasonCode:'UNVERIFIED_EDGE_ENVELOPE',
  signatureValid:false,
  canPublish:false,
  canServePublicHost:false,
  canApproveForClient:false,
});

export async function inspectSyntheticReleaseSignature(
  envelope,publicKey,nowSeconds
) {
  if(!exactKeys(envelope,['payload','signature']) ||
     !Number.isSafeInteger(nowSeconds) ||
     typeof envelope.signature!=='string' ||
     !/^[A-Za-z0-9_-]{86}$/.test(envelope.signature)) {
    return deny();
  }
  const payload=envelope.payload;
  const bytes=syntheticCanonicalReleaseBytes(payload);
  if(bytes===null ||
     payload.issuedAt>nowSeconds+30 ||
     payload.expiresAt<=nowSeconds ||
     publicKey?.type!=='public' ||
     publicKey.algorithm?.name!=='Ed25519' ||
     !publicKey.usages?.includes('verify')) {
    return deny();
  }
  let signature;
  try {
    const b64=envelope.signature.replaceAll('-','+').replaceAll('_','/')+'==';
    const raw=atob(b64);
    signature=Uint8Array.from(raw,c=>c.charCodeAt(0));
    if(signature.length!==64) return deny();
    // F1-268: base64url has unused padding bits for 64-byte signatures.
    // Reject alternate textual encodings of identical signature bytes:
    // audit trails must have a unique, canonical representation.
    const canonical=btoa(raw).replaceAll('+','-').replaceAll('/','_')
      .replace(/=+$/,'');
    if(canonical!==envelope.signature) return deny();
    if(!await crypto.subtle.verify('Ed25519',publicKey,signature,bytes)) {
      return deny();
    }
  } catch {
    return deny();
  }
  return Object.freeze({
    reasonCode:'SIGNATURE_MATCH_UNAUTHORIZED',
    signatureValid:true,
    canPublish:false,
    canServePublicHost:false,
    canApproveForClient:false,
  });
}
