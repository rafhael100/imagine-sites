<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Private observation of the last persisted release association event.
 * The caller supplies a new verify-full PDO and its fixed private CA hash.
 * No Laravel boot, pool, reconnect, SQL setting or mutable action is involved.
 */
final readonly class PrivateReleaseBindingInspection
{
    private const FUNCTION_SHA256 = '4e3c9069a99223296b0fe95311fe8e5e27bcfd2ef900fb2afae80144b15a6a04';

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
            throw new RuntimeException('RELEASE_BINDING_INSPECTION_DENIED');
        }
    }

    /** Coordinates select a database and site, never a tenant or reader grant. */
    public static function validateManifest(array $manifest): array
    {
        try {
            self::keys($manifest, ['schema', 'target', 'siteRef']);
            if (PHP_INT_SIZE !== 8 || $manifest['schema'] !== 'SITES_RELEASE_BINDING_INSPECTION_V1' ||
                !is_string($manifest['siteRef']) ||
                preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $manifest['siteRef']) !== 1) {
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
                'schema' => 'SITES_RELEASE_BINDING_INSPECTION_V1',
                'target' => array_replace(array_fill_keys($targetKeys, null), $target),
                'siteRef' => $manifest['siteRef'],
            ];
        } catch (Throwable) {
            throw new RuntimeException('RELEASE_BINDING_INSPECTION_DENIED');
        }
    }

    /**
     * The last persisted event helps investigate a lost binding response. It
     * does not prove current eligibility, attribute a call or reserve a CAS.
     * Own projects without an event have four null observation fields. Missing
     * and foreign projects share the same bounded denial. No list or automatic retry runs.
     */
    public function inspect(array $manifest, string $observedCaSha256): array
    {
        try {
            $input = self::validateManifest($manifest);
            if (!self::digest($observedCaSha256) ||
                !hash_equals($input['target']['caSha256'], $observedCaSha256)) {
                throw new RuntimeException();
            }
            $before = $this->observe($input['target']);
            $statement = $this->db->prepare('SELECT * FROM sites_core.read_release_binding_head(?::uuid)');
            $statement->execute([$input['siteRef']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $extra = $statement->fetch(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            if ($this->observe($input['target']) !== $before || !is_array($row) || $extra !== false ||
                array_keys($row) !== ['site_id', 'last_binding_revision', 'last_event_kind',
                    'editorial_revision', 'artifact_digest', 'can_publish', 'can_accept_real_orders'] ||
                $row['site_id'] !== $input['siteRef'] || $row['can_publish'] !== false ||
                $row['can_accept_real_orders'] !== false) {
                throw new RuntimeException();
            }
            if ($row['last_binding_revision'] === null) {
                if ($row['last_event_kind'] !== null || $row['editorial_revision'] !== null ||
                    $row['artifact_digest'] !== null) throw new RuntimeException();
            } elseif (!is_int($row['last_binding_revision']) || $row['last_binding_revision'] < 1 ||
                !in_array($row['last_event_kind'], ['bound', 'revoked'], true) ||
                !is_int($row['editorial_revision']) || $row['editorial_revision'] < 1 ||
                $row['editorial_revision'] > 2147483647 ||
                !is_string($row['artifact_digest']) || !self::digest($row['artifact_digest'])) {
                throw new RuntimeException();
            }
            return [
                'schema' => 'SITES_RELEASE_BINDING_INSPECTION_RESULT_V1',
                'status' => 'inspected',
                'lastBindingRevision' => $row['last_binding_revision'],
                'lastEventKind' => $row['last_event_kind'],
                'editorialRevision' => $row['editorial_revision'],
                'artifactDigest' => $row['artifact_digest'],
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ];
        } catch (Throwable) {
            // No private identifier, database diagnostic, input or error chain.
            throw new RuntimeException('RELEASE_BINDING_INSPECTION_DENIED');
        }
    }

    /** @return array{database:string,login:string,pid:int} */
    private function observe(array $target): array
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $this->db->inTransaction() ||
            $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException();
        }
        if ($this->db->query("SELECT pg_catalog.current_setting('search_path')")->fetchColumn() !== 'pg_catalog') {
            throw new RuntimeException();
        }
        $statement = $this->db->prepare(<<<'SQL'
SELECT current_database() AS database,session_user AS login,pg_backend_pid() AS pid,
 d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,inet_server_port() AS server_port,
 current_user=session_user AND current_setting('role')='none'
  AND current_setting('transaction_isolation')='read committed'
  AND current_setting('row_security')='on'
  AND current_setting('server_version_num')::integer BETWEEN 180000 AND 189999
  AND r.rolcanlogin AND NOT(r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication)
  AND current_setting('default_transaction_read_only')='on'
  AND current_setting('transaction_read_only')='on'
  AND current_setting('client_encoding')='UTF8' AS safe_session,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls,
 p.proowner=to_regrole('sites_core_release_reader') AND p.prosecdef AND p.provolatile='v' AND p.proretset
  AND NOT(p.proleakproof OR p.proisstrict) AND p.proparallel='u'
  AND p.prolang=(SELECT l.oid FROM pg_language l WHERE l.lanname='plpgsql')
  AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
  AND cardinality(p.proconfig)=2
  AND encode(sha256(convert_to(p.prosrc,'UTF8')),'hex')=?
  AND has_schema_privilege(session_user,'sites_core','USAGE')
  AND has_function_privilege(session_user,p.oid,'EXECUTE')
  AND NOT has_function_privilege(session_user,p.oid,'EXECUTE WITH GRANT OPTION')
  AND NOT EXISTS(SELECT 1 FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
   WHERE a.grantee=0 AND a.privilege_type='EXECUTE')
  AND NOT EXISTS(SELECT 1 FROM pg_proc other JOIN pg_namespace n ON n.oid=other.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication') AND other.oid<>p.oid
    AND has_function_privilege(session_user,other.oid,'EXECUTE')) AS reader_only
FROM pg_roles r CROSS JOIN pg_database d
 LEFT JOIN pg_stat_ssl s ON s.pid=pg_backend_pid()
 LEFT JOIN pg_proc p ON p.oid=to_regprocedure('sites_core.read_release_binding_head(uuid)')
WHERE r.rolname=session_user AND d.datname=current_database()
SQL);
        $statement->execute([self::FUNCTION_SHA256]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!is_array($row) || ($row['database'] ?? null) !== $target['database'] ||
            ($row['login'] ?? null) !== $target['login'] || !is_int($row['pid'] ?? null) || $row['pid'] < 1 ||
            (string) ($row['database_oid'] ?? '') !== (string) $target['databaseOid'] ||
            ($row['server_address'] ?? null) !== $target['serverAddress'] ||
            (string) ($row['server_port'] ?? '') !== (string) $target['port'] ||
            ($row['safe_session'] ?? null) !== true || ($row['tls'] ?? null) !== true ||
            ($row['reader_only'] ?? null) !== true) {
            throw new RuntimeException();
        }
        // Catalog/transport checks confer no private reader binding. The pinned
        // SQL function independently enforces current reader, owner, RLS/ACLs
        // and rechecks tenant/duty/epoch/expiry/revocation after fetching the event.
        return ['database' => $row['database'], 'login' => $row['login'], 'pid' => $row['pid']];
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
