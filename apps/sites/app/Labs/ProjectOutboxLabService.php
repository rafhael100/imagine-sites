<?php

declare(strict_types=1);

namespace App\Labs;

use DomainException;
use Illuminate\Database\Connection;

/**
 * F1-19: Laravel service exercising a DISPOSABLE, restricted PostgreSQL role.
 * Never a production API. No official order, client authority or publisher.
 */
final readonly class ProjectOutboxLabService
{
    public function __construct(private Connection $database) {}

    public function admit(
        string $tenantRef,
        string $siteRef,
        string $operationRef,
        string $idempotencyKey,
        string $digest,
        int $contractRevision,
        string $eventRef,
        string $eventType = 'sites.project.admitted.v1',
    ): void {
        if (PHP_SAPI !== 'cli' ||
            !app()->environment('testing') ||
            getenv('SITES_F1_19_PG_LAB') !== '1') {
            throw new DomainException('F1-19 writes are forbidden outside the test lab.');
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $digest) ||
            $contractRevision < 1) {
            throw new DomainException('Invalid synthetic revision or digest.');
        }

        $context = $this->database->selectOne(
            'SELECT current_database() AS database_name, current_user AS role_name'
        );
        if (!is_object($context) ||
            $context->database_name !== 'sites_lab' ||
            $context->role_name !== 'sites_lab_tenant_a') {
            throw new DomainException('Only the disposable restricted DB role is allowed.');
        }

        $this->database->transaction(function () use (
            $tenantRef, $siteRef, $operationRef,
            $idempotencyKey, $digest, $contractRevision,
            $eventRef, $eventType,
        ): void {
            $this->database->insert(
                'INSERT INTO lab_sites.project_commands
                  (tenant_id,site_id,operation_id,idempotency_key,payload_digest,expected_contract_revision)
                 VALUES (?,?,?,?,?,?)',
                [$tenantRef,$siteRef,$operationRef,$idempotencyKey,$digest,$contractRevision]
            );
            $this->database->insert(
                'INSERT INTO lab_sites.project_outbox
                  (tenant_id,event_id,operation_id,event_type)
                 VALUES (?,?,?,?)',
                [$tenantRef,$eventRef,$operationRef,$eventType]
            );
        }, 1);
    }
}
