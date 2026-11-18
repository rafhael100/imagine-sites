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
    public const REVIEW_LIMIT = 100;

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

            // Head, version window and both review groups use one SQL snapshot.
            // In particular, an old history page never supplies the edit baseline
            // or attaches a previous digest's feedback to the current head.
            $sql = <<<'SQL'
WITH current_version AS (
 SELECT head.project_id,head.company_id,head.revision AS current_revision,
        head.sha256 AS current_sha256,head.brief AS current_brief,
        (SELECT min(first_version.revision) FROM sites_preview.versions first_version
          WHERE first_version.project_id=head.project_id AND first_version.company_id=head.company_id) AS first_revision
   FROM sites_preview.versions head
  WHERE head.project_id=?::uuid AND head.company_id=?::uuid
  ORDER BY head.revision DESC LIMIT 1
), selected_versions AS (
 SELECT v.revision,v.sha256,v.created_at,
        v.author<>session_user AND NOT EXISTS (
          SELECT 1 FROM sites_preview.reviews r
           WHERE r.project_id=v.project_id AND r.company_id=v.company_id
             AND r.revision=v.revision AND r.sha256=v.sha256 AND r.reviewer=session_user
        ) AS reviewable
   FROM current_version c
   JOIN sites_preview.versions v ON v.project_id=c.project_id AND v.company_id=c.company_id
  WHERE true
SQL;
            $sql .= $boundary.' ORDER BY v.revision '.$direction.' LIMIT '.self::PAGE_SIZE;
            $sql .= <<<'SQL'

), page_reviews AS (
 SELECT r.revision,r.sha256,r.decision,r.note,r.created_at,r.reviewer
   FROM current_version c
   JOIN sites_preview.reviews r ON r.project_id=c.project_id AND r.company_id=c.company_id
   JOIN selected_versions v ON v.revision=r.revision AND v.sha256=r.sha256
  ORDER BY r.revision DESC,r.created_at DESC,r.reviewer
SQL;
            $sql .= ' LIMIT '.(self::REVIEW_LIMIT + 1);
            $sql .= <<<'SQL'

), head_reviews AS (
 SELECT r.revision,r.sha256,r.decision,r.note,r.created_at,r.reviewer
   FROM current_version c
   JOIN sites_preview.reviews r ON r.project_id=c.project_id AND r.company_id=c.company_id
    AND r.revision=c.current_revision AND r.sha256=c.current_sha256
  ORDER BY r.created_at DESC,r.reviewer
SQL;
            $sql .= ' LIMIT '.(self::REVIEW_LIMIT + 1);
            $sql .= <<<'SQL'

)
SELECT c.current_revision,c.current_sha256,c.current_brief,c.first_revision,
 coalesce((SELECT jsonb_agg(jsonb_build_object('revision',v.revision,'sha256',v.sha256,
   'created_at',v.created_at::text,'reviewable',v.reviewable) ORDER BY v.revision
SQL;
            $sql .= ' '.$direction;
            $sql .= <<<'SQL'
 ) FROM selected_versions v),'[]'::jsonb) AS versions,
 coalesce((SELECT jsonb_agg(jsonb_build_object('revision',r.revision,'sha256',r.sha256,
   'decision',r.decision,'note',r.note,'created_at',r.created_at::text)
   ORDER BY r.revision DESC,r.created_at DESC,r.reviewer) FROM page_reviews r),'[]'::jsonb) AS reviews,
 coalesce((SELECT jsonb_agg(jsonb_build_object('revision',r.revision,'sha256',r.sha256,
   'decision',r.decision,'note',r.note,'created_at',r.created_at::text)
   ORDER BY r.created_at DESC,r.reviewer) FROM head_reviews r),'[]'::jsonb) AS current_reviews
 FROM current_version c
