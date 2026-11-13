<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use DomainException;
use PDO;
use PDOException;
use Throwable;

/**
 * F1-49: read-only, RLS-scoped PostgreSQL 18 candidate authority source.
 * This does not establish service identity or production Portal authority.
 * It is allowed ONLY inside the flagged, disposable two-tenant CI lab.
 */
final class LabPostgresCurrentPortalReviewSource implements CurrentPortalReviewSnapshotSource
{
    public function __construct(private readonly PDO $db)
    {
        if (PHP_SAPI!=='cli' || getenv('SITES_F49_PG_LAB')!=='1') {
            throw new DomainException('Untrusted Portal source outside flagged CLI lab.');
        }
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
                throw new DomainException('Only PostgreSQL lab is permitted.');
            }
            $context=$this->db->query(
                'SELECT current_database() AS db, current_user AS role_name'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($context) ||
                $context['db']!=='sites_auth_replay_lab' ||
                !in_array($context['role_name'],[
                    'sites_review_tenant_a','sites_review_tenant_b'
                ],true)) {
                throw new DomainException('Invalid disposable authority reader role.');
            }
        } catch (PDOException $e) {
            throw new DomainException('Portal source DB unavailable.',previous:$e);
        }
    }

    public function findCurrentFor(
        string $tenantRef,
        string $siteRef,
    ): ?CurrentPortalReviewSnapshot {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$tenantRef) ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$siteRef)) {
            return null;
        }
        try {
            $query=$this->db->prepare(
                'SELECT tenant_ref,site_ref,workflow_ref,workflow_revision,
                        release_ref,artifact_digest,authorization_revision,status,
                        extract(epoch from checked_at)::bigint AS checked_at_unix,
                        extract(epoch from valid_until)::bigint AS valid_until_unix
                   FROM auth_replay.current_portal_review_authority
                  WHERE tenant_ref=:tenant AND site_ref=:site'
            );
            $query->bindValue(':tenant',$tenantRef,PDO::PARAM_STR);
            $query->bindValue(':site',$siteRef,PDO::PARAM_STR);
            if (!$query->execute())return null;
            $row=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row))return null;
            return new CurrentPortalReviewSnapshot(
                $row['tenant_ref'],$row['site_ref'],$row['workflow_ref'],
                (int)$row['workflow_revision'],$row['release_ref'],
                $row['artifact_digest'],(int)$row['authorization_revision'],
                $row['status'],(int)$row['checked_at_unix'],
                (int)$row['valid_until_unix']
            );
        } catch (Throwable) {
            // SQL errors, RLS violations, bad data and unavailable DB all DENY.
            return null;
        }
    }
}
