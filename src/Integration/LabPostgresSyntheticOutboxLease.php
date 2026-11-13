<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use PDO;
use Throwable;

/**
 * F1-147 CLI/CI fixture adapter. A lease labels synthetic metadata; it is
 * NEVER authority to send a lead, retry, touch customer data or call CRM.
 */
final class LabPostgresSyntheticOutboxLease
{
    public function __construct(private readonly PDO $db) {}

    public function tryLease(
        string $organizationReference,
        string $leaseReference
    ): LabSyntheticOutboxLeaseAssessment {
        if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
            || getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('SITES_F140_PG_LAB') !== '1'
            || getenv('DB_HOST') !== '127.0.0.1'
            || getenv('DB_DATABASE') !== 'sites_f140_atomic_lab') {
            return new LabSyntheticOutboxLeaseAssessment('LAB_DISABLED');
        }
        if (!$this->db->inTransaction()) {
            return new LabSyntheticOutboxLeaseAssessment('TRANSACTION_REQUIRED');
        }
        $uuid = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD';
        if (!preg_match($uuid, $organizationReference)
            || !preg_match($uuid, $leaseReference)) {
            return new LabSyntheticOutboxLeaseAssessment('INVALID_SYNTHETIC_LEASE');
        }

        try {
            $identity = $this->db->query(
                'SELECT current_user AS role,session_user AS login,current_database() AS db'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity)
                || $identity['role'] !== $identity['login']
                || !in_array($identity['login'], [
                    'sites_f140_app','sites_f142_org_b'
                ], true)
                || $identity['db'] !== 'sites_f140_atomic_lab') {
                return new LabSyntheticOutboxLeaseAssessment('UNTRUSTED_LAB_CONTEXT');
            }
            $q=$this->db->prepare(
                'SELECT f140_lab.try_lease_synthetic_outbox(?,?)'
            );
            $q->execute([
                strtolower($organizationReference),
                strtolower($leaseReference),
            ]);
            $result=$q->fetchColumn();
            if (!is_string($result)) {
                return new LabSyntheticOutboxLeaseAssessment('INCONCLUSIVE_DATABASE');
            }
            return new LabSyntheticOutboxLeaseAssessment(
                LabSyntheticOutboxLeaseAssessment::safeCode($result)
            );
        } catch (Throwable) {
            return new LabSyntheticOutboxLeaseAssessment('INCONCLUSIVE_DATABASE');
        }
    }
}
