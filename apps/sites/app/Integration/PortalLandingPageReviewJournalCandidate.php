<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use PDO;
use RuntimeException;
use Throwable;

/**
 * CI-only candidate: audit a distinct reviewer's observation about the exact
 * private draft. This NEVER approves release, client acceptance or billing.
 * No controller, authenticated Portal identity or live routing is wired.
 */
final readonly class PortalLandingPageReviewJournalCandidate
{
    public function __construct(
        private PDO $reviewerConnection,
        private PortalLandingPageEditorialDraftCandidate $draft,
    ) {}

    /**
     * All expected selectors must come from independently authorized custody.
     * SQL uses the DB session_user for the reviewer, NOT a caller-provided actor.
     *
     * @return array{state:string,reviewSha256:string}
     */
    public function observe(
        string $operationRef,
        string $tenantRef,
        string $orderRef,
        string $siteRef,
        string $contractRef,
        int $contractRevision,
        int $sourceVersion,
        string $approvalRef,
        int $approvalEpoch,
        string $expectedSourceSha256,
        string $expectedReviewSha256,
        string $decision,
        string $reasonCode,
    ): array {
        try {
            if ($this->reviewerConnection->inTransaction()) {
                throw new RuntimeException();
            }
            BriefCandidateJson::reference($operationRef);
            if (!in_array($decision, ['observations_recorded', 'changes_requested'], true) ||
                !in_array($reasonCode, ['content', 'identity', 'other'], true) ||
                !preg_match('/^[a-f0-9]{64}$/D', $expectedReviewSha256)) {
                throw new RuntimeException();
            }
            $this->reviewerConnection->beginTransaction();
            $before = $this->draft->prepareForInternalReview(
                $tenantRef, $orderRef, $siteRef, $contractRef,
                $contractRevision, $sourceVersion, $approvalRef,
                $approvalEpoch, $expectedSourceSha256,
            );
            if (!hash_equals($expectedReviewSha256, $before['reviewSha256'])) {
                throw new RuntimeException();
            }
            $stmt = $this->reviewerConnection->prepare(
                'SELECT sites_landing_custody.record_review_observation('.
                ':operation,:site,:sha,:decision,:reason)'
            );
            $stmt->execute([
                ':operation' => $operationRef,
                ':site' => $siteRef,
                ':sha' => $expectedReviewSha256,
                ':decision' => $decision,
                ':reason' => $reasonCode,
            ]);
            if ($stmt->fetchColumn() !== 'observation_private_only') {
                throw new RuntimeException();
            }
            $stmt->closeCursor();

            // Detect drift observed before commit. This is not an atomic
            // distributed authorization: the Portal may revoke afterwards.
            $after = $this->draft->prepareForInternalReview(
                $tenantRef, $orderRef, $siteRef, $contractRef,
                $contractRevision, $sourceVersion, $approvalRef,
                $approvalEpoch, $expectedSourceSha256,
            );
            foreach (['artifactBytes','artifactSha256','reviewBytes','reviewSha256'] as $key) {
                if (!hash_equals($before[$key], $after[$key])) {
                    throw new RuntimeException();
                }
            }
            $this->reviewerConnection->commit();
            return ['state' => 'observation_private_only',
                'reviewSha256' => $before['reviewSha256']];
        } catch (Throwable) {
            if ($this->reviewerConnection->inTransaction()) {
                $this->reviewerConnection->rollBack();
            }
            throw new RuntimeException('SITES_LANDING_REVIEW_JOURNAL_DENIED');
        }
    }
}
