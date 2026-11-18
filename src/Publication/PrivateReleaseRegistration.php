<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Production\InertPreviewAudit;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Private registration of exact artifact bytes through the existing registry.
 * The caller supplies a new verify-full connection and the hash of its fixed
 * private CA mount. No SQL setting, principal, grant or binding is changed here.
 */
final readonly class PrivateReleaseRegistration
{
    private const PLAN_SCHEMA = 'SITES_RELEASE_REGISTRATION_PLAN_V1';

    public function __construct(private PDO $db) {}

    public static function parseManifest(string $json): array
    {
        try {
            if ($json === '' || strlen($json) > 16384 || str_contains($json, "\0")) {
                throw new RuntimeException();
            }
            $input = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            self::rejectDuplicateKeys($json);
            return self::validateManifest($input);
        } catch (Throwable) {
            throw new RuntimeException('RELEASE_REGISTRATION_DENIED');
        }
    }

    /** Exact selectors only; no caller-supplied tenant or commercial authority. */
    public static function validateManifest(array $manifest): array
    {
        try {
            self::keys($manifest, ['schema', 'target', 'siteRef', 'releaseRef', 'artifactDigest', 'sizeBytes']);
            if ($manifest['schema'] !== 'SITES_RELEASE_REGISTRATION_V1' ||
                !is_string($manifest['siteRef']) || !PgSiteReleaseRegistryGuard::siteId($manifest['siteRef']) ||
                !is_string($manifest['releaseRef']) || !PgSiteReleaseRegistryGuard::reference($manifest['releaseRef']) ||
                !is_string($manifest['artifactDigest']) || !self::digest($manifest['artifactDigest']) ||
                !is_int($manifest['sizeBytes']) || $manifest['sizeBytes'] < 1 || $manifest['sizeBytes'] > 150000) {
                throw new RuntimeException();
            }
            $target = ReleaseArtifactConnection::target($manifest['target']);
            return [
                'schema' => 'SITES_RELEASE_REGISTRATION_V1',
                'target' => $target,
                'siteRef' => $manifest['siteRef'],
                'releaseRef' => $manifest['releaseRef'],
                'artifactDigest' => $manifest['artifactDigest'],
                'sizeBytes' => $manifest['sizeBytes'],
            ];
        } catch (Throwable) {
            throw new RuntimeException('RELEASE_REGISTRATION_DENIED');
        }
    }

    /**
     * Read-only input/runtime plan. It does not call a registry function, read
     * private principals, prove project existence or establish current private
     * authorization. Its deterministic hash has no epoch, TTL or authority.
     */
    public function plan(array $manifest, string $html, string $observedCaSha256): array
    {
        try {
            [$input, $planSha256] = self::input($manifest, $html, $observedCaSha256);
            $before = $this->observe($input['target'], true);
            if ($this->observe($input['target'], true) !== $before) throw new RuntimeException();
            return [
                'schema' => self::PLAN_SCHEMA,
                'status' => 'planned',
                'planSha256' => $planSha256,
                'artifactDigest' => $input['artifactDigest'],
                'sizeBytes' => $input['sizeBytes'],
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ];
        } catch (Throwable) {
            throw new RuntimeException('RELEASE_REGISTRATION_DENIED');
        }
    }

    /**
     * One invocation, no automatic retry. SQL revalidates the private principal
     * and project and preserves its existing same-author/epoch replay rules.
     * A failure after the invocation may follow a commit: no rollback or absence
     * of a persisted artifact is promised by a bounded error response.
     */
    public function record(
        array $manifest,
        string $html,
        string $observedCaSha256,
        string $expectedPlanSha256,
    ): array {
        try {
            [$input, $planSha256] = self::input($manifest, $html, $observedCaSha256);
            if (!self::digest($expectedPlanSha256) || !hash_equals($planSha256, $expectedPlanSha256)) {
                throw new RuntimeException();
            }
            $before = $this->observe($input['target'], false);
            $result = (new PgSiteReleaseRegistry($this->db))->recordArtifact(
                $input['siteRef'], $input['releaseRef'], $html,
            );
            if ($this->observe($input['target'], false) !== $before ||
                array_keys($result) !== ['releaseRef', 'digest', 'sizeBytes', 'created'] ||
                $result['releaseRef'] !== $input['releaseRef'] ||
                $result['digest'] !== $input['artifactDigest'] ||
                $result['sizeBytes'] !== $input['sizeBytes'] || !is_bool($result['created'])) {
                throw new RuntimeException();
            }
            return [
                'schema' => 'SITES_RELEASE_REGISTRATION_RESULT_V1',
                'status' => 'recorded',
                'planSha256' => $planSha256,
                'artifactDigest' => $input['artifactDigest'],
                'sizeBytes' => $input['sizeBytes'],
                'created' => $result['created'],
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ];
        } catch (Throwable) {
            // No SQL diagnostic, private reference, credential or error chain.
            throw new RuntimeException('RELEASE_REGISTRATION_DENIED');
        }
    }

    /** @return array{array,string} */
    private static function input(array $manifest, string $html, string $observedCaSha256): array
    {
        $input = self::validateManifest($manifest);
        if (!self::digest($observedCaSha256) || !hash_equals($input['target']['caSha256'], $observedCaSha256) ||
            $html === '' || strlen($html) > 150000 || str_contains($html, "\0") ||
            !mb_check_encoding($html, 'UTF-8') || $input['sizeBytes'] !== strlen($html) ||
            !hash_equals($input['artifactDigest'], hash('sha256', $html)) || !InertPreviewAudit::isSafe($html)) {
            throw new RuntimeException();
        }
        // A separate domain prefix and NUL delimiters bind all canonical
        // selectors and every original byte, never a normalized HTML copy.
        $canonical = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return [$input, hash('sha256', self::PLAN_SCHEMA."\0".$canonical."\0".$html)];
    }

    /** @return array{database:string,login:string,pid:int} */
    private function observe(array $target, bool $readOnly): array
    {
        return ReleaseArtifactConnection::observe($this->db, $target, $readOnly,
            ReleaseArtifactConnection::ARTIFACT);
    }

    private static function digest(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function keys(mixed $value, array $expected): void
    {
        if (!is_array($value) || count($value) !== count($expected) || array_diff($expected, array_keys($value))) {
            throw new RuntimeException();
        }
    }

    /** json_decode otherwise accepts ambiguous duplicate object keys. */
    private static function rejectDuplicateKeys(string $json): void
    {
        $objects = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if ($char === '{') $objects[] = [];
            elseif ($char === '[') $objects[] = null;
            elseif ($char === '}' || $char === ']') array_pop($objects);
            elseif ($char === '"') {
                $start = $i;
                for (++$i; $i < $length; ++$i) {
                    if ($json[$i] === '\\') { ++$i; continue; }
                    if ($json[$i] === '"') break;
                }
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $json[$next])) ++$next;
                if ($next < $length && $json[$next] === ':') {
                    $key = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                    $level = count($objects) - 1;
                    if ($level < 0 || !is_array($objects[$level]) || isset($objects[$level][$key])) {
                        throw new RuntimeException();
                    }
                    $objects[$level][$key] = true;
                }
            }
        }
    }
}
