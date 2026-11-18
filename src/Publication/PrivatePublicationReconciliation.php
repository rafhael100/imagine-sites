<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Explicit classification of a known expired journal attempt through its API.
 * The caller supplies a new verify-full PDO and the observed private CA hash.
 * No SQL setting, grant, principal, lease, ACK or publication authority is added.
 */
final readonly class PrivatePublicationReconciliation
{
    private const PLAN_SCHEMA = 'SITES_PUBLICATION_RECONCILIATION_PLAN_V1';

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
            throw new RuntimeException('PUBLICATION_RECONCILIATION_DENIED');
        }
    }

    /** Exact operation/CAS selectors, with no tenant, token or provider input. */
    public static function validateManifest(array $manifest): array
    {
        try {
            self::keys($manifest, ['schema', 'target', 'operationRef', 'expectedRevision']);
            if (PHP_INT_SIZE !== 8 || $manifest['schema'] !== 'SITES_PUBLICATION_RECONCILIATION_V1' ||
                !is_string($manifest['operationRef']) || !PgPublicationJournalGuard::uuid($manifest['operationRef']) ||
                !is_int($manifest['expectedRevision']) || $manifest['expectedRevision'] < 1 ||
                $manifest['expectedRevision'] > PHP_INT_MAX - 1) {
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
                !PgPublicationJournalGuard::digest($target['caSha256'])) {
                throw new RuntimeException();
            }
            return [
                'schema' => 'SITES_PUBLICATION_RECONCILIATION_V1',
                'target' => array_replace(array_fill_keys($targetKeys, null), $target),
                'operationRef' => $manifest['operationRef'],
                'expectedRevision' => $manifest['expectedRevision'],
            ];
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_RECONCILIATION_DENIED');
        }
    }

    /**
     * Read-only selectors/runtime plan; no private journal API is invoked.
     * It proves neither current duty/epoch, operation existence, journal head
     * nor lease expiration. Its hash does not depend on a snapshot or clock.
     */
    public function plan(array $manifest, string $observedCaSha256): array
    {
        try {
            [$input, $planSha256] = self::input($manifest, $observedCaSha256);
            $before = $this->observe($input['target'], true);
            if ($this->observe($input['target'], true) !== $before) throw new RuntimeException();
            return [
                'schema' => self::PLAN_SCHEMA,
                'status' => 'planned',
                'planSha256' => $planSha256,
                'expectedRevision' => $input['expectedRevision'],
                'canPublish' => false,
                'publicationVerified' => false,
            ];
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_RECONCILIATION_DENIED');
        }
    }

    /**
     * One journal API call with the reviewed CAS, no retry or outer transaction.
     * SQL decides expiration and current reconciler authority under its lock.
     * An inconclusive replay can already contain a late ACK and need not have
     * the same reconciler/epoch as the original event. Nothing here infers a
     * newly created expiration event, an absent ACK or permission to resend.
     * Failure after the call can follow a commit; it never proves rollback.
     */
    public function apply(array $manifest, string $observedCaSha256, string $expectedPlanSha256): array
    {
        try {
            [$input, $planSha256] = self::input($manifest, $observedCaSha256);
            if (!PgPublicationJournalGuard::digest($expectedPlanSha256) ||
                !hash_equals($planSha256, $expectedPlanSha256)) {
                throw new RuntimeException();
            }
            $before = $this->observe($input['target'], false);
            $operation = (new PgPublicationJournal($this->db))->reconcileExpired(
                $input['operationRef'], $input['expectedRevision'],
            );
            if ($operation['operationRef'] !== $input['operationRef'] ||
                $operation['technicalStatus'] !== 'inconclusive' ||
                $operation['revision'] < $input['expectedRevision'] ||
                $operation['revision'] > $input['expectedRevision'] + 1 ||
                $this->observe($input['target'], false) !== $before) {
                throw new RuntimeException();
            }
            return [
                'schema' => 'SITES_PUBLICATION_RECONCILIATION_RESULT_V1',
                'status' => 'reconciled',
                'planSha256' => $planSha256,
                'expectedRevision' => $input['expectedRevision'],
                'canPublish' => false,
                'publicationVerified' => false,
                'technicalStatus' => $operation['technicalStatus'],
                'revision' => $operation['revision'],
                'attemptNumber' => $operation['attemptNumber'],
                'editorialRevision' => $operation['editorialRevision'],
                'bindingRevision' => $operation['bindingRevision'],
                'artifactDigest' => $operation['artifactDigest'],
                'receiptDigest' => $operation['receiptDigest'],
                'leaseExpiresAt' => $operation['leaseExpiresAt'],
                'createdAt' => $operation['createdAt'],
                'updatedAt' => $operation['updatedAt'],
            ];
        } catch (Throwable) {
            // No SQL diagnostic, private selector, commercial object or cause.
            throw new RuntimeException('PUBLICATION_RECONCILIATION_DENIED');
        }
    }

    /** @return array{array,string} */
    private static function input(array $manifest, string $observedCaSha256): array
    {
        $input = self::validateManifest($manifest);
        if (!PgPublicationJournalGuard::digest($observedCaSha256) ||
            !hash_equals($input['target']['caSha256'], $observedCaSha256)) {
            throw new RuntimeException();
        }
        return [$input, hash('sha256', self::PLAN_SCHEMA."\0".
            json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    /** @return array{database:string,login:string,pid:int} */
    private function observe(array $target, bool $readOnly): array
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $this->db->inTransaction() ||
            $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException();
        }
        // Pin name resolution before the inherited guard's catalog queries.
        if ($this->db->query("SELECT pg_catalog.current_setting('search_path')")->fetchColumn() !== 'pg_catalog') {
            throw new RuntimeException();
        }
        $identity = PgPublicationJournalGuard::snapshot($this->db);
        $row = $this->db->query(<<<'SQL'
WITH api AS (SELECT to_regprocedure('sites_publication.read_operation(uuid)') AS reader,
 to_regprocedure('sites_publication.reconcile_expired(uuid,bigint)') AS reconciler)
SELECT d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,
 inet_server_port() AS server_port,
 current_setting('default_transaction_read_only') AS default_read_only,
 current_setting('transaction_read_only') AS read_only,
 current_setting('client_encoding')='UTF8' AS utf8,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls,
 api.reader IS NOT NULL AND api.reconciler IS NOT NULL
  AND has_schema_privilege(session_user,'sites_publication','USAGE')
  AND has_function_privilege(session_user,api.reader,'EXECUTE')
  AND has_function_privilege(session_user,api.reconciler,'EXECUTE')
  AND NOT has_function_privilege(session_user,api.reader,'EXECUTE WITH GRANT OPTION')
  AND NOT has_function_privilege(session_user,api.reconciler,'EXECUTE WITH GRANT OPTION')
  AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication') AND p.oid NOT IN (api.reader,api.reconciler)
    AND has_function_privilege(session_user,p.oid,'EXECUTE')) AS reconciliation_api_only
FROM pg_database d CROSS JOIN api LEFT JOIN pg_stat_ssl s ON s.pid=pg_backend_pid()
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
            ($row['reconciliation_api_only'] ?? null) !== true) {
            throw new RuntimeException();
        }
        // Public catalogs establish transport, mode and effective API grants.
        // SQL independently checks duty, tenant, epoch, expiry, RLS and CAS.
        return $identity;
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
