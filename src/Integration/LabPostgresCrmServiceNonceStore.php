<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use PDO;
use Throwable;

/**
 * F1-167: restricted PDO adapter for a disposable PG18 nonce ledger.
 * Cannot sign a live request, deliver lead, or confirm any CRM operation.
 */
final class LabPostgresCrmServiceNonceStore
{
    public function __construct(private readonly PDO $db) {}

    public function reserve(
        string $organizationReference,
        string $bindingReference,
        string $nonce,
        string $operation,
        int $ttlSeconds
    ):LabCrmServiceNonceAssessment {
        if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
           getenv('GITHUB_ACTIONS')!=='true' ||
           getenv('SITES_F167_PG_LAB')!=='1' ||
           getenv('DB_HOST')!=='127.0.0.1' ||
           getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
            return new LabCrmServiceNonceAssessment('LAB_DISABLED');
        }
        if(!$this->db->inTransaction()) {
            return new LabCrmServiceNonceAssessment('TRANSACTION_REQUIRED');
        }

        $uuid='/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD';
        $nonceUuid='/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
        if(!preg_match($uuid,$organizationReference) ||
           !preg_match($uuid,$bindingReference) ||
           !preg_match($nonceUuid,$nonce) ||
           !in_array($operation,['deliver','lookup'],true) ||
           $ttlSeconds<1 || $ttlSeconds>300) {
            return new LabCrmServiceNonceAssessment('INVALID_SYNTHETIC_REQUEST');
        }

        try {
            $identity=$this->db->query(
                'SELECT current_user AS role,session_user AS login,current_database() AS db'
            )->fetch(PDO::FETCH_ASSOC);
            if(!is_array($identity) ||
               $identity['role']!==$identity['login'] ||
               !in_array($identity['login'],['sites_f167_a','sites_f167_b'],true) ||
               $identity['db']!=='sites_f140_atomic_lab') {
                return new LabCrmServiceNonceAssessment('UNTRUSTED_LAB_CONTEXT');
            }
            $query=$this->db->prepare(
                'SELECT f167_lab.reserve_synthetic_crm_service_nonce('.
                'CAST(? AS uuid),CAST(? AS uuid),CAST(? AS uuid),'.
                'CAST(? AS text),CAST(? AS integer))'
            );
            $query->execute([
                strtolower($organizationReference),
                strtolower($bindingReference),
                $nonce,$operation,$ttlSeconds,
            ]);
            $result=$query->fetchColumn();
            if(!is_string($result)) {
                return new LabCrmServiceNonceAssessment('INCONCLUSIVE_DATABASE');
            }
            return new LabCrmServiceNonceAssessment(
                LabCrmServiceNonceAssessment::safeCode($result)
            );
        }catch(Throwable) {
            return new LabCrmServiceNonceAssessment('INCONCLUSIVE_DATABASE');
        }
    }
}
