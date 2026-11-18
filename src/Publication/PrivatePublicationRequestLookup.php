<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Internal, read-only recovery of a technical publication request when its
 * operation UUID/receipt is missing. Caller provides a new verify-full PDO and
 * reviewed target/CA. This is not a publisher, provider or external endpoint.
 */
final readonly class PrivatePublicationRequestLookup
{
    public function __construct(private PDO $db) {}

    /**
     * Return the exact 24-field technical snapshot including the historical
     * operationRef for private reconciliation. Never expose this directly to a
     * customer or interpret ACK as an observed publication.
     */
    public function readExact(
        array $target,
        string $observedCaSha256,
        string $siteRef,
        string $releaseRef,
        int $bindingRevision,
        string $portalAcceptanceRef,
        string $idempotencyKey,
    ): array {
        try {
            $target = ReleaseArtifactConnection::target($target);
            ReleaseArtifactConnection::observedCa($target, $observedCaSha256);
            if (!PgPublicationJournalGuard::uuid($siteRef) ||
                !PgPublicationJournalGuard::reference($releaseRef) ||
                $bindingRevision < 1 ||
                !PgPublicationJournalGuard::reference($portalAcceptanceRef) ||
                !PgPublicationJournalGuard::reference($idempotencyKey)) {
                throw new RuntimeException();
            }
            $identity = $this->observe($target);
            $operation = (new PgPublicationJournal($this->db))->readByRequest(
                $siteRef, $releaseRef, $bindingRevision, $portalAcceptanceRef, $idempotencyKey,
            );
            if ($operation === null || $this->observe($target) !== $identity ||
                $operation['siteRef'] !== $siteRef ||
                $operation['releaseRef'] !== $releaseRef ||
                $operation['bindingRevision'] !== $bindingRevision ||
                $operation['portalAcceptanceRef'] !== $portalAcceptanceRef ||
                $operation['idempotencyKey'] !== $idempotencyKey ||
                $operation['canPublish'] !== false ||
                $operation['publicationVerified'] !== false) throw new RuntimeException();
            return $operation;
        } catch (Throwable) {
            // No private selector, SQL detail, operation UUID or previous error.
            throw new RuntimeException('PUBLICATION_LOOKUP_DENIED');
        }
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
        $identity = PgPublicationJournalGuard::snapshot($this->db);
        $row = $this->db->query(<<<'SQL'
SELECT d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,
 inet_server_port() AS server_port,
 current_setting('default_transaction_read_only')='on'
  AND current_setting('transaction_read_only')='on' AS read_only,
 current_setting('client_encoding')='UTF8' AS utf8,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls,
 has_schema_privilege(session_user,'sites_publication','USAGE')
  AND has_function_privilege(session_user,
   to_regprocedure('sites_publication.read_request(uuid,text,bigint,text,text)'),'EXECUTE')
  AND NOT has_function_privilege(session_user,
   to_regprocedure('sites_publication.read_request(uuid,text,bigint,text,text)'),
   'EXECUTE WITH GRANT OPTION')
  AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication')
    AND p.oid<>to_regprocedure('sites_publication.read_request(uuid,text,bigint,text,text)')
    AND has_function_privilege(session_user,p.oid,'EXECUTE')) AS reader_only
FROM pg_database d LEFT JOIN pg_stat_ssl s ON s.pid=pg_backend_pid()
WHERE d.datname=current_database()
SQL)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $identity['database'] !== $target['database'] ||
            $identity['login'] !== $target['login'] ||
            (string) ($row['database_oid'] ?? '') !== (string) $target['databaseOid'] ||
            $row['server_address'] !== $target['serverAddress'] ||
            (string) ($row['server_port'] ?? '') !== (string) $target['port'] ||
            ($row['read_only'] ?? null) !== true ||
            ($row['utf8'] ?? null) !== true || ($row['tls'] ?? null) !== true ||
            ($row['reader_only'] ?? null) !== true) throw new RuntimeException();
        return $identity;
    }
}
