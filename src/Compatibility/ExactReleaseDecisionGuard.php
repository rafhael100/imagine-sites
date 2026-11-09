<?php

declare(strict_types=1);

namespace Imagine\Sites\Compatibility;

/**
 * Safety delta from legacy Portal Node/SQLite:
 * comparing numeric release only was insufficient; bind acceptance to
 * immutable digest and verified official Portal identity/authority.
 *
 * This is a pure non-operational gate. Inputs marked 'official' can ONLY
 * originate from a future trusted server-to-server verifier, never a client.
 */
final class ExactReleaseDecisionGuard
{
    /**
     * @return list<string> Reasons that deny a client decision
     */
    public function decisionBlockers(
        string $productionStage,
        string $actorRole,
        bool $isOrderOwner,
        bool $officialActorAuthorityCurrent,
        int $expectedVersion,
        int $currentVersion,
        string $requestedReleaseRef,
        string $currentReleaseRef,
        string $requestedDigest,
        string $currentDigest,
        bool $expressConfirmation,
        string $action,
        string $note,
    ): array {
        $errors = [];
        if (!in_array($action, ['approve', 'request-changes'], true)) {
            $errors[] = 'unsupported_client_decision';
        }
        if ($actorRole !== 'client' || !$isOrderOwner || !$officialActorAuthorityCurrent) {
            $errors[] = 'official_owner_authority_missing';
        }
        if ($productionStage !== 'review') $errors[] = 'production_not_in_review';
        if ($expectedVersion < 0 || $expectedVersion !== $currentVersion) {
            $errors[] = 'stale_production_version';
        }
        if ($requestedReleaseRef === '' || !hash_equals($currentReleaseRef, $requestedReleaseRef)) {
            $errors[] = 'release_reference_mismatch';
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $requestedDigest) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $currentDigest) ||
            !hash_equals($currentDigest, $requestedDigest)) {
            $errors[] = 'approved_bytes_digest_mismatch';
        }
        if (!$expressConfirmation) $errors[] = 'express_confirmation_missing';
        if (strlen($note) > 4000) $errors[] = 'decision_note_too_long';
        if ($action === 'request-changes' && strlen(trim($note)) < 10) {
            $errors[] = 'changes_note_required';
        }
        return $errors;
    }

    /**
     * This is ONLY a technical review precondition, not publication itself.
     *
     * @return list<string>
     */
    public function publicationBlockers(
        string $stage,
        string $approvedReleaseRef,
        string $requestedReleaseRef,
        string $approvedDigest,
        string $requestedDigest,
        bool $currentPortalAuthorityVerified,
        bool $independentOriginVersionVerified,
    ): array {
        $errors = [];
        if ($stage !== 'approved') $errors[] = 'release_not_approved';
        if ($approvedReleaseRef === '' || !hash_equals($approvedReleaseRef, $requestedReleaseRef)) {
            $errors[] = 'approved_release_ref_mismatch';
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $approvedDigest) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $requestedDigest) ||
            !hash_equals($approvedDigest, $requestedDigest)) {
            $errors[] = 'approved_digest_mismatch';
        }
        if (!$currentPortalAuthorityVerified) $errors[] = 'authoritative_portal_denied';
        if (!$independentOriginVersionVerified) $errors[] = 'origin_proof_absent';
        return $errors;
    }
}
