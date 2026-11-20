<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only, CI-only witness for a private review observation.
 * It does not certify a human, approve the artifact, or authorize any gate.
 */
final readonly class PortalLandingPageReviewReadbackCandidate
{
    public function __construct(
        private PDO $readerConnection,
        private PortalLandingPageEditorialDraftCandidate $draft,
    ) {}

    /**
     * Caller-provided tenant selectors are NOT trusted for SQL admission:
     * the PG function takes tenant exclusively from session_user.
     *
     * @return array{state:string,decision:string,reasonCode:string,operationRef:string,reviewSha256:string}
     */
    public function readCurrent(
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
    ): array {
        try {
            if ($this->readerConnection->inTransaction() ||
                !preg_match('/^[a-f0-9]{64}$/D', $expectedReviewSha256)) {
                throw new RuntimeException();
            }
            $this->readerConnection->beginTransaction();
            // SQL is read-only in effect, but needs row-level FOR SHARE locks
            // to fence concurrent revocation until commit; PostgreSQL prohibits
            // such locks in a READ ONLY transaction. Role has no table writes.

            $before = $this->draft->prepareForInternalReview(
                $tenantRef, $orderRef, $siteRef, $contractRef,
                $contractRevision, $sourceVersion, $approvalRef,
                $approvalEpoch, $expectedSourceSha256,
            );
            if (!hash_equals($expectedReviewSha256, $before['reviewSha256'])) {
                throw new RuntimeException();
            }

            $query = $this->readerConnection->prepare(
                'SELECT * FROM sites_landing_custody.read_current_review_observation(:site,:review)'
            );
            $query->execute([':site' => $siteRef, ':review' => $expectedReviewSha256]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $query->closeCursor();
            if (!is_array($row) ||
                !in_array($row['decision'] ?? null, ['observations_recorded', 'changes_requested'], true) ||
                !in_array($row['reason_code'] ?? null, ['content', 'identity', 'other'], true) ||
                !is_string($row['operation_ref'] ?? null) ||
                !is_string($row['observed_by'] ?? null) ||
                $row['observed_by'] === '' ||
                (string)($row['source_version'] ?? '') !== (string)$sourceVersion ||
                (string)($row['approval_epoch'] ?? '') !== (string)$approvalEpoch ||
                !hash_equals($expectedReviewSha256, (string)($row['review_sha256'] ?? '')) ||
                !hash_equals($before['artifactSha256'], (string)($row['artifact_sha256'] ?? ''))) {
                throw new RuntimeException();
            }
            BriefCandidateJson::reference($row['operation_ref']);

            // Cross-service readbacks are not atomic with this database read.
            // Observe drift before returning; later publication must recheck.
            $after = $this->draft->prepareForInternalReview(
                $tenantRef, $orderRef, $siteRef, $contractRef,
                $contractRevision, $sourceVersion, $approvalRef,
                $approvalEpoch, $expectedSourceSha256,
            );
            foreach (['artifactBytes', 'artifactSha256', 'reviewBytes', 'reviewSha256'] as $key) {
                if (!hash_equals($before[$key], $after[$key])) {
                    throw new RuntimeException();
                }
            }
            $this->readerConnection->commit();
            return [
                'state' => 'review_observation_private_only',
                'decision' => $row['decision'],
                'reasonCode' => $row['reason_code'],
                'operationRef' => $row['operation_ref'],
                'reviewSha256' => $before['reviewSha256'],
            ];
        } catch (Throwable) {
            if ($this->readerConnection->inTransaction()) {
                $this->readerConnection->rollBack();
            }
            throw new RuntimeException('SITES_LANDING_REVIEW_READBACK_DENIED');
        }
    }
}
