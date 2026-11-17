<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use RuntimeException;
use Throwable;

/** Reads bounded version windows while keeping the edit briefing on the latest version. */
final readonly class PrivatePreviewHistory
{
    public const PAGE_SIZE = 20;

    public function __construct(private PDO $db) {}

    public function page(string $company, string $project, ?int $before = null, ?int $after = null): array
    {
        foreach ([$company, $project] as $id) {
            if (preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $id) !== 1) {
                throw new InvalidArgumentException('Invalid private preview history');
            }
        }
        if (($before !== null && ($before < 1 || $before > 2147483647)) ||
            ($after !== null && ($after < 1 || $after > 2147483647)) || ($before !== null && $after !== null)) {
            throw new InvalidArgumentException('Invalid private preview history');
        }

        try {
            $runtime = new PrivatePreviewWorkspace($this->db);
            $runtime->assertSafeRuntime();
            $this->requireReadable($company);
            $direction = $after === null ? 'DESC' : 'ASC';
            $boundary = $before !== null ? ' AND v.revision<?' : ($after !== null ? ' AND v.revision>?' : '');
            $parameters = [$project, $company];
            if ($before !== null || $after !== null) $parameters[] = $before ?? $after;

            // The current head/brief and the selected window use the same SQL
            // snapshot. An old history page can never become the edit baseline.
            // Whether this principal already reviewed a version uses the whole
            // table under RLS, independently of the displayed review limit.
            $sql = <<<'SQL'
WITH current_version AS (
 SELECT head.project_id,head.company_id,head.revision AS current_revision,head.brief AS current_brief,
        (SELECT min(first_version.revision) FROM sites_preview.versions first_version
          WHERE first_version.project_id=head.project_id AND first_version.company_id=head.company_id) AS first_revision
   FROM sites_preview.versions head
  WHERE head.project_id=?::uuid AND head.company_id=?::uuid
  ORDER BY head.revision DESC LIMIT 1
)
SELECT c.current_revision,c.current_brief,c.first_revision,paged.*
  FROM current_version c
  LEFT JOIN LATERAL (
    SELECT v.revision,v.sha256,v.created_at,
           v.author<>session_user AND NOT EXISTS (
             SELECT 1 FROM sites_preview.reviews r
              WHERE r.project_id=v.project_id AND r.company_id=v.company_id
                AND r.revision=v.revision AND r.sha256=v.sha256 AND r.reviewer=session_user
           ) AS reviewable
      FROM sites_preview.versions v
     WHERE v.project_id=c.project_id AND v.company_id=c.company_id
SQL;
            $sql .= $boundary.' ORDER BY v.revision '.$direction.' LIMIT '.(self::PAGE_SIZE + 1);
            $sql .= ') paged ON true ORDER BY paged.revision '.$direction;
            $query = $this->db->prepare($sql);
            $query->execute($parameters);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                $runtime->assertSafeRuntime();
                $this->requireReadable($company);
                throw new OutOfBoundsException('Private preview unavailable');
            }
            $currentRevision = self::revision($rows[0]['current_revision'] ?? null);
            $firstRevision = self::revision($rows[0]['first_revision'] ?? null);
            $storedBrief = json_decode($rows[0]['current_brief'], true, 12, JSON_THROW_ON_ERROR);
            $brief = get_object_vars(new SiteBrief(
                $storedBrief['productCode'], $storedBrief['brand'], $storedBrief['headline'],
                $storedBrief['description'], $storedBrief['callToAction'], $storedBrief['benefits'],
            ));
            $versions = [];
            foreach ($rows as $row) {
                if ($row['revision'] === null) continue;
                $revision = self::revision($row['revision']);
                if ($revision > $currentRevision || $revision < $firstRevision ||
                    !is_string($row['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) !== 1 ||
                    !is_string($row['created_at'] ?? null) || $row['created_at'] === '' ||
                    !is_bool($row['reviewable'] ?? null)) {
                    throw new RuntimeException('Private preview history unavailable');
                }
                $versions[] = ['revision' => $revision, 'sha256' => $row['sha256'],
                    'created_at' => $row['created_at'], 'reviewable' => $row['reviewable']];
            }
            $versions = array_slice($versions, 0, self::PAGE_SIZE);
            if ($after !== null) $versions = array_reverse($versions);

            $reviews = [];
            $olderBefore = $newerAfter = null;
            if ($versions) {
                $newest = $versions[0]['revision'];
                $oldest = $versions[array_key_last($versions)]['revision'];
                $olderBefore = $oldest > $firstRevision ? $oldest : null;
                $newerAfter = $newest < $currentRevision ? $newest : null;
                $query = $this->db->prepare(<<<'SQL'
SELECT revision,decision,created_at
  FROM sites_preview.reviews
 WHERE project_id=?::uuid AND company_id=?::uuid AND revision>=? AND revision<=?
 ORDER BY revision DESC,created_at DESC,reviewer
 LIMIT 101
SQL);
                $query->execute([$project, $company, $oldest, $newest]);
                $reviews = $query->fetchAll(PDO::FETCH_ASSOC);
                foreach ($reviews as &$review) {
                    $review['revision'] = self::revision($review['revision'] ?? null);
                    if (!in_array($review['decision'] ?? null, ['approved_internal', 'changes_requested'], true) ||
                        !is_string($review['created_at'] ?? null) || $review['created_at'] === '') {
                        throw new RuntimeException('Private preview history unavailable');
                    }
                }
                unset($review);
            }
            $runtime->assertSafeRuntime();
            $this->requireReadable($company);
            return [
                'current_revision' => $currentRevision, 'brief' => $brief,
                'versions' => $versions, 'reviews' => array_slice($reviews, 0, 100),
                'reviews_truncated' => count($reviews) > 100,
                'older_before' => $olderBefore, 'newer_after' => $newerAfter,
            ];
        } catch (OutOfBoundsException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('Private preview history unavailable');
        }
    }

    private static function revision(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value)) ||
            preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $value) !== 1 || (int) $value > 2147483647) {
            throw new RuntimeException('Private preview history unavailable');
        }
        return (int) $value;
    }

    private function requireReadable(string $company): void
    {
        $query = $this->db->prepare("SELECT sites_preview.can_access(?::uuid,'read')");
        $query->execute([$company]);
        if ($query->fetchColumn() !== true) throw new RuntimeException('Private preview history unavailable');
    }
}
