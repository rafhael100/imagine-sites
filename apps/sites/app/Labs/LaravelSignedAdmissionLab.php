<?php

declare(strict_types=1);

namespace App\Labs;

use DomainException;
use Illuminate\Database\Connection;
use Imagine\Sites\Integration\DenyingPortalProjectAuthorityGateway;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;
use RuntimeException;

/**
 * F1-375: Laravel 13 -> real PostgreSQL 18 atomic nonce/admission/outbox.
 *
 * Important: this is a CI-only bridge into the disposable F1-360 schema.
 * Signature verification is NOT an official contract/payment authorization.
 * The Portal authority gateway defaults to DENY and cannot be bypassed by
 * injecting a client-supplied tenant hint: SQL binds the tenant to session_user.
 * Never instantiate this service from an HTTP route or a production worker.
 */
final readonly class LaravelSignedAdmissionLab
{
    public function __construct(
        private Connection $connection,
        private PortalProjectAuthorityGateway $authority =
            new DenyingPortalProjectAuthorityGateway(),
    ) {}

    /**
     * @param array<string,mixed> $signedEnvelope
     * @return array{status:string,operationRef:string,publishable:bool}
     */
    public function admit(
        array $signedEnvelope,
        string $siteRef,
        string $fictionalSigningKey,
        int $now,
    ): array {
        $this->assertDisposableSession();
        if ($this->connection->transactionLevel() !== 0 ||
            $now <= 0 || $fictionalSigningKey !==
                'F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI') {
            throw new DomainException('F1-375: invalid lab transaction or key.');
        }

        return $this->connection->transaction(function () use (
            $signedEnvelope,$siteRef,$fictionalSigningKey,$now,
        ): array {
            // Nonce consumption is PostgreSQL-backed and enclosed in the
            // SAME transaction as the project + outbox inserts.
            $replay = new class($this->connection,$signedEnvelope,$now)
                implements ReplayStore {
                /** @param array<string,mixed> $wire */
                public function __construct(
                    private Connection $db,
                    private array $wire,
                    private int $clock,
                ) {}

                public function rememberOnce(
                    string $issuer,string $nonce,int $expiresAt,
                ): bool {
                    $tenant=$this->wire['payload']['tenantRef'] ?? null;
                    if (!is_string($tenant)) return false;
                    $duration=$expiresAt-$this->clock;
                    if ($duration < 1 || $duration > 120) return false;
                    $record=$this->db->selectOne(
                        'SELECT sites_f360.remember_nonce(?,?,?,?) AS accepted',
                        [$tenant,$issuer,$nonce,$duration]
                    );
                    return is_object($record) &&
                        in_array($record->accepted ?? null,[true,'t',1,'1'],true);
                }
            };

            $command=SyntheticSignedCommand::verify(
                $signedEnvelope,$fictionalSigningKey,$now,$replay
            );
            $decision=(new SiteProjectAdmission($this->authority))->evaluate($command);
            if (!$decision->allowed) {
                // The replay nonce insert, if any, MUST be rolled back.
                throw new DomainException('F1-375: official authority unavailable.');
            }

            $row=$this->connection->selectOne(
                'SELECT sites_f360.admit_project(?,?,?,?,?,?,?) AS result',
                [
                    $command->tenantRef,$command->idempotencyKey,$siteRef,
                    $command->contractRef,$command->productCode,
                    $command->contractRevision,$command->semanticFingerprint(),
                ]
            );
            $status=$row->result ?? null;
            if (!in_array($status,['queued','duplicate_ignored'],true)) {
                throw new RuntimeException('F1-375: unrecognized database response.');
            }
            return [
                'status'=>$status,
                'operationRef'=>$command->idempotencyKey,
                'publishable'=>false,
            ];
        },1);
    }

    private function assertDisposableSession():void
    {
        if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F375_LARAVEL_PG_LAB')!=='1' ||
            getenv('DB_CONNECTION')!=='pgsql' ||
            getenv('DB_HOST')!=='127.0.0.1' ||
            getenv('DB_PORT')!=='5432' ||
            getenv('DB_USERNAME')!=='sites_f360_b') {
            throw new DomainException('F1-375: no disposable CI lab authority.');
        }
        // Check an authenticated DB login, not only config or SET ROLE.
        $row=$this->connection->selectOne(
            "SELECT current_database() AS db_name,
                    session_user AS login_name,
                    current_setting('server_version_num')::integer AS pg_version"
        );
        if (!is_object($row) ||
            $row->db_name!=='sites_f360_lab' ||
            $row->login_name!=='sites_f360_b' ||
            (int)$row->pg_version<180000 ||
            (int)$row->pg_version>=190000) {
            throw new DomainException('F1-375: unexpected database/session identity.');
        }
    }
}
