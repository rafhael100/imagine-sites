<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-391: verifies candidate Portal client-acceptance evidence without
 * publishing a release, marking a project complete or authorizing a domain.
 *
 * Signed Portal consent is necessary but insufficient: a separate live
 * witness must confirm that consent was not revoked or superseded. The
 * default Laravel preflight and publisher remain independently DENY.
 *
 * NOT bound to HTTP, Laravel, Cloudflare or a production service provider.
 */
final readonly class SignedPortalClientApprovalGate
{
    private const FIELDS = [
        'schemaVersion', 'issuer', 'audience',
        'issuedAt', 'expiresAt', 'acceptedAt',
        'tenantRef', 'siteRef', 'releaseRef', 'artifactDigest',
        'editorialRevision', 'acceptanceRef', 'acceptanceEpoch',
        'actorContext', 'decision', 'signature',
    ];

    public function __construct(
        private PortalClientApprovalReceiptSource $portal,
        private IndependentClientApprovalWitness $witness,
        private string $portalEd25519PublicKeyHex,
        private ?int $fixtureClockEpochSeconds = null,
    ) {}

    public function verify(
        ReleaseManifest $manifest,
        int $editorialRevision,
    ): PolicyDecision {
        if ($editorialRevision < 1) {
            return self::deny('editorial_revision_invalid');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached') ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$this->portalEd25519PublicKeyHex)) {
            return self::deny('portal_approval_key_unconfigured');
        }

        $ciFixture = getenv('GITHUB_ACTIONS') === 'true' &&
            getenv('SITES_F391_APPROVAL_LAB') === '1';
        $now = $ciFixture && $this->fixtureClockEpochSeconds !== null
            ? $this->fixtureClockEpochSeconds : time();
        if ($now < 1) {
            return self::deny('trusted_clock_unavailable');
        }

        try {
            $receipt = $this->portal->fetchCurrentReceipt($manifest,$editorialRevision);
        } catch (\Throwable) {
            return self::deny('portal_client_acceptance_unavailable');
        }
        if (!is_array($receipt) || array_keys($receipt) !== self::FIELDS) {
            return self::deny('portal_client_acceptance_invalid');
        }

        try {
            $raw = json_encode($receipt, JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            return self::deny('portal_client_acceptance_invalid');
        }
        if (!is_string($raw) || strlen($raw) > 4096) {
            return self::deny('portal_client_acceptance_oversized');
        }
        if ($receipt['schemaVersion'] !== 'sites.portal-client-acceptance.ed25519.v1' ||
            $receipt['issuer'] !== 'imagine-portal' ||
            $receipt['audience'] !== 'imagine-sites' ||
            !is_int($receipt['issuedAt']) ||
            !is_int($receipt['expiresAt']) ||
            !is_int($receipt['acceptedAt']) ||
            $receipt['issuedAt'] < $now - 120 ||
            $receipt['issuedAt'] > $now + 5 ||
            $receipt['expiresAt'] <= $now ||
            $receipt['expiresAt'] <= $receipt['issuedAt'] ||
            $receipt['expiresAt'] - $receipt['issuedAt'] > 60 ||
            $receipt['acceptedAt'] < 1 ||
            $receipt['acceptedAt'] > $receipt['issuedAt'] ||
            !is_int($receipt['editorialRevision']) ||
            !is_int($receipt['acceptanceEpoch']) ||
            $receipt['acceptanceEpoch'] < 1 ||
            !is_string($receipt['artifactDigest']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$receipt['artifactDigest']) ||
            !is_string($receipt['acceptanceRef']) ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D',$receipt['acceptanceRef']) ||
            $receipt['actorContext'] !== 'portal_client_authenticated_mfa' ||
            $receipt['decision'] !== 'accepted_explicitly' ||
            !is_string($receipt['signature']) ||
            !preg_match('/\A[a-f0-9]{128}\z/D',$receipt['signature'])) {
            return self::deny('portal_client_acceptance_invalid');
        }

        if ($receipt['tenantRef'] !== $manifest->tenantRef ||
            $receipt['siteRef'] !== $manifest->siteRef ||
            $receipt['releaseRef'] !== $manifest->releaseRef ||
            !hash_equals($manifest->digest,$receipt['artifactDigest']) ||
            $receipt['editorialRevision'] !== $editorialRevision) {
            return self::deny('client_acceptance_release_mismatch');
        }

        try {
            $payload = array_slice($receipt,0,-1,true);
            $bytes = json_encode($payload, JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signature = sodium_hex2bin($receipt['signature']);
            $public = sodium_hex2bin($this->portalEd25519PublicKeyHex);
            if (!sodium_crypto_sign_verify_detached($signature,$bytes,$public)) {
                return self::deny('client_acceptance_signature_invalid');
            }
        } catch (\Throwable) {
            return self::deny('client_acceptance_signature_invalid');
        }

        // Independent, current confirmation on every call: no cache or
        // trust in a stale signed statement after revocation/rework.
        try {
            if (!$this->witness->confirmsCurrent(
                $manifest,$editorialRevision,
                $receipt['acceptanceRef'],$receipt['acceptanceEpoch']
            )) {
                return self::deny('client_acceptance_revoked_or_superseded');
            }
        } catch (\Throwable) {
            return self::deny('client_acceptance_witness_unavailable');
        }

        // Approval evidence only. Callers must separately prove commercial,
        // domain, privacy and security authorization before any publication.
        return new PolicyDecision(true,[]);
    }

    private static function deny(string $reason): PolicyDecision
    {
        return new PolicyDecision(false,[$reason]);
    }
}
