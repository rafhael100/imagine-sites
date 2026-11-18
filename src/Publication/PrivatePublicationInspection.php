<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Private inspection of one persisted technical snapshot. The CLI supplies a
 * new verify-full connection and the hash of its fixed private CA mount. This
 * class neither opens a connection nor changes SQL settings, state or grants.
 */
final readonly class PrivatePublicationInspection
{
    public function __construct(private PDO $db) {}

    public static function parseManifest(string $json): array
    {
        try {
            if ($json === '' || strlen($json) > 16384 || str_contains($json, "\0")) {
                throw new RuntimeException();
            }
            $manifest = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            self::rejectDuplicateKeys($json);
            return self::validateManifest($manifest);
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_INSPECTION_DENIED');
        }
    }

    /** Coordinates select a target and operation; they confer no authority. */
    public static function validateManifest(array $manifest): array
    {
        try {
            self::keys($manifest, ['schema', 'target', 'operationRef']);
            if ($manifest['schema'] !== 'SITES_PUBLICATION_INSPECTION_V1' ||
                !is_string($manifest['operationRef']) ||
                !PgPublicationJournalGuard::uuid($manifest['operationRef'])) {
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
                'schema' => 'SITES_PUBLICATION_INSPECTION_V1',
                'target' => array_replace(array_fill_keys($targetKeys, null), $target),
                'operationRef' => $manifest['operationRef'],
            ];
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_INSPECTION_DENIED');
        }
    }

    /**
     * Return an explicit allowlist, never the journal's commercial references,
     * idempotency key, provider reference or lease token. Expiration is not a
     * state transition here: only the exact persisted technical state is read.
     */
    public function inspect(array $manifest, string $observedCaSha256): array
    {
        try {
            $input = self::validateManifest($manifest);
            if (!PgPublicationJournalGuard::digest($observedCaSha256) ||
                !hash_equals($input['target']['caSha256'], $observedCaSha256)) {
                throw new RuntimeException();
            }
            $before = $this->observe($input['target']);
            $operation = (new PgPublicationJournal($this->db))->read($input['operationRef']);
            if ($this->observe($input['target']) !== $before || $operation === null) {
                throw new RuntimeException();
            }
            return [
                'schema' => 'SITES_PUBLICATION_INSPECTION_RESULT_V1',
                'status' => 'inspected',
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
                'canPublish' => false,
                'publicationVerified' => false,
            ];
        } catch (Throwable) {
            // No SQL diagnostic, input value, private identifier or exception chain.
            throw new RuntimeException('PUBLICATION_INSPECTION_DENIED');
        }
    }

    /** @return array{database:string,login:string,pid:int} */
    private function observe(array $target): array
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
SELECT d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,
 inet_server_port() AS server_port,
 current_setting('default_transaction_read_only')='on'
  AND current_setting('transaction_read_only')='on' AS read_only,
 current_setting('client_encoding')='UTF8' AS utf8,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls,
 has_schema_privilege(session_user,'sites_publication','USAGE')
  AND has_function_privilege(session_user,to_regprocedure('sites_publication.read_operation(uuid)'),'EXECUTE')
  AND NOT has_function_privilege(session_user,to_regprocedure('sites_publication.read_operation(uuid)'),
   'EXECUTE WITH GRANT OPTION')
  AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication')
    AND p.oid<>to_regprocedure('sites_publication.read_operation(uuid)')
    AND has_function_privilege(session_user,p.oid,'EXECUTE')) AS reader_only
FROM pg_database d LEFT JOIN pg_stat_ssl s ON s.pid=pg_backend_pid()
WHERE d.datname=current_database()
SQL)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $identity['database'] !== $target['database'] ||
            $identity['login'] !== $target['login'] ||
            (string) ($row['database_oid'] ?? '') !== (string) $target['databaseOid'] ||
            ($row['server_address'] ?? null) !== $target['serverAddress'] ||
            (string) ($row['server_port'] ?? '') !== (string) $target['port'] ||
            ($row['read_only'] ?? null) !== true || ($row['utf8'] ?? null) !== true ||
            ($row['tls'] ?? null) !== true || ($row['reader_only'] ?? null) !== true) {
            throw new RuntimeException();
        }
        // This checks effective read-only capabilities, not a private duty label.
        // read_operation independently checks principal validity, epoch, tenant,
        // expiry, ownership, RLS and ACLs under the existing SQL contract.
        return $identity;
    }

    private static function keys(mixed $value, array $expected): void
    {
        if (!is_array($value) || count($value) !== count($expected) ||
            array_diff($expected, array_keys($value))) {
            throw new RuntimeException();
        }
    }

    /** json_decode accepts duplicate keys, including equivalent escaped keys. */
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
