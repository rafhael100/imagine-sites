<?php

declare(strict_types=1);

namespace Imagine\Sites\Persistence;

use DomainException;
use Imagine\Sites\Integration\DenyingPortalProjectAuthorityGateway;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\SyntheticInternalReviewDossier;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly;
use PDO;
use PDOException;
use Throwable;

/**
 * F1-95: append a verified PRIVATE draft's exact metadata to the real Laravel
 * PG18 sites_drafts table; no contents are copied to the database.
 *
 * Pure CLI + synthetic gateway + disposable DB; NOT an HTTP service, actual
 * identity verifier, official approval, content storage or publish action.
 */
final class LabVerifiedDraftRevisionStore
{
    public function __construct(
        private readonly PDO $db,
        private readonly PortalProjectAuthorityGateway $authority =
            new DenyingPortalProjectAuthorityGateway(),
    ) {}

    /** @return array<string,mixed> */
    public function append(
        SiteProjectCommand $command,
        SyntheticStructuredDraftAssembly $draft,
        string $directory,
        int $expectedRevision
    ): array {
        if (PHP_SAPI !== 'cli' ||
            getenv('APP_ENV') !== 'testing' ||
            getenv('SITES_F94_PROJECT_ADMISSION_LAB') !== '1' ||
            getenv('SITES_F95_DRAFT_LEDGER_LAB') !== '1' ||
            $expectedRevision < 1 || $expectedRevision > 10000 ||
            $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' ||
            $this->db->inTransaction()) {
            throw new DomainException('lab_draft_revision_disabled_or_invalid');
        }

        // The EXACT site from a previously authorized tenant/order/contract
        // must be the draft site. No client-chosen site, tenant or product.
        $siteRef = LabAuthorizedProjectAdmissionStore::siteRefFor($command);
        $dossier = SyntheticInternalReviewDossier::fromDraft($draft);
        $proof = SyntheticPrivateDraftVerifier::verifyDirectory($directory);
        if ($draft->brief->productCode !== $command->productCode ||
            $draft->workflow->state !== SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $draft->workflow->revision !== 2 ||
            $draft->workflow->tenantRef !== $command->tenantRef ||
            $draft->workflow->siteRef !== $siteRef ||
            $proof['verifiedPrivateLabFiles'] !== true ||
            $proof['tenantRef'] !== $command->tenantRef ||
            $proof['siteRef'] !== $siteRef ||
            $proof['workflowRef'] !== $draft->workflow->workflowRef ||
            $proof['releaseRef'] !== $draft->manifest->releaseRef ||
            $proof['artifactDigest'] !== $draft->artifact->digest ||
            $proof['bundleDigest'] !== $draft->bundle->bundleDigest ||
            $proof['dossierDigest'] !== $dossier->digest ||
            $proof['reviewerDecisionStatus'] !== 'pending' ||
            $proof['officialClientAcceptance'] !== false ||
            $proof['publishable'] !== false) {
            throw new DomainException('lab_draft_revision_source_mismatch');
        }

        // Recheck current authority even for an exact replay. The default
        // gateway denies; test authority never grants customer approval.
        try {
            if (!(new SiteProjectAdmission($this->authority))
                ->evaluate($command)->allowed) {
                throw new DomainException('lab_draft_revision_authority_denied');
            }
        } catch (Throwable $e) {
            throw new DomainException('lab_draft_revision_authority_denied', previous: $e);
        }

        // Refuse a privileged or unexpected DB role. Do not expose this login
        // to browsers, SQL clients, agents, Portal customers or webhooks.
        $dbName = (string)$this->db->query('SELECT current_database()')->fetchColumn();
        $role = $this->db->query(
            'SELECT current_user, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole
             FROM pg_roles WHERE rolname = current_user'
        )->fetch(PDO::FETCH_ASSOC);
        $isFalse = static fn (mixed $v): bool => in_array($v, [false,'f','0'], true);
        if ($dbName !== 'sites_f94_lab' ||
            !is_array($role) ||
            ($role['current_user'] ?? null) !== 'sites_f94_app' ||
            !$isFalse($role['rolsuper'] ?? null) ||
            !$isFalse($role['rolbypassrls'] ?? null) ||
            !$isFalse($role['rolcreatedb'] ?? null) ||
            !$isFalse($role['rolcreaterole'] ?? null)) {
            throw new DomainException('lab_draft_revision_requires_restricted_db_login');
        }

        try {
            $this->db->beginTransaction();
            $context = $this->db->prepare(
                "SELECT set_config('imagine_sites.tenant_ref', :tenant, true)"
            );
            $context->execute(['tenant' => $command->tenantRef]);
            if (!hash_equals($command->tenantRef,(string)$context->fetchColumn())) {
                throw new DomainException('lab_draft_revision_context_failed');
            }

            // Per tenant/site transaction advisory lock serializes revisions
            // among callers that obey this service, even with two PDO handles.
            $lock = $this->db->prepare(
                'SELECT pg_advisory_xact_lock(
                    hashtextextended(:tenant_site, 0))'
            );
            $lock->execute([
                'tenant_site' => strlen($command->tenantRef).':'.$command->tenantRef.':'.$siteRef,
            ]);
            $lock->closeCursor();

            // Project must exist and belong to the original exact authorized
            // operation. A pre-existing project ID alone proves nothing.
            $admitted = $this->db->prepare(
                'SELECT a.operation_id, a.idempotency_key, a.order_ref,
                        a.contract_ref, a.contract_revision, a.product_code,
                        a.semantic_fingerprint, p.lifecycle
                 FROM sites_project_admissions a
                 JOIN sites_projects p
                   ON p.tenant_ref=a.tenant_ref AND p.site_ref=a.site_ref
                 WHERE a.tenant_ref=:tenant AND a.site_ref=:site'
            );
            $admitted->execute(['tenant'=>$command->tenantRef,'site'=>$siteRef]);
            $entry=$admitted->fetch(PDO::FETCH_ASSOC);
            if ($entry === false ||
                $entry['operation_id'] !== $command->operationId ||
                $entry['idempotency_key'] !== $command->idempotencyKey ||
                $entry['order_ref'] !== $command->orderRef ||
                $entry['contract_ref'] !== $command->contractRef ||
                (int)$entry['contract_revision'] !== $command->contractRevision ||
                $entry['product_code'] !== $command->productCode ||
                !hash_equals(
                    (string)$entry['semantic_fingerprint'],
                    $command->semanticFingerprint()
                ) ||
                !in_array($entry['lifecycle'],['draft','internal_review'],true)) {
                throw new DomainException('lab_draft_revision_project_not_admitted');
            }

            $last=$this->db->prepare(
                'SELECT draft_revision,workflow_ref,release_ref,
                        artifact_digest,dossier_digest,review_state
                 FROM sites_drafts
                 WHERE tenant_ref=:tenant AND site_ref=:site
                 ORDER BY draft_revision DESC LIMIT 1'
            );
            $last->execute(['tenant'=>$command->tenantRef,'site'=>$siteRef]);
            $latest=$last->fetch(PDO::FETCH_ASSOC);
            if ($latest !== false &&
                (int)$latest['draft_revision'] === $expectedRevision) {
                if ($latest['workflow_ref'] !== $draft->workflow->workflowRef ||
                    $latest['release_ref'] !== $draft->manifest->releaseRef ||
                    !hash_equals($latest['artifact_digest'],$proof['artifactDigest']) ||
                    !hash_equals($latest['dossier_digest'],$proof['dossierDigest']) ||
                    $latest['review_state'] !== 'pending_internal') {
                    throw new DomainException('lab_draft_revision_idempotency_conflict');
                }
                // F1-97: an idempotent retry must also re-open private bytes
                // inside the transaction. The first proof was taken before
                // authority and database checks; never report a stale match
                // if its package changed during those checks.
                if (SyntheticPrivateDraftVerifier::verifyDirectory($directory) !== $proof) {
                    throw new DomainException('lab_draft_revision_replay_bytes_changed');
                }
                // F1-101: a retry must not rely on the grant observed before
                // querying the ledger and checking private files.
                $this->assertFinalAuthority($command);
                $this->db->commit();
                return self::result($command, $draft, $proof, $expectedRevision, true);
            }
            $required=($latest === false ? 1 : (int)$latest['draft_revision']+1);
            if ($expectedRevision !== $required) {
                throw new DomainException('lab_draft_revision_must_be_sequential');
            }

            // Reread bytes while still in the transaction; the filesystem
            // itself is NOT a transactional immutable blob store or lease.
            if (SyntheticPrivateDraftVerifier::verifyDirectory($directory) !== $proof) {
                throw new DomainException('lab_draft_revision_private_bytes_changed');
            }

            $insert=$this->db->prepare(
                'INSERT INTO sites_drafts
                    (tenant_ref,site_ref,draft_revision,workflow_ref,
                     release_ref,artifact_digest,dossier_digest,review_state)
                 VALUES
                    (:tenant,:site,:revision,:workflow,:release,
                     :artifact,:dossier,:state)'
            );
            $insert->execute([
                'tenant'=>$command->tenantRef,
                'site'=>$siteRef,
                'revision'=>$expectedRevision,
                'workflow'=>$draft->workflow->workflowRef,
                'release'=>$draft->manifest->releaseRef,
                'artifact'=>$proof['artifactDigest'],
                'dossier'=>$proof['dossierDigest'],
                'state'=>'pending_internal',
            ]);
            // Fail closed if the mock grant is revoked before the write
            // becomes durable. A denial rolls back the inserted row.
            $this->assertFinalAuthority($command);
            $this->db->commit();
            return self::result($command, $draft, $proof, $expectedRevision, false);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof DomainException) throw $e;
            throw new DomainException(
                $e instanceof PDOException
                    ? 'lab_draft_revision_pg_constraint_or_concurrency_denied'
                    : 'lab_draft_revision_write_failed',
                previous: $e
            );
        }
    }

    /**
     * F1-101: second independent Portal-candidate decision immediately
     * before COMMIT for both new revisions and exact idempotent replays.
     * This is synthetic, not an operational authenticated IAM adapter.
     */
    private function assertFinalAuthority(SiteProjectCommand $command): void
    {
        try {
            if (!(new SiteProjectAdmission($this->authority))
                ->evaluate($command)->allowed) {
                throw new DomainException('lab_draft_revision_final_authority_denied');
            }
        } catch (Throwable $e) {
            throw new DomainException(
                'lab_draft_revision_final_authority_denied',
                previous: $e
            );
        }
    }

    /** @param array<string,mixed> $proof @return array<string,mixed> */
    private static function result(
        SiteProjectCommand $command,
        SyntheticStructuredDraftAssembly $draft,
        array $proof,
        int $revision,
        bool $replay
    ): array {
        return [
            'schemaVersion'=>'sites.synthetic.persisted-draft-revision.v1',
            'tenantRef'=>$command->tenantRef,
            'siteRef'=>$draft->workflow->siteRef,
            'draftRevision'=>$revision,
            'workflowRef'=>$draft->workflow->workflowRef,
            'releaseRef'=>$draft->manifest->releaseRef,
            'artifactDigest'=>$proof['artifactDigest'],
            'dossierDigest'=>$proof['dossierDigest'],
            'verifiedPrivateLabFiles'=>true,
            'idempotentReplay'=>$replay,
            'internalReviewRequired'=>true,
            'portalIdentityOperational'=>false,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
        ];
    }
}
