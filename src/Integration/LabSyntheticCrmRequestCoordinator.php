<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use PDO;

/**
 * F1-168: in-process CI composition of binding revision fence F1-166,
 * technical wire F1-165 and disposable PG18 nonce uniqueness F1-167.
 *
 * All proofs are synthetic. This is not an authenticated application
 * service, not a real CRM ingress, not a queue, and cannot commit a receipt.
 */
final class LabSyntheticCrmRequestCoordinator
{
    public function __construct(
        private readonly PDO $db,
        private readonly ?SyntheticLeadBindingAuthoritySource $source = null,
    ) {}

    public function prepareWithinTransaction(
        SyntheticCrmLeadEnvelopeV1Draft $envelope,
        string $operation,
        int $issuedAt,
        int $expiresAt,
        int $now,
        string $nonce,
    ):LabSyntheticCrmRequestAttemptAssessment {
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F165_SYNTHETIC_AUTH')!=='1' ||
            getenv('SITES_F166_AUTHORITY_FENCE')!=='1' ||
            getenv('SITES_F167_PG_LAB')!=='1' ||
            getenv('SITES_F168_COMPOSITION_LAB')!=='1' ||
            getenv('DB_HOST')!=='127.0.0.1' ||
            getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
            return new LabSyntheticCrmRequestAttemptAssessment('LAB_DISABLED');
        }
        if (!$this->db->inTransaction()) {
            return new LabSyntheticCrmRequestAttemptAssessment('TRANSACTION_REQUIRED');
        }

        $preflight=(new SyntheticCrmTechnicalHandoffPreflight($this->source))
            ->inspect($envelope,$operation,$issuedAt,$expiresAt,$now,$nonce);

        if ($preflight->reasonCode!=='LAB_WIRE_PREPARED_UNTRUSTED' ||
            $preflight->candidate===null) {
            return new LabSyntheticCrmRequestAttemptAssessment(
                'BINDING_OR_WIRE_UNVERIFIED'
            );
        }

        // Atomic uniqueness within caller's still-open transaction.
        // No commit, durable receipt, CRM API call or permission follows.
        $nonceReport=(new LabPostgresCrmServiceNonceStore($this->db))->reserve(
            $envelope->organizationReference,
            $envelope->bindingReference,
            $nonce,
            $operation,
            $expiresAt-$issuedAt,
        );

        if ($nonceReport->reasonCode==='NONCE_ALREADY_CONSUMED') {
            return new LabSyntheticCrmRequestAttemptAssessment('NONCE_REPLAY_DENIED');
        }
        if ($nonceReport->reasonCode==='DB_LOGIN_SCOPE_DENIED') {
            return new LabSyntheticCrmRequestAttemptAssessment('TENANT_LOGIN_SCOPE_DENIED');
        }
        if ($nonceReport->reasonCode!=='NONCE_RESERVED_UNVERIFIED') {
            return new LabSyntheticCrmRequestAttemptAssessment(
                'NONCE_RESERVATION_NOT_VERIFIED'
            );
        }
        // Only a candidate for review; it is still in an UNCOMMITTED tx.
        return new LabSyntheticCrmRequestAttemptAssessment(
            'LAB_PENDING_TRANSACTION_UNTRUSTED',$preflight->candidate
        );
    }
}
