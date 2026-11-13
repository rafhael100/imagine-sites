<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use Imagine\Sites\Application\CurrentPortalReviewSnapshot;
use Imagine\Sites\Application\CurrentPortalReviewSnapshotSource;
use Imagine\Sites\Application\DenyingPortalReviewSnapshotSource;
use Imagine\Sites\Application\SnapshotReviewBindingPolicy;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Production\SyntheticReviewCandidateGuard;
use InvalidArgumentException;
use Throwable;

/**
 * F1-50: epoch-bound, OFFLINE signed review PROPOSAL (NOT approval).
 * Version v2 adds signed authorizationRevision, so an old signature cannot
 * be revived after Portal revokes and reissues authorization under a new epoch.
 *
 * Signer is a fixture; verification requires an independent current source
 * and a durable replay fence. Both deny by default. Not production IAM.
 */
final class SyntheticReviewHandoffEpochEnvelope
{
    private const FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
    private const FIELDS=[
        'schemaVersion','issuer','audience','issuedAt','expiresAt','nonce',
        'authorizationRevision','candidate','mac'
    ];

    /** @return array<string,mixed> */
    public static function sign(
        SyntheticProductionWorkflow $workflow,
        string $releaseRef,
        int $authorizationRevision,
        string $key,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
    ):array {
        self::validateKey($key);
        self::validateTimes($issuedAt,$expiresAt,$issuedAt);
        self::validateNonce($nonce);
        if ($authorizationRevision<1 || $workflow->artifactDigest===null) {
            throw new InvalidArgumentException('Invalid review authority revision.');
        }
        $candidate=$workflow->portalReviewCandidate(
            $releaseRef,$workflow->artifactDigest,$workflow->revision
        );
        if (!SyntheticReviewCandidateGuard::matchesLocalSnapshot(
            $candidate,$workflow,$releaseRef
        )) throw new DomainException('Review candidate mismatch.');

        $unsigned=[
            'schemaVersion'=>'sites.synthetic.review-handoff.v2',
            'issuer'=>'imagine-sites',
            'audience'=>'imagine-portal',
            'issuedAt'=>$issuedAt,
            'expiresAt'=>$expiresAt,
            'nonce'=>$nonce,
            'authorizationRevision'=>$authorizationRevision,
            'candidate'=>$candidate,
        ];
        return $unsigned+['mac'=>hash_hmac('sha256',self::encode($unsigned),$key)];
    }

    /**
     * @param array<string,mixed> $envelope
     * @return array<string,string|int|bool>
     */
    public static function verify(
        array $envelope,
        SyntheticProductionWorkflow $workflow,
        string $releaseRef,
        string $key,
        int $now,
        ?ReplayStore $replay=null,
        ?CurrentPortalReviewSnapshotSource $source=null,
    ):array {
        self::validateKey($key);
        if (array_keys($envelope)!==self::FIELDS ||
            $envelope['schemaVersion']!=='sites.synthetic.review-handoff.v2' ||
            $envelope['issuer']!=='imagine-sites' ||
            $envelope['audience']!=='imagine-portal' ||
            !is_int($envelope['issuedAt']) ||
            !is_int($envelope['expiresAt']) ||
            !is_string($envelope['nonce']) ||
            !is_int($envelope['authorizationRevision']) ||
            $envelope['authorizationRevision']<1 ||
            !is_array($envelope['candidate']) ||
            !is_string($envelope['mac']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$envelope['mac'])) {
            throw new DomainException('Malformed review-handoff v2 envelope.');
        }
        self::validateTimes($envelope['issuedAt'],$envelope['expiresAt'],$now);
        self::validateNonce($envelope['nonce']);
        if (!hash_equals(
            hash_hmac('sha256',self::encode(array_slice($envelope,0,-1,true)),$key),
            $envelope['mac']
        )) throw new DomainException('Invalid review-handoff v2 MAC.');
        $candidate=$envelope['candidate'];
        if (!SyntheticReviewCandidateGuard::matchesLocalSnapshot(
            $candidate,$workflow,$releaseRef
        )) throw new DomainException('Mismatched release, bytes or workflow revision.');

        $source ??= new DenyingPortalReviewSnapshotSource();
        try {
            $snapshot=$source->findCurrentFor($workflow->tenantRef,$workflow->siteRef);
            if (!$snapshot instanceof CurrentPortalReviewSnapshot ||
                !// Use the SAME trusted verifier clock already used for issued/expiry
                // checks. Never accept this clock from an HTTP request.
                SnapshotReviewBindingPolicy::allows($workflow,$candidate,$snapshot,$now) ||
                $snapshot->authorizationRevision!==$envelope['authorizationRevision']) {
                throw new DomainException('Current authority epoch denied.');
            }
        } catch (Throwable) {
            throw new DomainException('Current authority source denied.');
        }

        // Reject until an explicitly injected durable v2 replay adapter exists.
        $replay ??=new DenyingReplayStore();
        if (!$replay->rememberOnce('imagine-sites:review-handoff.v2',
            $envelope['nonce'],$envelope['expiresAt'])) {
            throw new DomainException('v2 replay or missing durable nonce fence.');
        }
        return $candidate; // informational; cannot approve or publish
    }

    private static function validateKey(string $key):void {
        if(strlen($key)<32)throw new InvalidArgumentException('Synthetic key too short.');
    }
    private static function validateTimes(int $issued,int $expires,int $now):void {
        if($issued<1 || $expires<=$issued || $expires-$issued>120 ||
            $issued>$now+5 || $expires<=$now) {
            throw new DomainException('Expired, future or excessive review-handoff TTL.');
        }
    }
    private static function validateNonce(string $nonce):void {
        if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,63}\z/D',$nonce)) {
            throw new DomainException('Invalid v2 nonce.');
        }
    }
    /** @param array<string,mixed> $value */
    private static function encode(array $value):string {
        return json_encode($value,self::FLAGS);
    }
}
