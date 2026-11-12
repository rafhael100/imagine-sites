<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use PDO;
use Throwable;

/**
 * F1-144: CLI CI-only bridge for F1-143 role-scoped PostgreSQL claims.
 * Caller-provided UUIDs are NOT customer identity. Database role login is
 * a lab fixture, not a real Portal IAM or OAuth service authorization.
 */
final class LabPostgresTenantBoundCrmClaim
{
    public function __construct(private readonly PDO $db) {}

    public function register(
        SyntheticCrmLeadEnvelopeV1Draft $draft,
        int $expectedRevision
    ): LabTenantBoundCrmClaimAssessment {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('SITES_F140_PG_LAB') !== '1'
            || getenv('DB_HOST') !== '127.0.0.1'
            || getenv('DB_DATABASE') !== 'sites_f140_atomic_lab') {
            return new LabTenantBoundCrmClaimAssessment('LAB_DISABLED');
        }
        if (!$this->db->inTransaction()) {
            return new LabTenantBoundCrmClaimAssessment('TRANSACTION_REQUIRED');
        }

        try {
            $identity = $this->db->query(
                'SELECT current_user AS role, session_user AS login, current_database() AS db'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity)
                || $identity['role'] !== $identity['login']
                || !in_array($identity['login'], [
                    'sites_f140_app', 'sites_f142_org_b',
                ], true)
                || $identity['db'] !== 'sites_f140_atomic_lab') {
                return new LabTenantBoundCrmClaimAssessment('UNTRUSTED_LAB_CONTEXT');
            }

            $q = $this->db->prepare(
                'SELECT f140_lab.try_login_scoped_claim(?,?,?,?,?,?,?)'
            );
            $q->execute([
                $draft->organizationReference,
                $draft->submissionReference,
                $draft->bindingReference,
                $draft->siteReference,
                $draft->formReference,
                $draft->destinationReference,
                $expectedRevision,
            ]);
            $result = $q->fetchColumn();
            if (!is_string($result)) {
                return new LabTenantBoundCrmClaimAssessment('INCONCLUSIVE_DATABASE');
            }

            return new LabTenantBoundCrmClaimAssessment(
                LabTenantBoundCrmClaimAssessment::safeCode($result)
            );
        } catch (Throwable) {
            return new LabTenantBoundCrmClaimAssessment('INCONCLUSIVE_DATABASE');
        }
    }
}
