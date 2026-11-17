<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use PDO;
use Throwable;

/**
 * Fresh, execute-only read of the independent Sites registry.
 *
 * A named SQL reader must be provisioned independently of the Portal proof
 * issuer and of the artifact/binding writers. Failure returns null; neither
 * browser claims nor a Portal webhook are alternate binding sources.
 */
final readonly class PgSiteCommercialReleaseBindingSource implements SiteCurrentCommercialReleaseBindingSource
{
    public function __construct(private PDO $db) {}

    public function readCurrent(
        SiteProjectCommand $command,
        ReleaseManifest $manifest,
        int $editorialRevision,
    ): ?SiteCommercialReleaseBinding {
        if ($command->tenantRef !== $manifest->tenantRef || $editorialRevision < 1 ||
            !PgSiteReleaseRegistryGuard::siteId($manifest->siteRef)) {
            return null;
        }
        try {
            $before = PgSiteReleaseRegistryGuard::snapshot($this->db);
            $query = $this->db->prepare(
                'SELECT sites_core.read_current_release_binding(?::uuid,?::text,?::text,?::integer,?::text)'
            );
            $query->execute([$manifest->siteRef, $manifest->releaseRef, $manifest->digest,
                $editorialRevision, $command->semanticFingerprint()]);
            $raw = $query->fetchColumn();
            if ($raw === null || $raw === false ||
                PgSiteReleaseRegistryGuard::snapshot($this->db) !== $before) {
                return null;
            }
            $binding = PgSiteReleaseRegistryGuard::binding($raw);
            if ($binding->tenantRef !== $command->tenantRef || $binding->orderRef !== $command->orderRef ||
                $binding->contractRef !== $command->contractRef ||
                $binding->contractRevision !== $command->contractRevision ||
                $binding->productCode !== $command->productCode ||
                $binding->siteRef !== $manifest->siteRef || $binding->releaseRef !== $manifest->releaseRef ||
                !hash_equals($binding->artifactDigest, $manifest->digest) ||
                $binding->editorialRevision !== $editorialRevision) {
                return null;
            }
            return $binding;
        } catch (Throwable) {
            return null;
        }
    }
}
