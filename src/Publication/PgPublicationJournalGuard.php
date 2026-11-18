<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Connection and response boundaries; SQL independently verifies duties and ownership. */
final class PgPublicationJournalGuard
{
    private const OPERATION_KEYS = [
        'operationRef', 'tenantRef', 'siteRef', 'releaseRef', 'artifactDigest',
        'editorialRevision', 'bindingRevision', 'orderRef', 'contractRef', 'contractRevision',
        'productCode', 'commandDigest', 'portalAcceptanceRef', 'idempotencyKey', 'technicalStatus',
        'revision', 'attemptNumber', 'leaseExpiresAt', 'providerRequestRef', 'receiptDigest',
        'createdAt', 'updatedAt', 'canPublish', 'publicationVerified',
    ];

    /** @return array{database:string,login:string,pid:int} */
    public static function snapshot(PDO $db, bool $readOnly = false): array
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $db->inTransaction() ||
            $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('Publication journal connection unavailable');
        }
        // Only the exact-request reader opts in. Existing journal entry points
        // retain their connection contract and do not acquire a new setting.
        if ($readOnly) {
            $context = $db->query(<<<'SQL'
SELECT pg_catalog.current_setting('default_transaction_read_only') AS default_read_only,
 pg_catalog.current_setting('transaction_read_only') AS transaction_read_only,
 pg_catalog.current_setting('client_encoding') AS client_encoding
SQL)->fetch(PDO::FETCH_ASSOC);
            if (!is_array($context) || ($context['default_read_only'] ?? null) !== 'on' ||
                ($context['transaction_read_only'] ?? null) !== 'on' ||
                ($context['client_encoding'] ?? null) !== 'UTF8') {
                throw new RuntimeException('Publication journal connection unavailable');
            }
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
            !is_string($row['database'] ?? null) || $row['database'] === '' ||
            !is_string($row['login'] ?? null) || $row['login'] === '' ||
            (!is_int($row['pid'] ?? null) && !is_string($row['pid'] ?? null)) ||
            preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $row['pid']) !== 1 ||
            (int) $row['pid'] > 2147483647) {
            throw new RuntimeException('Publication journal connection unavailable');
        }
        return ['database' => $row['database'], 'login' => $row['login'], 'pid' => (int) $row['pid']];
    }

    public static function uuid(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $value) === 1;
    }

    public static function reference(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) === 1;
    }

    public static function digest(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    /** Format validation alone grants no authority and does not establish SQL provenance. */
    public static function operation(mixed $raw): array
    {
        try {
            $row = self::object($raw, self::OPERATION_KEYS);
            foreach (['operationRef', 'siteRef'] as $key) {
                if (!is_string($row[$key]) || !self::uuid($row[$key])) throw new RuntimeException();
            }
            foreach (['tenantRef', 'releaseRef', 'orderRef', 'contractRef', 'portalAcceptanceRef', 'idempotencyKey'] as $key) {
                if (!is_string($row[$key]) || !self::reference($row[$key])) throw new RuntimeException();
            }
            foreach (['artifactDigest', 'commandDigest'] as $key) {
                if (!is_string($row[$key]) || !self::digest($row[$key])) throw new RuntimeException();
            }
            foreach (['editorialRevision', 'bindingRevision', 'contractRevision', 'revision'] as $key) {
                if (!is_int($row[$key]) || $row[$key] < 1) throw new RuntimeException();
            }
            if ($row['editorialRevision'] > 2147483647 ||
                !is_int($row['attemptNumber']) || $row['attemptNumber'] < 0 || $row['attemptNumber'] > 1 ||
                !in_array($row['productCode'], ['onepage', 'landing_page'], true) ||
                !in_array($row['technicalStatus'], ['requested', 'dispatched', 'acknowledged', 'inconclusive'], true) ||
                $row['canPublish'] !== false || $row['publicationVerified'] !== false ||
                !self::timestamp($row['createdAt']) || !self::timestamp($row['updatedAt']) ||
                strcmp($row['updatedAt'], $row['createdAt']) < 0 ||
                ($row['leaseExpiresAt'] !== null && !self::timestamp($row['leaseExpiresAt'])) ||
                ($row['providerRequestRef'] !== null && (!is_string($row['providerRequestRef']) || !self::reference($row['providerRequestRef']))) ||
                ($row['receiptDigest'] !== null && (!is_string($row['receiptDigest']) || !self::digest($row['receiptDigest']))) ||
                (($row['providerRequestRef'] === null) !== ($row['receiptDigest'] === null))) {
                throw new RuntimeException();
            }
            if ($row['technicalStatus'] === 'requested') {
                if ($row['revision'] !== 1 || $row['attemptNumber'] !== 0 ||
                    $row['leaseExpiresAt'] !== null || $row['providerRequestRef'] !== null ||
                    $row['createdAt'] !== $row['updatedAt']) {
                    throw new RuntimeException();
                }
            } else {
                if ($row['attemptNumber'] !== 1 || $row['leaseExpiresAt'] === null ||
                    strcmp($row['leaseExpiresAt'], $row['createdAt']) <= 0) {
                    throw new RuntimeException();
                }
                if ($row['technicalStatus'] === 'dispatched' &&
                    ($row['revision'] !== 2 || $row['providerRequestRef'] !== null ||
                        strcmp($row['updatedAt'], $row['leaseExpiresAt']) >= 0)) {
                    throw new RuntimeException();
                }
                if ($row['technicalStatus'] === 'acknowledged' &&
                    ($row['revision'] !== 3 || $row['providerRequestRef'] === null ||
                        strcmp($row['updatedAt'], $row['leaseExpiresAt']) >= 0)) {
                    throw new RuntimeException();
                }
                if ($row['technicalStatus'] === 'inconclusive' &&
                    (!in_array($row['revision'], [3, 4], true) ||
                        ($row['revision'] === 4 && $row['providerRequestRef'] === null) ||
                        strcmp($row['updatedAt'], $row['leaseExpiresAt']) < 0)) {
                    throw new RuntimeException();
                }
            }
            // Canonical key order is useful to callers; no additional claim is added.
            return array_replace(array_fill_keys(self::OPERATION_KEYS, null), $row);
        } catch (Throwable) {
            throw new RuntimeException('Publication journal response unavailable');
        }
    }

    /** @return array{operation:array,leaseToken:string} */
    public static function attempt(mixed $raw): array
    {
        try {
            $row = self::object($raw, ['operation', 'leaseToken']);
            if (!is_array($row['operation']) || !is_string($row['leaseToken']) || !self::uuid($row['leaseToken'])) {
                throw new RuntimeException();
            }
            $operation = self::operation(json_encode($row['operation'], JSON_THROW_ON_ERROR));
            if ($operation['technicalStatus'] !== 'dispatched') throw new RuntimeException();
            return ['operation' => $operation, 'leaseToken' => $row['leaseToken']];
        } catch (Throwable) {
            throw new RuntimeException('Publication journal response unavailable');
        }
    }

    private static function object(mixed $raw, array $keys): array
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 8192) throw new RuntimeException();
        $row = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($row) || count($row) !== count($keys) || array_diff($keys, array_keys($row))) {
            throw new RuntimeException();
        }
        return $row;
    }

    private static function timestamp(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $value) !== 1 ||
            str_starts_with($value, '0000-')) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s.u\Z') === $value;
    }
}
