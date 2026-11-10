<?php

declare(strict_types=1);

namespace Imagine\Sites\Persistence;

use DomainException;
use Imagine\Sites\Integration\DenyingPortalProjectAuthorityGateway;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier;
use PDO;
use PDOException;
use Throwable;

/**
 * F1-96: read ONLY the latest canonical private draft from a real PostgreSQL
 * lab revision ledger, checking its original admission and on-disk bytes.
 * This is an internal, fictitious CLI read, NOT Portal/guest preview access.
 */
final class LabVerifiedLatestDraftReader
{
    public function __construct(
        private readonly PDO $db,
        private readonly PortalProjectAuthorityGateway $authority =
            new DenyingPortalProjectAuthorityGateway(),
    ) {}

    /** @return array<string,mixed> */
    public function read(SiteProjectCommand $command, string $directory): array
    {
        if (PHP_SAPI !== 'cli' ||
            getenv('APP_ENV') !== 'testing' ||
            getenv('SITES_F94_PROJECT_ADMISSION_LAB') !== '1' ||
            getenv('SITES_F95_DRAFT_LEDGER_LAB') !== '1' ||
            getenv('SITES_F96_LATEST_READBACK_LAB') !== '1' ||
            $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' ||
            $this->db->inTransaction()) {
            throw new DomainException('lab_latest_draft_reader_disabled');
        }

        // Revalidate authority for every read. A correct hash is not an
        // identity token and is not a substitute for a current Portal grant.
        try {
            if (!(new SiteProjectAdmission($this->authority))
                ->evaluate($command)->allowed) {
                throw new DomainException('lab_latest_draft_reader_denied');
            }
        } catch (Throwable $e) {
            throw new DomainException('lab_latest_draft_reader_denied',previous:$e);
        }

        $proof=SyntheticPrivateDraftVerifier::verifyDirectory($directory);
        $site=LabAuthorizedProjectAdmissionStore::siteRefFor($command);
        if ($proof['tenantRef'] !== $command->tenantRef ||
            $proof['siteRef'] !== $site ||
            $proof['verifiedPrivateLabFiles'] !== true ||
            $proof['reviewerDecisionStatus'] !== 'pending' ||
            $proof['officialClientAcceptance'] !== false ||
            $proof['publishable'] !== false) {
            throw new DomainException('lab_latest_draft_reader_private_source_mismatch');
        }

        $dbName=(string)$this->db->query('SELECT current_database()')->fetchColumn();
        $role=$this->db->query(
            'SELECT current_user,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole
             FROM pg_roles WHERE rolname=current_user'
        )->fetch(PDO::FETCH_ASSOC);
        $isFalse=static fn(mixed $v):bool => in_array($v,[false,'f','0'],true);
        if ($dbName !== 'sites_f94_lab' || !is_array($role) ||
            ($role['current_user'] ?? null) !== 'sites_f94_app' ||
            !$isFalse($role['rolsuper'] ?? null) ||
            !$isFalse($role['rolbypassrls'] ?? null) ||
            !$isFalse($role['rolcreatedb'] ?? null) ||
            !$isFalse($role['rolcreaterole'] ?? null)) {
            throw new DomainException('lab_latest_draft_reader_privileged_role_denied');
        }

        try {
            $this->db->beginTransaction();
            $set=$this->db->prepare(
                "SELECT set_config('imagine_sites.tenant_ref',:tenant,true)"
            );
            $set->execute(['tenant'=>$command->tenantRef]);
            if (!hash_equals($command->tenantRef,(string)$set->fetchColumn())) {
                throw new DomainException('lab_latest_draft_reader_context_failure');
            }

            // F1-98: cooperate with the F1-95 exclusive writer lock.
            // Concurrent readers may share this lock, but an append using
            // the same tenant/site key cannot interleave with this readback.
            // This is not a filesystem lease or a distributed snapshot.
            $lock = $this->db->prepare(
                'SELECT pg_advisory_xact_lock_shared(
                    hashtextextended(:tenant_site, 0))'
            );
            $lock->execute([
                'tenant_site' => strlen($command->tenantRef).':'.$command->tenantRef.':'.$site,
            ]);
            $lock->closeCursor();

            // A scoped admission is required even for reads. A row with a
            // matching siteRef alone cannot establish a trusted order link.
            $admission=$this->db->prepare(
                'SELECT a.operation_id,a.idempotency_key,a.order_ref,
                        a.contract_ref,a.contract_revision,a.product_code,
                        a.semantic_fingerprint,p.lifecycle
                 FROM sites_project_admissions a
                 JOIN sites_projects p
                 ON p.tenant_ref=a.tenant_ref AND p.site_ref=a.site_ref
                 WHERE a.tenant_ref=:tenant AND a.site_ref=:site'
            );
            $admission->execute([
                'tenant'=>$command->tenantRef,'site'=>$site
            ]);
            $record=$admission->fetch(PDO::FETCH_ASSOC);
            if ($record === false ||
                $record['operation_id'] !== $command->operationId ||
                $record['idempotency_key'] !== $command->idempotencyKey ||
                $record['order_ref'] !== $command->orderRef ||
                $record['contract_ref'] !== $command->contractRef ||
                (int)$record['contract_revision'] !== $command->contractRevision ||
                $record['product_code'] !== $command->productCode ||
                !hash_equals(
                    $record['semantic_fingerprint'],$command->semanticFingerprint()
                ) ||
                !in_array($record['lifecycle'],['draft','internal_review'],true)) {
                throw new DomainException('lab_latest_draft_reader_project_not_admitted');
            }

            $latest=$this->db->prepare(
                'SELECT draft_revision,workflow_ref,release_ref,
                        artifact_digest,dossier_digest,review_state
                 FROM sites_drafts
                 WHERE tenant_ref=:tenant AND site_ref=:site
                 ORDER BY draft_revision DESC LIMIT 1'
            );
            $latest->execute(['tenant'=>$command->tenantRef,'site'=>$site]);
            $row=$latest->fetch(PDO::FETCH_ASSOC);
            if ($row === false ||
                (int)$row['draft_revision'] < 1 ||
                $row['workflow_ref'] !== $proof['workflowRef'] ||
                $row['release_ref'] !== $proof['releaseRef'] ||
                !hash_equals($row['artifact_digest'],$proof['artifactDigest']) ||
                !hash_equals($row['dossier_digest'],$proof['dossierDigest']) ||
                $row['review_state'] !== 'pending_internal') {
                throw new DomainException('lab_latest_draft_reader_persisted_record_mismatch');
            }

            // A second independent read detects ordinary changes between the
            // first file verification and the database lookup. Not a lease,
            // remote storage guarantee or distributed atomic snapshot.
            if (SyntheticPrivateDraftVerifier::verifyDirectory($directory) !== $proof) {
                throw new DomainException('lab_latest_draft_reader_files_changed');
            }
            $this->db->commit();
            return [
                'schemaVersion'=>'sites.synthetic.latest-verified-draft.v1',
                'tenantRef'=>$command->tenantRef,
                'siteRef'=>$site,
                'draftRevision'=>(int)$row['draft_revision'],
                'workflowRef'=>$proof['workflowRef'],
                'releaseRef'=>$proof['releaseRef'],
                'artifactDigest'=>$proof['artifactDigest'],
                'dossierDigest'=>$proof['dossierDigest'],
                'storedRowAndPrivateFilesMatch'=>true,
                'internalReviewRequired'=>true,
                'externalPortalAuthorityRequired'=>true,
                'officialClientAcceptance'=>false,
                'publishable'=>false,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            if ($e instanceof DomainException)throw $e;
            throw new DomainException(
                $e instanceof PDOException
                    ? 'lab_latest_draft_reader_database_denied'
                    : 'lab_latest_draft_reader_fail_closed',
                previous:$e
            );
        }
    }
}
