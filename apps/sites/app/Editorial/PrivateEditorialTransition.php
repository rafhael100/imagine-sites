<?php

declare(strict_types=1);

namespace App\Editorial;

use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Publication\PgSiteReleaseRegistryGuard;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Explicit private candidate around the existing editorial writer. The caller
 * supplies a new verify-full PDO and the observed hash of its fixed private CA.
 * No SQL setting, grant, principal, registry artifact or binding is changed here.
 */
final readonly class PrivateEditorialTransition
{
    private const PLAN_SCHEMA = 'SITES_EDITORIAL_TRANSITION_PLAN_V1';
    private const ACTIONS = ['register', 'revise', 'submit', 'rework', 'approve_internal', 'reopen'];

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
            throw new RuntimeException('EDITORIAL_TRANSITION_DENIED');
        }
    }

    /** Selectors only; SQL owns the private tenant, duty and state preconditions. */
    public static function validateManifest(array $manifest): array
    {
        try {
            self::keys($manifest, ['schema', 'target', 'siteRef', 'expectedRevision', 'action', 'artifactDigest', 'sizeBytes']);
            if ($manifest['schema'] !== 'SITES_EDITORIAL_TRANSITION_V1' ||
                !is_string($manifest['siteRef']) || !PgSiteReleaseRegistryGuard::siteId($manifest['siteRef']) ||
                !is_int($manifest['expectedRevision']) || $manifest['expectedRevision'] < 0 ||
                $manifest['expectedRevision'] > 2147483646 || !in_array($manifest['action'], self::ACTIONS, true) ||
                !is_string($manifest['artifactDigest']) || !self::digest($manifest['artifactDigest']) ||
                !is_int($manifest['sizeBytes']) || $manifest['sizeBytes'] < 1 || $manifest['sizeBytes'] > 150000) {
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
            return [
                'schema' => 'SITES_EDITORIAL_TRANSITION_V1',
                'target' => array_replace(array_fill_keys($targetKeys, null), $target),
                'siteRef' => $manifest['siteRef'],
                'expectedRevision' => $manifest['expectedRevision'],
                'action' => $manifest['action'],
                'artifactDigest' => $manifest['artifactDigest'],
                'sizeBytes' => $manifest['sizeBytes'],
            ];
        } catch (Throwable) {
            throw new RuntimeException('EDITORIAL_TRANSITION_DENIED');
        }
    }

    /**
     * Read-only input/runtime commitment, without calling an editorial function.
     * It does not establish the current head, private duty, active binding,
     * project existence or that these bytes exist in the release registry.
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
                'action' => $input['action'],
                'artifactDigest' => $input['artifactDigest'],
                'sizeBytes' => $input['sizeBytes'],
                'clientAccepted' => false,
                'canPublish' => false,
            ];
        } catch (Throwable) {
            throw new RuntimeException('EDITORIAL_TRANSITION_DENIED');
        }
    }

    /**
     * Exactly one transition with the supplied CAS; never an automatic retry.
     * Editorial SQL rechecks its active binding, not a registry principal epoch.
     * A lost/invalid response or a failed postcheck can follow a commit. This
     * bounded error is not a rollback guarantee or permission to advance CAS.
     */
    public function apply(
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
            $connection = $this->connection($input['target']['database']);
            $result = (new EditorialRevisionWriter($connection))->transition(
                $input['siteRef'], $input['expectedRevision'], $input['action'], $input['artifactDigest'],
            );
            $state = match ($input['action']) {
                'submit' => 'internal_review',
                'approve_internal' => 'awaiting_portal_acceptance',
                default => 'draft',
            };
            if ($connection->getRawPdo() !== $this->db || $connection->getRawReadPdo() !== null ||
                $connection->transactionLevel() !== 0 || $this->observe($input['target'], false) !== $before ||
                array_keys($result) !== ['revision', 'state', 'digest', 'clientAccepted', 'publishable'] ||
                $result['revision'] !== $input['expectedRevision'] + 1 || $result['state'] !== $state ||
                $result['digest'] !== $input['artifactDigest'] || $result['clientAccepted'] !== false ||
                $result['publishable'] !== false) {
                throw new RuntimeException();
            }
            return [
                'schema' => 'SITES_EDITORIAL_TRANSITION_RESULT_V1',
                'status' => 'transitioned',
                'planSha256' => $planSha256,
                'action' => $input['action'],
                'artifactDigest' => $input['artifactDigest'],
                'sizeBytes' => $input['sizeBytes'],
                'clientAccepted' => false,
                'canPublish' => false,
                'revision' => $result['revision'],
                'state' => $result['state'],
            ];
        } catch (Throwable) {
            // No private selectors, SQL diagnostics, bindings or error chain.
            throw new RuntimeException('EDITORIAL_TRANSITION_DENIED');
        }
    }

    /**
     * selectOne normally permits a read pool and lost-connection retry. Keep
     * this local adapter on the verified PDO, without the application manager,
     * event listeners, query logging or a functional reconnect path.
     */
    private function connection(string $database): PostgresConnection
    {
        $connection = new PostgresConnection($this->db, $database, '', [
            'driver' => 'pgsql', 'mask_bindings_in_exception_messages' => true,
        ]);
        $connection->useWriteConnectionWhenReading();
        $connection->unsetEventDispatcher();
        $connection->disableQueryLog();
        $connection->setReconnector(static function (): never {
            throw new RuntimeException('EDITORIAL_TRANSITION_DENIED');
        });
        return $connection;
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
        $canonical = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return [$input, hash('sha256', self::PLAN_SCHEMA."\0".$canonical."\0".$html)];
    }

    /** @return array{database:string,login:string,pid:int} */
    private function observe(array $target, bool $readOnly): array
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $this->db->inTransaction() ||
            $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException();
        }
        if ($this->db->query("SELECT pg_catalog.current_setting('search_path')")->fetchColumn() !== 'pg_catalog') {
            throw new RuntimeException();
        }
        $identity = PgSiteReleaseRegistryGuard::snapshot($this->db);
        $row = $this->db->query(<<<'SQL'
SELECT d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,
 inet_server_port() AS server_port,
 current_setting('default_transaction_read_only') AS default_read_only,
 current_setting('transaction_read_only') AS read_only,
 current_setting('client_encoding')='UTF8' AS utf8,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls,
 has_schema_privilege(session_user,'sites_core','USAGE')
  AND has_function_privilege(session_user,to_regprocedure('sites_core.advance_editorial_revision(uuid,integer,text,text)'),'EXECUTE')
  AND NOT has_function_privilege(session_user,to_regprocedure('sites_core.advance_editorial_revision(uuid,integer,text,text)'),
   'EXECUTE WITH GRANT OPTION')
  AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication')
    AND p.oid<>to_regprocedure('sites_core.advance_editorial_revision(uuid,integer,text,text)')
    AND has_function_privilege(session_user,p.oid,'EXECUTE')) AS editorial_api_only
FROM pg_database d LEFT JOIN pg_stat_ssl s ON s.pid=pg_backend_pid()
WHERE d.datname=current_database()
SQL)->fetch(PDO::FETCH_ASSOC);
        $mode = $readOnly ? 'on' : 'off';
        if (!is_array($row) || $identity['database'] !== $target['database'] ||
            $identity['login'] !== $target['login'] ||
            (string) ($row['database_oid'] ?? '') !== (string) $target['databaseOid'] ||
            ($row['server_address'] ?? null) !== $target['serverAddress'] ||
            (string) ($row['server_port'] ?? '') !== (string) $target['port'] ||
            ($row['default_read_only'] ?? null) !== $mode || ($row['read_only'] ?? null) !== $mode ||
            ($row['utf8'] ?? null) !== true || ($row['tls'] ?? null) !== true ||
            ($row['editorial_api_only'] ?? null) !== true) {
            throw new RuntimeException();
        }
        // Public catalogs establish transport, mode and effective API grants.
        // Only the existing transition checks the private active binding/duty
        // and current editorial head. Planning must not claim those checks.
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
