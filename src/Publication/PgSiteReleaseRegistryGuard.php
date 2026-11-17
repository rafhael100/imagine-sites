<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use PDO;
use RuntimeException;

/** Connection boundary; SQL entry points independently enforce grants and ownership. */
final class PgSiteReleaseRegistryGuard
{
    /** @return array{database:string,login:string,pid:int} */
    public static function snapshot(PDO $db): array
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $db->inTransaction() ||
            $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('Release registry connection unavailable');
        }
        $row = $db->query(<<<'SQL'
SELECT current_database() AS database,session_user AS login,pg_backend_pid() AS pid,
 current_user=session_user AND current_setting('role')='none'
 AND current_setting('transaction_isolation')='read committed'
 AND current_setting('row_security')='on'
 AND current_setting('server_version_num')::integer BETWEEN 180000 AND 189999
 AND r.rolcanlogin AND NOT(r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication) AS safe
FROM pg_roles r WHERE r.rolname=session_user
SQL)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['safe'] ?? null) !== true ||
            !is_string($row['database'] ?? null) || !is_string($row['login'] ?? null) ||
            !is_numeric($row['pid'] ?? null) || (int) $row['pid'] < 1) {
            throw new RuntimeException('Release registry connection unavailable');
        }
        return ['database' => $row['database'], 'login' => $row['login'], 'pid' => (int) $row['pid']];
    }

    public static function siteId(string $site): bool
    {
        return preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $site) === 1;
    }

    public static function reference(string $reference): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $reference) === 1;
    }

    public static function binding(mixed $raw): SiteCommercialReleaseBinding
    {
        if (!is_string($raw) || strlen($raw) > 4096) {
            throw new RuntimeException('Release registry response unavailable');
        }
        $row = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $keys = ['tenantRef', 'orderRef', 'contractRef', 'contractRevision', 'productCode',
            'siteRef', 'releaseRef', 'artifactDigest', 'editorialRevision', 'bindingRevision'];
        if (!is_array($row) || count($row) !== count($keys) || array_diff($keys, array_keys($row))) {
            throw new RuntimeException('Release registry response unavailable');
        }
        foreach ($keys as $key) {
            $valid = in_array($key, ['contractRevision', 'editorialRevision', 'bindingRevision'], true)
                ? is_int($row[$key]) && $row[$key] > 0 : is_string($row[$key]);
            if (!$valid) {
                throw new RuntimeException('Release registry response unavailable');
            }
        }
        return new SiteCommercialReleaseBinding(
            $row['tenantRef'], $row['orderRef'], $row['contractRef'], $row['contractRevision'],
            $row['productCode'], $row['siteRef'], $row['releaseRef'], $row['artifactDigest'],
            $row['editorialRevision'], $row['bindingRevision'],
        );
    }
}
