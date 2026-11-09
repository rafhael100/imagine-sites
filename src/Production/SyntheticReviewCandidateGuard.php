<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;

/**
 * F1-31: strict, offline comparison of a handoff candidate against an
 * immutable local F1-28 snapshot. NOT an authentication or authorization
 * verifier and NEVER an official client approval or delivery decision.
 */
final class SyntheticReviewCandidateGuard
{
    /** @param array<string,mixed> $candidate */
    public static function matchesLocalSnapshot(
        array $candidate,
        SyntheticProductionWorkflow $workflow,
        string $releaseRef,
    ): bool {
        if ($workflow->artifactDigest === null) return false;
        try {
            $expected=$workflow->portalReviewCandidate(
                $releaseRef,$workflow->artifactDigest,$workflow->revision
            );
        } catch (DomainException) {
            return false;
        }
        // Exact field set, order, types, opaque references, release and digest
        // must match the locally constructed inert candidate. No extra claims.
        return array_keys($candidate) === array_keys($expected) && $candidate === $expected;
    }
}
