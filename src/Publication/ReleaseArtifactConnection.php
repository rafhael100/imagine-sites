<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use PDO;
use RuntimeException;

/** @internal Fixed target and runtime boundary shared by artifact operations. */
final class ReleaseArtifactConnection
{
    public const ARTIFACT = 'artifact';
    public const BRIEF_ARTIFACT = 'brief_artifact';
    public const BRIEF_RECEIPT = 'brief_receipt';
    public const BRIEF_SNAPSHOT = 'brief_snapshot';

    /** Coordinates identify a reviewed endpoint, never a private tenant or duty. */
    public static function target(mixed $target): array
    {
        $keys = ['host', 'port', 'database', 'databaseOid', 'login', 'serverAddress', 'caSha256'];
        if (!is_array($target) || count($target) !== count($keys) || array_diff($keys, array_keys($target))) {
            throw new RuntimeException();
        }
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
        return array_replace(array_fill_keys($keys, null), $target);
    }

    public static function observedCa(array $target, string $observedCaSha256): void
    {
        if (!self::digest($observedCaSha256) || !hash_equals($target['caSha256'], $observedCaSha256)) {
            throw new RuntimeException();
        }
    }

    /** @return array{database:string,login:string,pid:int} */
    public static function observe(PDO $db, array $target, bool $readOnly, string $api): array
    {
        // API names are a closed internal enumeration, never a SQL selector
        // supplied by a manifest. Each actor must have exactly this entry point.
        $function = match ($api) {
            self::ARTIFACT => 'sites_core.record_release_artifact(uuid,text,text)',
            self::BRIEF_ARTIFACT => 'sites_core.record_brief_release_artifact(text,text,text)',
            self::BRIEF_RECEIPT => 'sites_core.read_brief_release_artifact_receipt(uuid,text,text)',
            self::BRIEF_SNAPSHOT => 'sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)',
            default => throw new RuntimeException(),
        };
        if (in_array($api, [self::BRIEF_RECEIPT, self::BRIEF_SNAPSHOT], true) && !$readOnly) {
            throw new RuntimeException();
        }
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $db->inTransaction() ||
            $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException();
        }
        if ($db->query("SELECT pg_catalog.current_setting('search_path')")->fetchColumn() !== 'pg_catalog') {
            throw new RuntimeException();
        }
        $identity = PgSiteReleaseRegistryGuard::snapshot($db);
        $row = $db->query(<<<SQL
SELECT d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,
 inet_server_port() AS server_port,
 current_setting('default_transaction_read_only') AS default_read_only,
 current_setting('transaction_read_only') AS read_only,
 current_setting('client_encoding')='UTF8' AS utf8,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls,
 has_schema_privilege(session_user,'sites_core','USAGE')
  AND has_function_privilege(session_user,to_regprocedure('$function'),'EXECUTE')
  AND NOT has_function_privilege(session_user,to_regprocedure('$function'),'EXECUTE WITH GRANT OPTION')
  AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication')
    AND p.oid<>to_regprocedure('$function')
    AND has_function_privilege(session_user,p.oid,'EXECUTE')) AS artifact_api_only
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
            ($row['artifact_api_only'] ?? null) !== true) {
            throw new RuntimeException();
        }
        return $identity;
    }

    private static function digest(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
