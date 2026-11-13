<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use Imagine\Sites\Application\DenyingReviewHandoffAuthorityGateway;
use Imagine\Sites\Application\ReviewHandoffAuthorityGateway;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Production\SyntheticReviewCandidateGuard;
use InvalidArgumentException;

/**
 * F1-45: an OFFLINE signed proposal, not an official Portal acceptance.
 * HMAC is a lab interoperability primitive, NOT a production identity system.
 *
 * A valid signature NEVER grants contract, client, publication or delivery
 * authority. Verification denies unless a separately injected current-authority
 * gateway and a replay fence both admit the exact current revision and digest.
 */
final class SyntheticReviewHandoffEnvelope
{
    private const FORMAT = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const FIELDS = [
        'schemaVersion','issuer','audience','issuedAt','expiresAt','nonce','candidate','mac',
    ];

    /** @return array<string,mixed> */
    public static function sign(
        SyntheticProductionWorkflow $workflow,
        string $releaseRef,
        string $key,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
    ): array {
        self::requireLabKey($key);
        self::validateTimes($issuedAt,$expiresAt,$issuedAt);
        self::validateNonce($nonce);
        if ($workflow->artifactDigest === null) {
            throw new DomainException('No exact artifact to offer for review.');
        }
        $candidate = $workflow->portalReviewCandidate(
            $releaseRef,$workflow->artifactDigest,$workflow->revision
        );
        if (!SyntheticReviewCandidateGuard::matchesLocalSnapshot(
            $candidate,$workflow,$releaseRef
        )) {
            throw new DomainException('No exact review candidate.');
        }
        $unsigned = [
            'schemaVersion'=>'sites.synthetic.review-handoff.v1',
            'issuer'=>'imagine-sites',
            'audience'=>'imagine-portal',
            'issuedAt'=>$issuedAt,
            'expiresAt'=>$expiresAt,
            'nonce'=>$nonce,
            'candidate'=>$candidate,
        ];
        return $unsigned+['mac'=>hash_hmac('sha256',self::serialize($unsigned),$key)];
    }

    /**
     * @param array<string,mixed> $envelope
     * @return array<string,string|int|bool> exact non-authoritative candidate
     */
    public static function verify(
        array $envelope,
        SyntheticProductionWorkflow $currentWorkflow,
        string $expectedReleaseRef,
        string $key,
        int $now,
        ?ReplayStore $replayStore = null,
        ?ReviewHandoffAuthorityGateway $authority = null,
    ): array {
        self::requireLabKey($key);
        if (array_keys($envelope)!==self::FIELDS ||
            $envelope['schemaVersion']!=='sites.synthetic.review-handoff.v1' ||
            $envelope['issuer']!=='imagine-sites' ||
            $envelope['audience']!=='imagine-portal' ||
            !is_int($envelope['issuedAt']) ||
            !is_int($envelope['expiresAt']) ||
            !is_string($envelope['nonce']) ||
            !is_array($envelope['candidate']) ||
            !is_string($envelope['mac']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$envelope['mac'])) {
            throw new DomainException('Invalid synthetic handoff envelope.');
        }
        self::validateNonce($envelope['nonce']);
        self::validateTimes($envelope['issuedAt'],$envelope['expiresAt'],$now);

        // Compare canonical signed bytes first: no untrusted claims are
        // projected into the Portal candidate from a request body alone.
        $unsigned=array_slice($envelope,0,-1,true);
        if (!hash_equals(
            hash_hmac('sha256',self::serialize($unsigned),$key),
            $envelope['mac']
        )) {
            throw new DomainException('Synthetic handoff signature mismatch.');
        }

        $candidate=$envelope['candidate'];
        if (!SyntheticReviewCandidateGuard::matchesLocalSnapshot(
            $candidate,$currentWorkflow,$expectedReleaseRef
        )) {
            throw new DomainException('Stale or mismatched handoff candidate.');
        }
        // Default DENY; this contract alone cannot authenticate a caller,
        // establish order ownership or check a real Portal entitlement.
        $authority ??= new DenyingReviewHandoffAuthorityGateway();
        if (!$authority->isCurrentlyAuthorized($currentWorkflow,$candidate)) {
            throw new DomainException('Current Portal review authority unavailable.');
        }

        // Never spend nonce on invalid signature/old revision/revoked authority.
        $replayStore ??= new DenyingReplayStore();
        if (!$replayStore->rememberOnce('imagine-sites:review-handoff.v1',
            $envelope['nonce'],$envelope['expiresAt'])) {
            throw new DomainException('Synthetic handoff replay denied.');
        }
        return $candidate;
    }

    private static function validateNonce(string $nonce): void
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,63}\z/D',$nonce)) {
            throw new DomainException('Invalid synthetic handoff nonce.');
        }
    }

    private static function validateTimes(int $issued,int $expires,int $now): void
    {
        if ($issued < 1 || $expires <= $issued ||
            $expires-$issued > 120 || $issued > $now+5 ||
            $expires <= $now) {
            throw new DomainException('Synthetic handoff is expired or premature.');
        }
    }

    private static function requireLabKey(string $key): void
    {
        if (strlen($key)<32) {
            throw new InvalidArgumentException('Synthetic handoff key must contain at least 32 bytes.');
        }
    }

    /** @param array<string,mixed> $value */
    private static function serialize(array $value): string
    {
        return json_encode($value,self::FORMAT);
    }
}
