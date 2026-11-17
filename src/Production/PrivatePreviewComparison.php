<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use RuntimeException;
use Throwable;

/** Read-only comparison of two immutable private demonstration versions. */
final readonly class PrivatePreviewComparison
{
    private const FIELDS = ['productCode', 'brand', 'headline', 'description', 'callToAction', 'benefits'];
    // Exactly the marker inserted by PrivatePreviewWorkspace::append. The
    // complete stored bytes are hashed before removing it from an audit copy.
    private const BANNER = '<aside role="note" style="background:#F9941D;color:#222;padding:16px;text-align:center">DEMONSTRAÇÃO PRIVADA — sem contratação, pagamento ou aceite.</aside>';

    public function __construct(private PDO $db) {}

    /**
     * @return array{from:array{revision:int,sha256:string,created_at:string,brief:array},to:array{revision:int,sha256:string,created_at:string,brief:array},changed_fields:list<string>}
     */
    public function compare(string $company, string $project, int $from, int $to): array
    {
        foreach ([$company, $project] as $id) {
            if (preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $id) !== 1) {
                throw new InvalidArgumentException('Invalid private preview comparison');
            }
        }
        if ($from < 1 || $from >= $to || $to > 2147483647) {
            throw new InvalidArgumentException('Invalid private preview comparison');
        }

        try {
            $runtime = new PrivatePreviewWorkspace($this->db);
            $runtime->assertSafeRuntime();
            $this->requireReadable($company);
            // A single SELECT reads both selected versions and their project
            // from one PostgreSQL statement snapshot, independently of head
            // or the currently displayed history page. No caller transaction.
            $query = $this->db->prepare(<<<'SQL'
SELECT v.revision,v.sha256,v.created_at,v.brief,v.html,p.product,p.purpose
  FROM sites_preview.versions v
  JOIN sites_preview.projects p ON p.id=v.project_id AND p.company_id=v.company_id
 WHERE v.company_id=?::uuid AND v.project_id=?::uuid AND v.revision IN (?::integer,?::integer)
 ORDER BY v.revision
SQL);
            $query->execute([$company, $project, $from, $to]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) < 2) {
                $runtime->assertSafeRuntime();
                $this->requireReadable($company);
                throw new OutOfBoundsException('Private preview comparison unavailable');
            }
            if (count($rows) !== 2) throw new RuntimeException();
            $before = self::version($rows[0], $from);
            $after = self::version($rows[1], $to);
            if ($before['brief']['productCode'] !== $after['brief']['productCode']) {
                throw new RuntimeException();
            }
            $changed = [];
            foreach (self::FIELDS as $field) {
                if ($before['brief'][$field] !== $after['brief'][$field]) $changed[] = $field;
            }
            $runtime->assertSafeRuntime();
            $this->requireReadable($company);
            return ['from' => $before, 'to' => $after, 'changed_fields' => $changed];
        } catch (OutOfBoundsException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('Private preview comparison unavailable');
        }
    }

    private static function version(array $row, int $expectedRevision): array
    {
        if ((!is_int($row['revision'] ?? null) && !is_string($row['revision'] ?? null)) ||
            (string)$row['revision'] !== (string)$expectedRevision ||
            !is_string($row['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) !== 1 ||
            !is_string($row['created_at'] ?? null) || $row['created_at'] === '' ||
            !is_string($row['html'] ?? null) || !mb_check_encoding($row['html'], 'UTF-8') ||
            !is_string($row['brief'] ?? null) || strlen($row['brief']) > 65536 ||
            ($row['purpose'] ?? null) !== 'demonstration_only') {
            throw new RuntimeException();
        }
        $artifact = new ReleaseArtifact($row['html']);
        if (!hash_equals($row['sha256'], $artifact->digest)) throw new RuntimeException();
        $stored = json_decode($row['brief'], true, 12, JSON_THROW_ON_ERROR);
        if (!is_array($stored) || count($stored) !== count(self::FIELDS) ||
            array_diff(self::FIELDS, array_keys($stored)) || !is_array($stored['benefits']) ||
            !array_is_list($stored['benefits'])) throw new RuntimeException();
        foreach (array_slice(self::FIELDS, 0, -1) as $field) {
            if (!is_string($stored[$field])) throw new RuntimeException();
        }
        $brief = new SiteBrief($stored['productCode'], $stored['brand'], $stored['headline'],
            $stored['description'], $stored['callToAction'], $stored['benefits']);
        if ($brief->productCode !== ($row['product'] ?? null)) throw new RuntimeException();
        $body = '<body data-site-product="'.($brief->productCode === 'onepage' ? 'onepage' : 'landing-page').'">';
        if (substr_count($artifact->html, self::BANNER) !== 1 ||
            !str_contains($artifact->html, $body.self::BANNER)) throw new RuntimeException();
        $auditCopy = str_replace($body.self::BANNER, $body, $artifact->html, $removed);
        if ($removed !== 1 || !InertPreviewAudit::isSafe($auditCopy)) throw new RuntimeException();
        return ['revision' => $expectedRevision, 'sha256' => $artifact->digest,
            'created_at' => $row['created_at'], 'brief' => get_object_vars($brief)];
    }

    private function requireReadable(string $company): void
    {
        $query = $this->db->prepare("SELECT sites_preview.can_access(?::uuid,'read')");
        $query->execute([$company]);
        if ($query->fetchColumn() !== true) throw new RuntimeException();
    }
}
