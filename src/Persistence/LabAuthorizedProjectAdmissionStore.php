<?php

declare(strict_types=1);

namespace Imagine\Sites\Persistence;

use DomainException;
use Imagine\Sites\Integration\DenyingPortalProjectAuthorityGateway;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Integration\SiteProjectCommand;
use PDO;
use PDOException;
use Throwable;

/**
 * F1-94: laboratory-only, transactional project admission.
 *
 * No public endpoint and NO production identity. Authority is a mandatory,
 * independently provided gateway; the default denies. A tenant GUC does not
 * authenticate the caller and the DB login must never be exposed to clients.
 */
final class LabAuthorizedProjectAdmissionStore
{
    public function __construct(
        private readonly PDO $db,
        private readonly PortalProjectAuthorityGateway $authority =
            new DenyingPortalProjectAuthorityGateway(),
    ) {}

    /**
     * A fictional site reference is MINTED from the authorized contract and
     * order identity. It is an opaque identifier, NEVER an authorization proof.
     * Future real Site IDs must be issued by an authenticated Sites service.
     */
    public static function siteRefFor(SiteProjectCommand $command): string
    {
        return 'site-'.substr(hash('sha256', implode("\0", [
            'imagine-sites-lab-project.v1',
            $command->tenantRef,
            $command->orderRef,
            $command->contractRef,
        ])), 0, 32);
    }

    /** @return array<string,mixed> Non-publishable metadata; no Portal acceptance. */
    public function admit(SiteProjectCommand $command, string $siteRef): array
    {
        if (PHP_SAPI !== 'cli' ||
            getenv('APP_ENV') !== 'testing' ||
            getenv('SITES_F94_PROJECT_ADMISSION_LAB') !== '1' ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $siteRef)) {
            throw new DomainException('lab_project_admission_disabled_or_invalid_site');
        }

        if (!hash_equals(self::siteRefFor($command), $siteRef)) {
            throw new DomainException('lab_project_admission_site_not_bound_to_order');
        }

        // No database read, transaction, idempotency consumption or write
        // occurs until this separately supplied gateway authorizes the exact
        // opaque Portal command. In this lab, the test gateway is fictional.
        try {
            $decision = (new SiteProjectAdmission($this->authority))
                ->evaluate($command);
            if (!$decision->allowed) {
                throw new DomainException('lab_project_admission_portal_authority_denied');
            }
        } catch (Throwable) {
            throw new DomainException('lab_project_admission_portal_authority_denied');
        }

        $dbName = (string)$this->db->query('SELECT current_database()')->fetchColumn();
        $role = $this->db->query(
            "SELECT current_user,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole
             FROM pg_roles WHERE rolname=current_user"
        )->fetch(PDO::FETCH_ASSOC);
        $isFalse = static fn (mixed $v): bool => in_array($v, [false, 'f', '0'], true);
        if ($dbName !== 'sites_f94_lab' || !is_array($role) ||
            ($role['current_user'] ?? null) !== 'sites_f94_app' ||
            !$isFalse($role['rolsuper'] ?? null) ||
            !$isFalse($role['rolbypassrls'] ?? null) ||
            !$isFalse($role['rolcreatedb'] ?? null) ||
            !$isFalse($role['rolcreaterole'] ?? null)) {
            throw new DomainException('lab_project_admission_requires_restricted_role');
        }

        $fingerprint = $command->semanticFingerprint();
        if ($this->db->inTransaction()) {
            throw new DomainException('lab_project_admission_needs_own_transaction');
        }

