<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Controlled writes to the independent Sites release registry.
 *
 * Each method requires a different explicitly provisioned SQL duty. Commercial
 * references are always derived from Sites core; no caller-provided tenant,
 * order, contract, payment or acceptance flag is accepted. No provider binds
 * this class by default and no HTTP route invokes it.
 */
final readonly class PgSiteReleaseRegistry
{
    public function __construct(private PDO $db) {}

    /** @return array{releaseRef:string,digest:string,sizeBytes:int,created:bool} */
    public function recordArtifact(string $siteId, string $releaseRef, string $html): array
    {
        if (!PgSiteReleaseRegistryGuard::siteId($siteId) ||
            !PgSiteReleaseRegistryGuard::reference($releaseRef) ||
            $html === '' || strlen($html) > 150000 || str_contains($html, "\0") ||
            !mb_check_encoding($html, 'UTF-8')) {
            throw new InvalidArgumentException('Release artifact input denied');
        }
        $raw = $this->invoke('SELECT sites_core.record_release_artifact(?::uuid,?::text,?::text)',
            [$siteId, $releaseRef, $html]);
        try {
            if (!is_string($raw) || strlen($raw) > 1024) {
                throw new RuntimeException('Release artifact response unavailable');
            }
            $row = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($row) || count($row) !== 4 ||
                ($row['releaseRef'] ?? null) !== $releaseRef ||
                ($row['digest'] ?? null) !== hash('sha256', $html) ||
                ($row['sizeBytes'] ?? null) !== strlen($html) ||
                !is_bool($row['created'] ?? null)) {
                throw new RuntimeException('Release artifact response unavailable');
            }
            return ['releaseRef' => $row['releaseRef'], 'digest' => $row['digest'],
                'sizeBytes' => $row['sizeBytes'], 'created' => $row['created']];
        } catch (Throwable) {
            throw new RuntimeException('Release artifact response unavailable');
        }
    }

    public function bind(
        string $siteId,
        string $releaseRef,
        int $editorialRevision,
        int $expectedBindingRevision,
    ): SiteCommercialReleaseBinding {
        if (!PgSiteReleaseRegistryGuard::siteId($siteId) ||
            !PgSiteReleaseRegistryGuard::reference($releaseRef) ||
            $editorialRevision < 1 || $expectedBindingRevision < 0) {
            throw new InvalidArgumentException('Release binding input denied');
        }
        $raw = $this->invoke('SELECT sites_core.bind_release_artifact(?::uuid,?::text,?::integer,?::bigint)',
            [$siteId, $releaseRef, $editorialRevision, $expectedBindingRevision]);
        try {
            $binding = PgSiteReleaseRegistryGuard::binding($raw);
            if ($binding->siteRef !== $siteId || $binding->releaseRef !== $releaseRef ||
                $binding->editorialRevision !== $editorialRevision ||
                $binding->bindingRevision < max(1, $expectedBindingRevision) ||
                $binding->bindingRevision - $expectedBindingRevision > 1) {
                throw new RuntimeException('Release binding response unavailable');
            }
            return $binding;
        } catch (Throwable) {
            throw new RuntimeException('Release binding response unavailable');
        }
    }

    public function revoke(string $siteId, int $expectedBindingRevision): int
    {
        if (!PgSiteReleaseRegistryGuard::siteId($siteId) || $expectedBindingRevision < 1) {
            throw new InvalidArgumentException('Release revocation input denied');
        }
        $revision = $this->invoke('SELECT sites_core.revoke_release_binding(?::uuid,?::bigint)',
            [$siteId, $expectedBindingRevision]);
        if ((!is_int($revision) && !is_string($revision)) ||
            preg_match('/\A[1-9][0-9]*\z/D', (string) $revision) !== 1 ||
            (string) (int) $revision !== (string) $revision ||
            (int) $revision < $expectedBindingRevision ||
            (int) $revision - $expectedBindingRevision > 1) {
            throw new RuntimeException('Release revocation response unavailable');
        }
        return (int) $revision;
    }

    /** Each function invocation is one atomic PostgreSQL transaction. */
    private function invoke(string $sql, array $parameters): mixed
    {
        try {
            $before = PgSiteReleaseRegistryGuard::snapshot($this->db);
            $query = $this->db->prepare($sql);
            $query->execute($parameters);
            $result = $query->fetchColumn();
            if (PgSiteReleaseRegistryGuard::snapshot($this->db) !== $before) {
                throw new RuntimeException('Release registry connection changed');
            }
            return $result;
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? $error->getCode()) === '40001') {
                throw new SiteReleaseRegistryConflict('Release registry revision conflict');
            }
            throw new RuntimeException('Release registry operation denied');
        } catch (Throwable) {
            throw new RuntimeException('Release registry operation denied');
        }
    }
}
