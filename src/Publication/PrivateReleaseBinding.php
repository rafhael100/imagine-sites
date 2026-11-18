<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Explicit private binding/revocation through the existing registry APIs.
 * The caller supplies a new verify-full PDO and the observed private CA hash.
 * No SQL setting, grant, principal, artifact or editorial revision is changed.
 */
final readonly class PrivateReleaseBinding
{
    private const PLAN_SCHEMA = 'SITES_RELEASE_BINDING_PLAN_V1';

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
            throw new RuntimeException('RELEASE_BINDING_DENIED');
        }
    }

    /** Exact SQL selectors; no external digest, HTML or commercial authority. */
    public static function validateManifest(array $manifest): array
    {
        try {
            $action = $manifest['action'] ?? null;
            if (PHP_INT_SIZE !== 8 || !in_array($action, ['bind', 'revoke'], true)) {
                throw new RuntimeException();
            }
            $keys = ['schema', 'target', 'action', 'siteRef', 'expectedBindingRevision'];
            if ($action === 'bind') array_push($keys, 'releaseRef', 'editorialRevision');
            self::keys($manifest, $keys);
            if ($manifest['schema'] !== 'SITES_RELEASE_BINDING_V1' ||
                !is_string($manifest['siteRef']) || !PgSiteReleaseRegistryGuard::siteId($manifest['siteRef']) ||
                !is_int($manifest['expectedBindingRevision']) ||
                $manifest['expectedBindingRevision'] < ($action === 'bind' ? 0 : 1) ||
                $manifest['expectedBindingRevision'] > PHP_INT_MAX - 1 ||
                ($action === 'bind' && (
                    !is_string($manifest['releaseRef']) || !PgSiteReleaseRegistryGuard::reference($manifest['releaseRef']) ||
                    !is_int($manifest['editorialRevision']) || $manifest['editorialRevision'] < 1 ||
                    $manifest['editorialRevision'] > 2147483647))) {
                throw new RuntimeException();
            }
            $target = $manifest['target'];
            $targetKeys = ['host', 'port', 'database', 'databaseOid', 'login', 'serverAddress', 'caSha256'];
            self::keys($target, $targetKeys);
            foreach (['host', 'database', 'login', 'serverAddress', 'caSha256'] as $key) {
                if (!is_string($target[$key])) throw new RuntimeException();
            }
            if (strlen($target['host']) > 253 ||
                !(filter_var($target['host'], FILTER_VALIDATE_IP) !== false ||
                    (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/D', $target['host']) === 1 &&
                        !str_contains($target['host'], '..'))) ||
                preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/D', $target['database']) !== 1 ||
                preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/D', $target['login']) !== 1 ||
                filter_var($target['serverAddress'], FILTER_VALIDATE_IP) === false ||
                !is_int($target['port']) || $target['port'] < 1 || $target['port'] > 65535 ||
                !is_int($target['databaseOid']) || $target['databaseOid'] < 1 || $target['databaseOid'] > 4294967295 ||
                !self::digest($target['caSha256'])) {
                throw new RuntimeException();
            }
            $input = [
                'schema' => 'SITES_RELEASE_BINDING_V1',
                'target' => array_replace(array_fill_keys($targetKeys, null), $target),
                'action' => $action,
                'siteRef' => $manifest['siteRef'],
                'expectedBindingRevision' => $manifest['expectedBindingRevision'],
            ];
            if ($action === 'bind') {
                $input['releaseRef'] = $manifest['releaseRef'];
                $input['editorialRevision'] = $manifest['editorialRevision'];
            }
            return $input;
        } catch (Throwable) {
            throw new RuntimeException('RELEASE_BINDING_DENIED');
        }
    }

    /**
     * Read-only selectors/runtime plan; it never calls a registry function.
     * It proves neither private duty/epoch, current head, project/artefact
     * existence nor a digest. SQL derives and verifies those when binding.
     */
    public function plan(array $manifest, string $observedCaSha256): array
    {
        try {
            [$input, $planSha256] = self::input($manifest, $observedCaSha256);
            $before = $this->observe($input, true);
            if ($this->observe($input, true) !== $before) throw new RuntimeException();
            return [
                'schema' => self::PLAN_SCHEMA,
                'status' => 'planned',
                'planSha256' => $planSha256,
                'action' => $input['action'],
                'expectedBindingRevision' => $input['expectedBindingRevision'],
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ];
        } catch (Throwable) {
            throw new RuntimeException('RELEASE_BINDING_DENIED');
        }
    }

    /**
     * One API invocation with the reviewed CAS, no retry or transaction wrapper.
     * Existing SQL owns same-actor/epoch replay and current private authority.
     * Its return does not distinguish insertion from replay: no such claim is
     * inferred from revision arithmetic. A failure after the call may follow
     * a commit and never guarantees rollback or authorizes automatic retry.
     */
    public function apply(array $manifest, string $observedCaSha256, string $expectedPlanSha256): array
    {
        try {
            [$input, $planSha256] = self::input($manifest, $observedCaSha256);
            if (!self::digest($expectedPlanSha256) || !hash_equals($planSha256, $expectedPlanSha256)) {
                throw new RuntimeException();
            }
            $before = $this->observe($input, false);
            $registry = new PgSiteReleaseRegistry($this->db);
            if ($input['action'] === 'bind') {
                $binding = $registry->bind($input['siteRef'], $input['releaseRef'],
                    $input['editorialRevision'], $input['expectedBindingRevision']);
                if ($binding->siteRef !== $input['siteRef'] || $binding->releaseRef !== $input['releaseRef'] ||
                    $binding->editorialRevision !== $input['editorialRevision'] || !self::digest($binding->artifactDigest)) {
                    throw new RuntimeException();
                }
                $revision = $binding->bindingRevision;
            } else {
                // Revocation deliberately does not require a currently valid
                // artifact author, binder or editorial head. SQL checks the
                // revoker and the exact latest binding revision independently.
                $revision = $registry->revoke($input['siteRef'], $input['expectedBindingRevision']);
            }
            if ($revision < max(1, $input['expectedBindingRevision']) ||
                $revision > $input['expectedBindingRevision'] + 1 || $this->observe($input, false) !== $before) {
                throw new RuntimeException();
            }
            $result = [
                'schema' => 'SITES_RELEASE_BINDING_RESULT_V1',
                'status' => $input['action'] === 'bind' ? 'bound' : 'revoked',
                'planSha256' => $planSha256,
                'action' => $input['action'],
                'expectedBindingRevision' => $input['expectedBindingRevision'],
                'canPublish' => false,
                'canAcceptRealOrders' => false,
                'bindingRevision' => $revision,
            ];
            if ($input['action'] === 'bind') {
                $result['editorialRevision'] = $binding->editorialRevision;
                $result['artifactDigest'] = $binding->artifactDigest;
            }
            return $result;
        } catch (Throwable) {
            // No SQL diagnostic, private selector, commercial object or cause.
            throw new RuntimeException('RELEASE_BINDING_DENIED');
        }
    }

    /** @return array{array,string} */
    private static function input(array $manifest, string $observedCaSha256): array
    {
        $input = self::validateManifest($manifest);
        if (!self::digest($observedCaSha256) || !hash_equals($input['target']['caSha256'], $observedCaSha256)) {
            throw new RuntimeException();
        }
        return [$input, hash('sha256', self::PLAN_SCHEMA."\0".
            json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    /** @return array{database:string,login:string,pid:int} */
    private function observe(array $input, bool $readOnly): array
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $this->db->inTransaction() ||
            $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException();
        }
        if ($this->db->query("SELECT pg_catalog.current_setting('search_path')")->fetchColumn() !== 'pg_catalog') {
            throw new RuntimeException();
        }
        $identity = PgSiteReleaseRegistryGuard::snapshot($this->db);
        $signature = match ($input['action']) {
            'bind' => 'sites_core.bind_release_artifact(uuid,text,integer,bigint)',
            'revoke' => 'sites_core.revoke_release_binding(uuid,bigint)',
        };
        $probe = $this->db->prepare(<<<'SQL'
WITH api AS (SELECT to_regprocedure(?::text) AS oid)
SELECT d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,
 inet_server_port() AS server_port,
 current_setting('default_transaction_read_only') AS default_read_only,
 current_setting('transaction_read_only') AS read_only,
 current_setting('client_encoding')='UTF8' AS utf8,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls,
 has_schema_privilege(session_user,'sites_core','USAGE')
  AND has_function_privilege(session_user,api.oid,'EXECUTE')
  AND NOT has_function_privilege(session_user,api.oid,'EXECUTE WITH GRANT OPTION')
  AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication') AND p.oid<>api.oid
    AND has_function_privilege(session_user,p.oid,'EXECUTE')) AS binding_api_only
FROM pg_database d CROSS JOIN api LEFT JOIN pg_stat_ssl s ON s.pid=pg_backend_pid()
WHERE d.datname=current_database()
SQL);
        $probe->execute([$signature]);
        $row = $probe->fetch(PDO::FETCH_ASSOC);
        $target = $input['target'];
        $mode = $readOnly ? 'on' : 'off';
        if (!is_array($row) || $identity['database'] !== $target['database'] ||
            $identity['login'] !== $target['login'] ||
            (string) ($row['database_oid'] ?? '') !== (string) $target['databaseOid'] ||
            ($row['server_address'] ?? null) !== $target['serverAddress'] ||
            (string) ($row['server_port'] ?? '') !== (string) $target['port'] ||
            ($row['default_read_only'] ?? null) !== $mode || ($row['read_only'] ?? null) !== $mode ||
            ($row['utf8'] ?? null) !== true || ($row['tls'] ?? null) !== true ||
            ($row['binding_api_only'] ?? null) !== true) {
            throw new RuntimeException();
        }
        // Public catalogs establish transport, mode and effective API grants.
        // They do not read the private principal or predict the CAS outcome.
        return $identity;
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
