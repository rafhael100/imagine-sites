<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use DomainException;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use PDO;
use PDOException;

/**
 * F1-50: PostgreSQL-backed CURRENT review authority in a DISPOSABLE lab.
 * Does not prove any real Portal right, service authentication or contract.
 * Requires a restricted SQL role with RLS, never root/superuser/HTTP.
 */
final readonly class LabPgCurrentReviewAuthorityGateway implements ReviewHandoffAuthorityGateway
{
    public function __construct(private PDO $db)
    {
        if(PHP_SAPI!=='cli' || getenv('SITES_F50_AUTH_LAB')!=='1') {
            throw new DomainException('F1-50 is disabled outside explicit CLI lab.');
        }
        try {
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
                throw new DomainException('Only disposable PostgreSQL is permitted.');
            }
            $row=$this->db->query(
                'SELECT current_database() AS database_name, current_user AS role_name'
            )->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row) || $row['database_name']!=='sites_lab' ||
                !in_array($row['role_name'],['sites_lab_tenant_a','sites_lab_tenant_b'],true)) {
                throw new DomainException('Unknown/disallowed SQL identity or database.');
            }
        } catch(PDOException $e) {
            throw new DomainException('F1-50 database context unavailable.',previous:$e);
        }
    }

    /** @param array<string,string|int|bool> $candidate */
    public function isCurrentlyAuthorized(
        SyntheticProductionWorkflow $workflow,
        array $candidate,
    ):bool {
        // This checks the locally reconstructed workflow against the signed
        // candidate and the CURRENT independently persisted database revision.
        // It does NOT accept a tenant from JSON as authority in its own right.
        if($workflow->artifactDigest===null ||
            $workflow->state!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            !isset($candidate['tenantRef'],$candidate['siteRef'],$candidate['workflowRef'],
                $candidate['workflowRevision'],$candidate['releaseRef'],$candidate['artifactDigest']) ||
            !is_string($candidate['tenantRef']) || !is_string($candidate['siteRef']) ||
            !is_string($candidate['workflowRef']) || !is_int($candidate['workflowRevision']) ||
            !is_string($candidate['releaseRef']) || !is_string($candidate['artifactDigest']) ||
            $workflow->tenantRef!==$candidate['tenantRef'] ||
            $workflow->siteRef!==$candidate['siteRef'] ||
            $workflow->workflowRef!==$candidate['workflowRef'] ||
            $workflow->revision!==$candidate['workflowRevision'] ||
            !hash_equals($workflow->artifactDigest,$candidate['artifactDigest'])) {
            return false;
        }
        try {
            $query=$this->db->prepare(
                'SELECT workflow_revision,release_ref,artifact_digest,
                        authority_revision,active
                 FROM lab_sites.current_review_authorities
                 WHERE tenant_id=:tenant AND site_id=:site AND workflow_ref=:workflow'
            );
            $query->execute([
                ':tenant'=>$candidate['tenantRef'],
                ':site'=>$candidate['siteRef'],
                ':workflow'=>$candidate['workflowRef'],
            ]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row) || $query->fetch(PDO::FETCH_ASSOC)!==false) {
                return false;
            }
            return $row['active']===true || $row['active']==='t'
                ? (int)$row['workflow_revision']===$workflow->revision &&
                  (int)$row['authority_revision']>0 &&
                  hash_equals((string)$row['release_ref'],$candidate['releaseRef']) &&
                  hash_equals((string)$row['artifact_digest'],$candidate['artifactDigest'])
                : false;
        } catch(PDOException) {
            return false;
        }
    }
}
