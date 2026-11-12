/**
 * F1-269: nonce replay experiment in one ephemeral Worker/test isolate.
 *
 * NOT a durable, concurrent, globally consistent KV/DO/SQL ledger. It cannot
 * confer authorization, record consent, protect production replays or publish.
 * A separate authenticated durable service must replace it for real use.
 */
import {
  syntheticCanonicalReleaseBytes,
  inspectSyntheticReleaseSignature,
} from './release-signature.mjs';

const sha=/^[a-f0-9]{64}$/;
const denial=Object.freeze({
  reasonCode:'REPLAY_OR_AUTHORITY_UNVERIFIED',
  signatureValid:false,
  syntheticReservation:false,
  canPublish:false,
  canServePublicHost:false,
  canAcknowledgeVisitor:false,
});

/** Instance-local bounded fixture, intentionally non-durable and not exported as a Worker binding. */
export class EphemeralSyntheticNonceLedger {
  #claims=new Map();

  /**
   * Returns true exactly once for a still-valid digest/expiry inside a
   * single JS execution context. Calls are synchronous: no async TOCTOU.
   */
  reserveOnce(keyDigest,nowSeconds,expiresAt) {
    if(typeof keyDigest!=='string' || !sha.test(keyDigest) ||
       !Number.isSafeInteger(nowSeconds) ||
       !Number.isSafeInteger(expiresAt) ||
       expiresAt<=nowSeconds ||
       expiresAt-nowSeconds>300) return false;

    // Never discard unexpired nonces for capacity. Deny at saturation.
    for(const [key,deadline] of this.#claims) {
      if(deadline<=nowSeconds) this.#claims.delete(key);
    }
    if(this.#claims.has(keyDigest) || this.#claims.size>=256) return false;
    this.#claims.set(keyDigest,expiresAt);
    return true;
  }
}

/**
 * Verify cryptography first, then reserve a keyed, fixed-scope nonce.
 * Source identity and durability are unverified: success is advisory only.
 */
export async function inspectSyntheticNonceReplay(
  envelope,publicKey,nowSeconds,ledger
) {
  const verified=await inspectSyntheticReleaseSignature(
    envelope,publicKey,nowSeconds
  );
  if(!verified.signatureValid ||
     !(ledger instanceof EphemeralSyntheticNonceLedger) ||
     syntheticCanonicalReleaseBytes(envelope?.payload)===null) return denial;

  const p=envelope.payload;
  const bytes=new TextEncoder().encode([
    'sites.synthetic.nonce-fence.v1',
    p.scopeCorrelationSha256,
    p.releaseRefSha256,
    p.htmlSha256,
    p.nonce,
  ].join('\n'));
  const digest=Array.from(
    new Uint8Array(await crypto.subtle.digest('SHA-256',bytes)),
    b=>b.toString(16).padStart(2,'0')
  ).join('');
  if(!ledger.reserveOnce(digest,nowSeconds,p.expiresAt)) return denial;

  return Object.freeze({
    reasonCode:'SYNTHETIC_NONCE_RESERVED_UNTRUSTED',
    signatureValid:true,
    syntheticReservation:true,
    canPublish:false,
    canServePublicHost:false,
    canAcknowledgeVisitor:false,
  });
}
