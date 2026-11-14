<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use PDO;
use Throwable;

/**
 * F1-400: Sites-owned current release registry, independently scoped by a
 * distinct PostgreSQL18 session identity and Sites-specific HMAC.
 *
 * Default publisher remains DENY. This class is NOT registered with Laravel
 * or any operational provider and cannot publish/create domains or charges.
 * Tenant and signed MAC must come from S2S/IAM authority, NEVER client JSON.
 */
final readonly class SitesPg18CommercialReleaseBindingSource
    implements SiteCurrentCommercialReleaseBindingSource
{
    public function __construct(
        private PDO $db,
        private string $tenantRef,
        #[\SensitiveParameter] private string $signedTenantMac,
    ) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$tenantRef)!==1 ||
            preg_match('/\A[a-f0-9]{64}\z/D',$signedTenantMac)!==1) {
            throw new DomainException('Trusted Sites release principal unavailable.');
        }
        try {
            $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
            $q=$db->query(
                "SELECT r.rolsuper,r.rolbypassrls,
                        c.relrowsecurity,c.relforcerowsecurity
                   FROM pg_roles r
                   JOIN pg_class c
                     ON c.oid='sites_binding.current_release_history'::regclass
                  WHERE r.rolname=session_user AND current_user=session_user
                    AND session_user='sites_release_reader'"
            );
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) ||
                self::pgFlag($row['rolsuper']??null) ||
                self::pgFlag($row['rolbypassrls']??null) ||
                !self::pgFlag($row['relrowsecurity']??null) ||
                !self::pgFlag($row['relforcerowsecurity']??null)) {
                throw new DomainException('Restricted Sites role and FORCE RLS required.');
            }
        } catch (Throwable) {
            throw new DomainException('Restricted Sites role and FORCE RLS required.');
        }
    }

    public function readCurrent(
        SiteProjectCommand $command,
        ReleaseManifest $manifest,
        int $editorialRevision,
    ): ?SiteCommercialReleaseBinding {
        if ($command->tenantRef!==$manifest->tenantRef ||
            $this->tenantRef!==$command->tenantRef ||
            $editorialRevision<1 || $this->db->inTransaction()) {
            return null;
        }
        try {
            $this->db->beginTransaction();
            $scope=$this->db->prepare(
                "SELECT set_config('imagine.sites_tenant',?,true),
                        set_config('imagine.sites_mac',?,true)"
            );
            $scope->execute([$this->tenantRef,$this->signedTenantMac]);

            // The newest version for this order is authoritative even if
            // a previous release's receipt remains cryptographically valid.
            $q=$this->db->prepare(
                "SELECT tenant_ref,order_ref,contract_ref,contract_revision,
                        product_code,site_ref,release_ref,artifact_digest,
                        editorial_revision,binding_revision,binding_state
                   FROM sites_binding.current_release_history
                  WHERE tenant_ref=? AND order_ref=?
                  ORDER BY binding_revision DESC LIMIT 1"
            );
            $q->execute([$this->tenantRef,$command->orderRef]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            $this->db->commit();
            if (!is_array($row) || $row['binding_state']!=='active' ||
                $row['tenant_ref']!==$this->tenantRef ||
                $row['order_ref']!==$command->orderRef ||
                $row['contract_ref']!==$command->contractRef ||
                (int)$row['contract_revision']!==$command->contractRevision ||
                $row['product_code']!==$command->productCode ||
                $row['site_ref']!==$manifest->siteRef ||
                $row['release_ref']!==$manifest->releaseRef ||
                !hash_equals((string)$row['artifact_digest'],$manifest->digest) ||
                (int)$row['editorial_revision']!==$editorialRevision) {
                return null;
            }

            return new SiteCommercialReleaseBinding(
                (string)$row['tenant_ref'],(string)$row['order_ref'],
                (string)$row['contract_ref'],(int)$row['contract_revision'],
                (string)$row['product_code'],(string)$row['site_ref'],
                (string)$row['release_ref'],(string)$row['artifact_digest'],
                (int)$row['editorial_revision'],(int)$row['binding_revision']
            );
        } catch (Throwable) {
            if ($this->db->inTransaction())$this->db->rollBack();
            return null;
        }
    }

    private static function pgFlag(mixed $v):bool
    {
        return in_array($v,[true,1,'1','t'],true);
    }
}
