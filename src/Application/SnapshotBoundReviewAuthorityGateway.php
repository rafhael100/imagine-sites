<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Throwable;

/**
 * F1-48: strict compare against an INDEPENDENT current snapshot, not claims
 * from the review envelope. Source is DENY by default. Laboratory only:
 * this is not a production identity or cryptographic authority verifier.
 */
final class SnapshotBoundReviewAuthorityGateway implements ReviewHandoffAuthorityGateway
{
    public function __construct(
        private readonly CurrentPortalReviewSnapshotSource $source =
            new DenyingPortalReviewSnapshotSource(),
    ) {}

    /** @param array<string,string|int|bool> $candidate */
    public function isCurrentlyAuthorized(
        SyntheticProductionWorkflow $workflow,
        array $candidate,
    ): bool {
        if ($workflow->state!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $workflow->artifactDigest===null ||
            ($candidate['portalAuthorityRequired']??null)!==true ||
            ($candidate['officialClientAcceptance']??null)!==false ||
            ($candidate['publishable']??null)!==false) {
            return false;
        }

        try {
            // The source MUST use independently authenticated Portal tenancy.
            // Never fetch "authority=true" from the incoming request body.
            $snapshot=$this->source->findCurrentFor($workflow->tenantRef,$workflow->siteRef);
            if (!$snapshot instanceof CurrentPortalReviewSnapshot) return false;

            $now=time();  // server-side clock, not an envelope or user-supplied claim
            if ($snapshot->status!=='reviewable' ||
                $snapshot->checkedAt>$now+5 ||
                $snapshot->checkedAt<$now-30 ||
                $snapshot->validUntil<=$now ||
                $snapshot->workflowRevision!==$workflow->revision ||
                $snapshot->authorizationRevision<1 ||
                $candidate['workflowRevision']!==$snapshot->workflowRevision) {
                return false;
            }
            foreach ([
                ['tenantRef',$snapshot->tenantRef,$workflow->tenantRef],
                ['siteRef',$snapshot->siteRef,$workflow->siteRef],
                ['workflowRef',$snapshot->workflowRef,$workflow->workflowRef],
                ['releaseRef',$snapshot->releaseRef,$candidate['releaseRef']],
            ] as [$field,$authoritative,$expected]) {
                if (!isset($candidate[$field]) ||
                    !is_string($candidate[$field]) ||
                    !hash_equals($authoritative,$expected) ||
                    ($field!=='releaseRef' &&
                     !hash_equals($authoritative,$candidate[$field]))) {
                    return false;
                }
            }
            return hash_equals($snapshot->artifactDigest,$workflow->artifactDigest) &&
                isset($candidate['artifactDigest']) &&
                is_string($candidate['artifactDigest']) &&
                hash_equals($snapshot->artifactDigest,$candidate['artifactDigest']);
        } catch (Throwable) {
            // A missing/unavailable/untrusted authority source never permits
            // a delivery or even the informational review handoff.
            return false;
        }
    }
}