        try {
            $this->db->beginTransaction();

            // set_config(...,true) is LOCAL to this transaction. This does NOT
            // prove tenant identity: only the trusted gateway can authorize
            // the command, and arbitrary SQL must never be exposed.
            $context = $this->db->prepare(
                "SELECT set_config('imagine_sites.tenant_ref', :tenant, true)"
            );
            $context->execute(['tenant' => $command->tenantRef]);
            if (!hash_equals(
                $command->tenantRef,
                (string)$context->fetchColumn()
            )) {
                throw new DomainException('lab_project_admission_tenant_context_failed');
            }

            $lookup = $this->db->prepare(
                'SELECT site_ref, operation_id, order_ref, contract_ref,
                        contract_revision, product_code, semantic_fingerprint
                 FROM sites_project_admissions
                 WHERE tenant_ref = :tenant AND idempotency_key = :idempotency'
            );
            $lookup->execute([
                'tenant' => $command->tenantRef,
                'idempotency' => $command->idempotencyKey,
            ]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);

            if ($existing !== false) {
                if ($existing['site_ref'] !== $siteRef ||
                    $existing['operation_id'] !== $command->operationId ||
                    $existing['order_ref'] !== $command->orderRef ||
                    $existing['contract_ref'] !== $command->contractRef ||
                    (int)$existing['contract_revision'] !== $command->contractRevision ||
                    $existing['product_code'] !== $command->productCode ||
                    !hash_equals(
                        (string)$existing['semantic_fingerprint'],
                        $fingerprint
                    )) {
                    throw new DomainException('lab_project_admission_idempotency_conflict');
                }
                // F1-104: current authority is required even after a
                // read-only idempotent replay lookup and comparison.
                $this->assertFinalAuthority($command);
                $this->db->commit();
                return self::result($command, $siteRef, $fingerprint, true);
            }

            $insertProject = $this->db->prepare(
                'INSERT INTO sites_projects
                    (tenant_ref,site_ref,product_code)
                 VALUES (:tenant,:site,:product)'
            );
            $insertProject->execute([
                'tenant' => $command->tenantRef,
                'site' => $siteRef,
                'product' => $command->productCode,
            ]);

            $insertAdmission = $this->db->prepare(
                'INSERT INTO sites_project_admissions
                    (tenant_ref,site_ref,operation_id,idempotency_key,
                     order_ref,contract_ref,contract_revision,product_code,
                     semantic_fingerprint)
                 VALUES
                    (:tenant,:site,:operation,:idempotency,
                     :order_ref,:contract,:revision,:product,:fingerprint)'
            );
            $insertAdmission->execute([
                'tenant' => $command->tenantRef,
                'site' => $siteRef,
                'operation' => $command->operationId,
                'idempotency' => $command->idempotencyKey,
                'order_ref' => $command->orderRef,
                'contract' => $command->contractRef,
                'revision' => $command->contractRevision,
                'product' => $command->productCode,
                'fingerprint' => $fingerprint,
            ]);

            // F1-104: a grant revoked during the transaction must roll back
            // both the project row and the admission row.
            $this->assertFinalAuthority($command);
            $this->db->commit();
            return self::result($command, $siteRef, $fingerprint, false);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Never interpret a concurrent unique-key violation as success.
            // The caller can retry the SAME authorized operation and then
            // compare its full persisted fingerprint through the read path.
            if ($e instanceof DomainException) {
                throw $e;
            }
            throw new DomainException(
                $e instanceof PDOException
                    ? 'lab_project_admission_constraint_or_concurrency_denied'
                    : 'lab_project_admission_write_denied',
                previous: $e
            );
        }
    }

    /** Synthetic second gateway check; not a substitute for live Portal IAM. */
    private function assertFinalAuthority(SiteProjectCommand $command): void
    {
        try {
            if (!(new SiteProjectAdmission($this->authority))
                ->evaluate($command)->allowed) {
                throw new DomainException('lab_project_admission_final_authority_denied');
            }
        } catch (Throwable $e) {
            throw new DomainException(
                'lab_project_admission_final_authority_denied',
                previous: $e
            );
        }
    }

    /** @return array<string,mixed> */
    private static function result(
        SiteProjectCommand $command,
        string $siteRef,
        string $fingerprint,
        bool $replayed
    ): array {
        return [
            'schemaVersion' => 'sites.synthetic.project-admission-result.v1',
            'tenantRef' => $command->tenantRef,
            'siteRef' => $siteRef,
            'operationId' => $command->operationId,
            'productCode' => $command->productCode,
            'semanticFingerprint' => $fingerprint,
            'idempotentReplay' => $replayed,
            'labOnly' => true,
            'portalIdentityOperational' => false,
            'officialClientAcceptance' => false,
            'publishable' => false,
        ];
    }
}
