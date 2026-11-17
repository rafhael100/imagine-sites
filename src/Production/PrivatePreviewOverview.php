<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only queue for the private demonstration workspace.
 *
 * Editorial status describes the exact latest persisted revision in the read
 * snapshot. It is never client acceptance, commercial authority or permission
 * to publish. A new revision cannot inherit an earlier revision's review.
 */
final readonly class PrivatePreviewOverview
{
    public function __construct(private PDO $db) {}

    /**
     * The company comes from PreviewPrincipal, not browser input. RLS and the
     * active SQL login binding remain authoritative even with this predicate.
     *
     * @return list<array{
     *   id:string,product:string,latest_revision:int,latest_created_at:string,
     *   sha256:string,editorial_status:string
     * }>
     */
    public function projects(string $company): array
    {
        if (preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $company) !== 1) {
            throw new RuntimeException('Private preview overview unavailable');
        }

        try {
            $workspace = new PrivatePreviewWorkspace($this->db);
            $workspace->assertSafeRuntime();
            $this->requireReadable($company);

            // One statement takes one coherent READ COMMITTED snapshot. Both
            // lateral reads retain company and project predicates, in addition
            // to FORCE RLS. Only the latest revision and its exact digest count.
            $query = $this->db->prepare(<<<'SQL'
SELECT p.id,p.product,v.revision AS latest_revision,
       v.created_at AS latest_created_at,v.sha256,
       CASE WHEN coalesce(r.changes_requested,false) THEN 'changes_requested'
            WHEN coalesce(r.approved_internal,false) THEN 'approved_internal'
            ELSE 'awaiting_review' END AS editorial_status
  FROM sites_preview.projects p
  JOIN LATERAL (
    SELECT pv.revision,pv.created_at,pv.sha256
      FROM sites_preview.versions pv
     WHERE pv.project_id=p.id AND pv.company_id=p.company_id
     ORDER BY pv.revision DESC
     LIMIT 1
  ) v ON true
  LEFT JOIN LATERAL (
    SELECT bool_or(pr.decision='changes_requested') AS changes_requested,
           bool_or(pr.decision='approved_internal') AS approved_internal
      FROM sites_preview.reviews pr
     WHERE pr.project_id=p.id AND pr.company_id=p.company_id
       AND pr.revision=v.revision AND pr.sha256=v.sha256
  ) r ON true
 WHERE p.company_id=?::uuid AND p.purpose='demonstration_only'
 ORDER BY v.created_at DESC,p.id
 LIMIT 50
SQL);
            $query->execute([$company]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);

            // Refuse to disclose a result if the binding or runtime privileges
            // changed during the read. No data or cached permission is reused.
            $workspace->assertSafeRuntime();
            $this->requireReadable($company);

            $result = [];
            foreach ($rows as $row) {
                if (!is_string($row['id'] ?? null) ||
                    preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $row['id']) !== 1 ||
                    !in_array($row['product'] ?? null, ['onepage', 'landing_page'], true) ||
                    !is_scalar($row['latest_revision'] ?? null) ||
                    preg_match('/\A[1-9][0-9]*\z/D', (string) $row['latest_revision']) !== 1 ||
                    (int) $row['latest_revision'] < 1 ||
                    !is_string($row['latest_created_at'] ?? null) ||
                    $row['latest_created_at'] === '' ||
                    !is_string($row['sha256'] ?? null) ||
                    preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) !== 1 ||
                    !in_array($row['editorial_status'] ?? null,
                        ['awaiting_review', 'changes_requested', 'approved_internal'], true)) {
                    throw new RuntimeException('Private preview overview unavailable');
                }
                $result[] = [
                    'id' => $row['id'],
                    'product' => $row['product'],
                    'latest_revision' => (int) $row['latest_revision'],
                    'latest_created_at' => $row['latest_created_at'],
                    'sha256' => $row['sha256'],
                    'editorial_status' => $row['editorial_status'],
                ];
            }
            return $result;
        } catch (Throwable) {
            // Do not expose SQL, tenant identifiers or driver diagnostics.
            throw new RuntimeException('Private preview overview unavailable');
        }
    }

    private function requireReadable(string $company): void
    {
        $query = $this->db->prepare("SELECT sites_preview.can_access(?::uuid,'read')");
        $query->execute([$company]);
        if ($query->fetchColumn() !== true) {
            throw new RuntimeException('Private preview overview unavailable');
        }
    }
}
