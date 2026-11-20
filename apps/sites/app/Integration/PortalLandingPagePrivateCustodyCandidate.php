<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use PDO;
use RuntimeException;
use Throwable;

/**
 * CI-only candidate: stages exact private Landing HTML and its immutable
 * pending-review pointer under a PostgreSQL session_user-bound site admission.
 *
 * No route, controller, scheduled job, active credential or tenant grant uses
 * this adapter. A staged artifact is NOT an approved review or public release.
 */
final readonly class PortalLandingPagePrivateCustodyCandidate
{
    public function __construct(
        private PDO $db,
        private PortalLandingPageEditorialDraftCandidate $draft,
    ) {}

    /** @return array{state:string,reviewSha256:string,artifactSha256:string} */
    public function stage(
        string $operationRef, string $tenantRef, string $orderRef,
        string $siteRef, string $contractRef, int $contractRevision,
        int $sourceVersion, string $approvalRef, int $approvalEpoch,
        string $expectedSourceSha256,
    ): array {
        try {
            if ($this->db->inTransaction()) {
                throw new RuntimeException();
            }
            BriefCandidateJson::reference($operationRef);
            // All selectors must come from an independently authenticated
            // custody context. They are never evidence of authorization.
            $this->db->beginTransaction();
            $before = $this->draft->prepareForInternalReview(
                $tenantRef, $orderRef, $siteRef, $contractRef,
                $contractRevision, $sourceVersion, $approvalRef,
                $approvalEpoch, $expectedSourceSha256,
            );
            $stmt = $this->db->prepare(
                'SELECT sites_landing_custody.record_pending('.
                ':operation, :artifact, :review, :digest)'
            );
            $stmt->execute([
                ':operation' => $operationRef,
                ':artifact' => $before['artifactBytes'],
                ':review' => $before['reviewBytes'],
                ':digest' => $before['reviewSha256'],
            ]);
            if ($stmt->fetchColumn() !== 'pending_private_only') {
                throw new RuntimeException();
            }
            $stmt->closeCursor();

            // Best-effort second Portal read *after* the SQL record and
            // *before* commit. This does not provide distributed atomicity.
            $after = $this->draft->prepareForInternalReview(
                $tenantRef, $orderRef, $siteRef, $contractRef,
                $contractRevision, $sourceVersion, $approvalRef,
                $approvalEpoch, $expectedSourceSha256,
            );
            foreach (['artifactBytes','artifactSha256','reviewBytes','reviewSha256'] as $field) {
                if (!hash_equals($before[$field], $after[$field])) {
                    throw new RuntimeException();
                }
            }
            $this->db->commit();

            return [
                'state' => 'pending_private_only',
                'reviewSha256' => $before['reviewSha256'],
                'artifactSha256' => $before['artifactSha256'],
            ];
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Do not leak customer HTML, selectors, provider errors or SQL.
            throw new RuntimeException('SITES_LANDING_PRIVATE_CUSTODY_DENIED');
        }
    }
}
