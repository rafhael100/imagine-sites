<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only reconciliation by five original request selectors. No request,
 * attempt, acknowledgement, provider call, resend or publication authority.
 */
final readonly class PrivatePublicationRequestRecovery
{
    private const PLAN_SCHEMA = 'SITES_PUBLICATION_REQUEST_RECOVERY_PLAN_V1';

    public function __construct(private PDO $db) {}

    /**
     * Reuses the exact original request envelope and its strict duplicate-key
     * parser. A recovery reader supplies its own target LOGIN and receiptRef.
     */
    public static function parseManifest(string $json): array
    {
        try {
            return PrivatePublicationRequest::parseManifest($json);
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_RECOVERY_DENIED');
        }
    }

    public function plan(array $input, string $observedCaSha256): array
    {
        try {
            [$manifest, $planHash] = self::input($input, $observedCaSha256);
            $before = $this->observe($manifest['target']);
            if ($this->observe($manifest['target']) !== $before) throw new RuntimeException();
            return [
                'schema' => self::PLAN_SCHEMA,
                'status' => 'planned',
                'planSha256' => $planHash,
                'expectedBindingRevision' => $manifest['expectedBindingRevision'],
                'canPublish' => false,
                'publicationVerified' => false,
            ];
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_RECOVERY_DENIED');
        }
    }

    /** Returns the public projection and a SEPARATE strictly private receipt. */
    public function recover(array $input, string $observedCaSha256, string $expectedPlanSha256): array
    {
        try {
            [$manifest, $planHash] = self::input($input, $observedCaSha256);
            if (!PgPublicationJournalGuard::digest($expectedPlanSha256) ||
                !hash_equals($planHash, $expectedPlanSha256)) throw new RuntimeException();
            $before = $this->observe($manifest['target']);
            $operation = (new PgPublicationJournal($this->db))->readRequest(
                $manifest['siteRef'],
                $manifest['releaseRef'],
                $manifest['expectedBindingRevision'],
                $manifest['portalAcceptanceRef'],
                $manifest['idempotencyKey'],
            );
            if ($this->observe($manifest['target']) !== $before ||
                $operation['siteRef'] !== $manifest['siteRef'] ||
                $operation['releaseRef'] !== $manifest['releaseRef'] ||
                $operation['bindingRevision'] !== $manifest['expectedBindingRevision'] ||
                $operation['portalAcceptanceRef'] !== $manifest['portalAcceptanceRef'] ||
                $operation['idempotencyKey'] !== $manifest['idempotencyKey'] ||
                $operation['canPublish'] !== false ||
                $operation['publicationVerified'] !== false) throw new RuntimeException();
            return [
                'result' => [
                    'schema' => 'SITES_PUBLICATION_REQUEST_RECOVERY_RESULT_V1',
                    'status' => 'recovered',
                    'planSha256' => $planHash,
                    'technicalStatus' => $operation['technicalStatus'],
                    'revision' => $operation['revision'],
                    'attemptNumber' => $operation['attemptNumber'],
                    'bindingRevision' => $operation['bindingRevision'],
                    'artifactDigest' => $operation['artifactDigest'],
                    'receiptDigest' => $operation['receiptDigest'],
                    'createdAt' => $operation['createdAt'],
                    'updatedAt' => $operation['updatedAt'],
                    'canPublish' => false,
                    'publicationVerified' => false,
                ],
                'receipt' => [
                    'schema' => 'SITES_PUBLICATION_REQUEST_RECOVERY_RECEIPT_V1',
                    'planSha256' => $planHash,
                    'operationRef' => $operation['operationRef'],
                ],
            ];
        } catch (Throwable) {
            // In particular, a failed lookup cannot justify a writer retry.
            throw new RuntimeException('PUBLICATION_RECOVERY_DENIED');
        }
    }

    private static function input(array $manifest, string $caSha256): array
    {
        $parsed = PrivatePublicationRequest::validateManifest($manifest);
        if (!PgPublicationJournalGuard::digest($caSha256) ||
            !hash_equals($parsed['target']['caSha256'], $caSha256)) throw new RuntimeException();
        return [$parsed, hash('sha256', self::PLAN_SCHEMA."\0".
            json_encode($parsed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    /** @return array{database:string,login:string,pid:int} */
    private function observe(array $target): array
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' ||
            $this->db->inTransaction() ||
            $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION ||
            $this->db->query("SELECT pg_catalog.current_setting('search_path')")->fetchColumn() !== 'pg_catalog') {
            throw new RuntimeException();
        }
        $identity = PgPublicationJournalGuard::snapshot($this->db, true);
        $row = $this->db->query(<<<'SQL'
SELECT d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,
 inet_server_port() AS server_port,
 current_setting('default_transaction_read_only')='on'
  AND current_setting('transaction_read_only')='on' AS read_only,
 current_setting('client_encoding')='UTF8' AS utf8,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls,
 has_schema_privilege(session_user,'sites_publication','USAGE')
  AND has_function_privilege(session_user,
   to_regprocedure('sites_publication.read_request_operation(uuid,text,bigint,text,text)'),'EXECUTE')
  AND NOT has_function_privilege(session_user,
   to_regprocedure('sites_publication.read_request_operation(uuid,text,bigint,text,text)'),
   'EXECUTE WITH GRANT OPTION')
  AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication')
    AND p.oid<>to_regprocedure('sites_publication.read_request_operation(uuid,text,bigint,text,text)')
    AND has_function_privilege(session_user,p.oid,'EXECUTE')) AS reader_only
FROM pg_database d LEFT JOIN pg_stat_ssl s ON s.pid=pg_backend_pid()
WHERE d.datname=current_database()
SQL)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $identity['database'] !== $target['database'] ||
            $identity['login'] !== $target['login'] ||
            (string) ($row['database_oid'] ?? '') !== (string) $target['databaseOid'] ||
            ($row['server_address'] ?? null) !== $target['serverAddress'] ||
            (string) ($row['server_port'] ?? '') !== (string) $target['port'] ||
            ($row['read_only'] ?? null) !== true ||
            ($row['utf8'] ?? null) !== true || ($row['tls'] ?? null) !== true ||
            ($row['reader_only'] ?? null) !== true) throw new RuntimeException();
        return $identity;
    }
}
