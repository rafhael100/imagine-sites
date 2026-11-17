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
        // Preserve the original public shape used by the existing role contract.
        $keys = array_flip(['id', 'product', 'latest_revision', 'latest_created_at', 'sha256', 'editorial_status']);
        return array_map(static fn (array $row): array => array_intersect_key($row, $keys), $this->page($company)['items']);
    }

    /**
     * @return array{items:list<array<string,mixed>>,next_cursor:?string,has_more:bool}
     */
    public function page(string $company, ?PreviewQueueQuery $navigation = null): array
    {
        if (preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $company) !== 1) {
            throw new RuntimeException('Private preview overview unavailable');
        }

        try {
            $navigation ??= new PreviewQueueQuery();
            $workspace = new PrivatePreviewWorkspace($this->db);
            $workspace->assertSafeRuntime();
            $this->requireReadable($company);

            $predicates = [];
            $parameters = [$company];
            if ($navigation->status !== 'all') {
                $predicates[] = 'q.editorial_status=?';
                $parameters[] = $navigation->status;
            }
            if ($navigation->product !== 'all') {
                $predicates[] = 'q.product=?';
                $parameters[] = $navigation->product;
            }
            if ($navigation->cursor !== null) {
                $predicates[] = '(q.latest_created_at<?::timestamptz OR (q.latest_created_at=?::timestamptz AND q.id>?::uuid))';
                array_push($parameters, $navigation->cursorTime, $navigation->cursorTime, $navigation->cursorId);
            }

            // One statement takes one coherent READ COMMITTED snapshot. Both
            // lateral reads retain company and project predicates, in addition
            // to FORCE RLS. Only the latest revision and its exact digest count.
            $sql = <<<'SQL'
WITH queue AS (
SELECT p.id,p.product,v.revision AS latest_revision,
       v.created_at AS latest_created_at,v.sha256,
       v.brief->>'brand' AS brand,v.brief->>'headline' AS headline,
       to_char(v.created_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS cursor_time,
       CASE WHEN coalesce(r.changes_requested,false) THEN 'changes_requested'
            WHEN coalesce(r.approved_internal,false) THEN 'approved_internal'
            ELSE 'awaiting_review' END AS editorial_status
  FROM sites_preview.projects p
  JOIN LATERAL (
    SELECT pv.revision,pv.created_at,pv.sha256,pv.brief
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
)
SELECT * FROM queue q
SQL;
            if ($predicates) $sql .= ' WHERE '.implode(' AND ', $predicates);
            $sql .= ' ORDER BY q.latest_created_at DESC,q.id LIMIT '.(PreviewQueueQuery::PAGE_SIZE + 1);
            $query = $this->db->prepare($sql);
            $query->execute($parameters);
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
                    !is_string($row['brand'] ?? null) || trim($row['brand']) === '' ||
                    !mb_check_encoding($row['brand'], 'UTF-8') || mb_strlen($row['brand'], 'UTF-8') > 120 ||
                    !is_string($row['headline'] ?? null) || trim($row['headline']) === '' ||
                    !mb_check_encoding($row['headline'], 'UTF-8') || mb_strlen($row['headline'], 'UTF-8') > 180 ||
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
                $cursor = PreviewQueueQuery::cursorFor($row['cursor_time'], $row['id']);
                $result[] = [
                    'id' => $row['id'],
                    'product' => $row['product'],
                    'brand' => $row['brand'],
                    'headline' => $row['headline'],
                    'latest_revision' => (int) $row['latest_revision'],
                    'latest_created_at' => $row['latest_created_at'],
                    'sha256' => $row['sha256'],
                    'editorial_status' => $row['editorial_status'],
                    'cursor' => $cursor,
                ];
            }
            $hasMore = count($result) > PreviewQueueQuery::PAGE_SIZE;
            $items = array_slice($result, 0, PreviewQueueQuery::PAGE_SIZE);
            $nextCursor = $hasMore ? $items[array_key_last($items)]['cursor'] : null;
            foreach ($items as &$item) unset($item['cursor']);
            unset($item);
            return ['items' => $items, 'next_cursor' => $nextCursor, 'has_more' => $hasMore];
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
