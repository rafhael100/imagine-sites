<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\PolicyDecision;

/**
 * F1-386. Production-shaped *candidate* Portal authority gate.
 *
 * An authentic Portal Ed25519 signature is necessary but INSUFFICIENT.
 * Settlement and revocation must be independently confirmed for every
 * invocation. No Portal, PSP or revocation production adapters exist yet.
 *
 * Does not consume a nonce, write a project or publish. The caller's
 * transaction/outbox still needs separate authenticated, durable processing.
 *
 * No service-provider binding or HTTP route is enabled by this class.
 */
final readonly class IndependentPortalCommercialGateway implements PortalProjectAuthorityGateway
{
    private const ORDERED_KEYS = [
        'schemaVersion','issuer','audience','issuedAt','expiresAt',
        'tenantRef','orderRef','contractRef','contractRevision',
        'productCode','operationFingerprint','settlementRef',
        'settlementSha256','revocationEpoch','signature',
    ];

    public function __construct(
        private PortalEligibilityProofSource $portal,
        private IndependentSettlementWitness $settlement,
        private IndependentRevocationWitness $revocation,
        private string $portalEd25519PublicKeyHex,
        private ?int $clockEpochSeconds = null,
    ) {}

    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        if (!function_exists('sodium_crypto_sign_verify_detached') ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$this->portalEd25519PublicKeyHex)) {
            return self::deny('portal_key_unconfigured');
        }
        $now=$this->clockEpochSeconds ?? time();
        if ($now<=0) return self::deny('trusted_clock_unavailable');

        try {
            // Must be resolved through the trusted source, never from
            // a client-controlled envelope or request parameter.
            $proof=$this->portal->fetchCurrentProof($command);
        } catch (\Throwable) {
            return self::deny('portal_proof_unavailable');
        }
        if (!is_array($proof) || array_keys($proof)!==self::ORDERED_KEYS) {
            return self::deny('portal_proof_invalid');
        }
        try {
            $raw=json_encode($proof,JSON_THROW_ON_ERROR|
                JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            return self::deny('portal_proof_invalid');
        }
        if (!is_string($raw) || strlen($raw)>4096) {
            return self::deny('portal_proof_oversized');
        }
        if ($proof['schemaVersion']!=='sites.portal-entitlement.ed25519.v1' ||
            $proof['issuer']!=='imagine-portal' ||
            $proof['audience']!=='imagine-sites' ||
            !is_int($proof['issuedAt']) ||
            !is_int($proof['expiresAt']) ||
            $proof['issuedAt']>$now+5 ||
            $proof['expiresAt']<=$now ||
            $proof['expiresAt']<=$proof['issuedAt'] ||
            $proof['expiresAt']-$proof['issuedAt']>120 ||
            !is_int($proof['contractRevision']) ||
            !is_int($proof['revocationEpoch']) ||
            $proof['revocationEpoch']<1 ||
            !is_string($proof['operationFingerprint']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$proof['operationFingerprint']) ||
            !is_string($proof['settlementRef']) ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D',$proof['settlementRef']) ||
            !is_string($proof['settlementSha256']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$proof['settlementSha256']) ||
            !is_string($proof['signature']) ||
            !preg_match('/\A[a-f0-9]{128}\z/D',$proof['signature'])) {
            return self::deny('portal_proof_invalid');
        }

        if ($proof['tenantRef']!==$command->tenantRef ||
            $proof['orderRef']!==$command->orderRef ||
            $proof['contractRef']!==$command->contractRef ||
            $proof['contractRevision']!==$command->contractRevision ||
            $proof['productCode']!==$command->productCode ||
            !hash_equals(
                $command->semanticFingerprint(),
                $proof['operationFingerprint']
            )) {
            return self::deny('portal_contract_mismatch');
        }

        $payload=array_slice($proof,0,-1,true);
        try {
            $bytes=json_encode($payload,JSON_THROW_ON_ERROR|
                JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $signature=sodium_hex2bin($proof['signature']);
            $pubKey=sodium_hex2bin($this->portalEd25519PublicKeyHex);
            if (!sodium_crypto_sign_verify_detached(
                $signature,$bytes,$pubKey
            )) {
                return self::deny('portal_signature_invalid');
            }
        } catch (\Throwable) {
            return self::deny('portal_signature_invalid');
        }

        // Independent checks must run AFTER signature validation, every
        // time, without trusting the signed "settled" assertion.
        try {
            if (!$this->settlement->confirmsSettled(
                $command,$proof['settlementRef'],$proof['settlementSha256']
            )) {
                return self::deny('independent_settlement_missing');
            }
        } catch (\Throwable) {
            return self::deny('independent_settlement_unavailable');
        }
        try {
            if (!$this->revocation->isCurrent(
                $command,$proof['revocationEpoch']
            )) {
                return self::deny('independent_revocation_unconfirmed');
            }
        } catch (\Throwable) {
            return self::deny('independent_revocation_unavailable');
        }

        return new PolicyDecision(true,[]);
    }

    private static function deny(string $code): PolicyDecision
    {
        // No contract IDs, personal data, secret key, signature or PSP
        // receipt is surfaced in the policy result.
        return new PolicyDecision(false,[$code]);
    }
}