SQL;
            $query = $this->db->prepare($sql);
            $query->execute($parameters);
            $page = $query->fetch(PDO::FETCH_ASSOC);
            if (!$page) {
                $runtime->assertSafeRuntime();
                $this->requireReadable($company);
                throw new OutOfBoundsException('Private preview unavailable');
            }
            $currentRevision = self::revision($page['current_revision'] ?? null);
            $firstRevision = self::revision($page['first_revision'] ?? null);
            $currentDigest = self::digest($page['current_sha256'] ?? null);
            $storedBrief = json_decode($page['current_brief'], true, 12, JSON_THROW_ON_ERROR);
            $brief = get_object_vars(new SiteBrief(
                $storedBrief['productCode'], $storedBrief['brand'], $storedBrief['headline'],
                $storedBrief['description'], $storedBrief['callToAction'], $storedBrief['benefits'],
            ));
            $versions = [];
            $versionDigests = [];
            foreach (self::rows($page['versions'] ?? null, self::PAGE_SIZE) as $row) {
                $revision = self::revision($row['revision'] ?? null);
                $digest = self::digest($row['sha256'] ?? null);
                if ($revision > $currentRevision || $revision < $firstRevision ||
                    isset($versionDigests[$revision]) || ($revision === $currentRevision && $digest !== $currentDigest) ||
                    !is_string($row['created_at'] ?? null) || $row['created_at'] === '' ||
                    !is_bool($row['reviewable'] ?? null)) {
                    throw new RuntimeException('Private preview history unavailable');
                }
                $versionDigests[$revision] = $digest;
                $versions[] = ['revision' => $revision, 'sha256' => $digest,
                    'created_at' => $row['created_at'], 'reviewable' => $row['reviewable']];
            }
            if ($after !== null) $versions = array_reverse($versions);

            $reviews = self::reviews(self::rows($page['reviews'] ?? null, self::REVIEW_LIMIT + 1), $versionDigests);
            $currentReviews = self::reviews(self::rows($page['current_reviews'] ?? null, self::REVIEW_LIMIT + 1),
                [$currentRevision => $currentDigest]);
            $olderBefore = $newerAfter = null;
            if ($versions) {
                $newest = $versions[0]['revision'];
                $oldest = $versions[array_key_last($versions)]['revision'];
                $olderBefore = $oldest > $firstRevision ? $oldest : null;
                $newerAfter = $newest < $currentRevision ? $newest : null;
            }
            $runtime->assertSafeRuntime();
            $this->requireReadable($company);
            return [
                'current_revision' => $currentRevision, 'current_sha256' => $currentDigest, 'brief' => $brief,
                'versions' => $versions, 'reviews' => array_slice($reviews, 0, self::REVIEW_LIMIT),
                'reviews_truncated' => count($reviews) > self::REVIEW_LIMIT,
                'current_reviews' => array_slice($currentReviews, 0, self::REVIEW_LIMIT),
                'current_reviews_truncated' => count($currentReviews) > self::REVIEW_LIMIT,
                'older_before' => $olderBefore, 'newer_after' => $newerAfter,
            ];
        } catch (OutOfBoundsException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('Private preview history unavailable');
        }
    }

    private static function rows(mixed $raw, int $maximum): array
    {
        // A note may require six JSON bytes per code point for escaped controls.
        // Bound the aggregate without rejecting a valid 2000-character note.
        if (!is_string($raw) || strlen($raw) > $maximum * 16384) {
            throw new RuntimeException('Private preview history unavailable');
        }
        $rows = json_decode($raw, false, 12, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $maximum) {
            throw new RuntimeException('Private preview history unavailable');
        }
        foreach ($rows as $index => $row) {
            if (!$row instanceof \stdClass) throw new RuntimeException('Private preview history unavailable');
            $rows[$index] = get_object_vars($row);
        }
        return $rows;
    }

    private static function reviews(array $rows, array $versionDigests): array
    {
        $reviews = [];
        foreach ($rows as $row) {
            $revision = self::revision($row['revision'] ?? null);
            $digest = self::digest($row['sha256'] ?? null);
            if (($versionDigests[$revision] ?? null) !== $digest ||
                !in_array($row['decision'] ?? null, ['approved_internal', 'changes_requested'], true) ||
                !is_string($row['created_at'] ?? null) || $row['created_at'] === '' || !array_key_exists('note', $row)) {
                throw new RuntimeException('Private preview history unavailable');
            }
            $note = $row['note'];
            // Existing changes_requested rows can legitimately predate notes.
            // Preserve NULL and the original text; new-input rules must not
            // manufacture feedback or discard that immutable legacy history.
            if ($note !== null && (!is_string($note) || strlen($note) > PreviewReviewInput::MAX_NOTE_BYTES ||
                !mb_check_encoding($note, 'UTF-8') || str_contains($note, "\0") ||
                mb_strlen($note, 'UTF-8') > PreviewReviewInput::MAX_NOTE_CHARACTERS)) {
                throw new RuntimeException('Private preview history unavailable');
            }
            $reviews[] = ['revision' => $revision, 'sha256' => $digest, 'decision' => $row['decision'],
                'note' => $note, 'created_at' => $row['created_at']];
        }
        return $reviews;
    }

    private static function digest(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new RuntimeException('Private preview history unavailable');
        }
        return $value;
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
