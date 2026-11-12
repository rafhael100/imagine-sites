<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use Imagine\Sites\Production\SyntheticProductionWorkflow;

/** F1-50: strict, side-effect-free binding to ONE independently read snapshot.
 * This policy is not a trust source, authentication system or Portal approval.
 */
final class SnapshotReviewBindingPolicy
{
    /** @param array<string,mixed> $candidate */
    public static function allows(
        SyntheticProductionWorkflow $workflow,
        array $candidate,
        CurrentPortalReviewSnapshot $snapshot,
        int $now,
    ):bool {
        if ($workflow->state!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $workflow->artifactDigest===null ||
            ($candidate['portalAuthorityRequired']??null)!==true ||
            ($candidate['officialClientAcceptance']??null)!==false ||
            ($candidate['publishable']??null)!==false ||
            $snapshot->status!=='reviewable' ||
            $snapshot->checkedAt>$now+5 ||
            $snapshot->checkedAt<$now-30 ||
            $snapshot->validUntil<=$now ||
            $snapshot->workflowRevision!==$workflow->revision ||
            ($candidate['workflowRevision']??null)!==$snapshot->workflowRevision) {
            return false;
        }
        foreach ([
            ['tenantRef',$workflow->tenantRef,$snapshot->tenantRef],
            ['siteRef',$workflow->siteRef,$snapshot->siteRef],
            ['workflowRef',$workflow->workflowRef,$snapshot->workflowRef],
        ] as [$field,$expected,$authoritative]) {
            if (!is_string($candidate[$field]??null) ||
                !hash_equals($expected,$authoritative) ||
                !hash_equals($authoritative,$candidate[$field])) return false;
        }
        if (!is_string($candidate['releaseRef']??null) ||
            !hash_equals($snapshot->releaseRef,$candidate['releaseRef']) ||
            !is_string($candidate['artifactDigest']??null)) return false;
        return hash_equals($snapshot->artifactDigest,$workflow->artifactDigest) &&
            hash_equals($snapshot->artifactDigest,$candidate['artifactDigest']);
    }
}
