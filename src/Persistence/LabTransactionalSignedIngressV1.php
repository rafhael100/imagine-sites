<?php

declare(strict_types=1);

namespace Imagine\Sites\Persistence;

use DomainException;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedAuthorityAdmission;
use PDO;
use RuntimeException;
use Throwable;

/**
 * F1-364: reusable but strictly CI-FENCED prototype.
 *
 * This links the pinned, synthetic signed Portal authorization code from
 * PR #443 to the ephemeral PG18 F1-360/363 durable nonce/admission/outbox.
 * NEVER use for HTTP, real credentials, payments, client data or production.
 */
final class LabTransactionalSignedIngressV1
{
    public function __construct(private readonly PDO $database) {}

    /**
     * @param array<string,mixed> $signedCommand
     * @param array<string,mixed>|null $portalProof
     * @param array<string,mixed>|null $externalProof
     * @return array{status:string,tenant_ref:string,operation_ref:string,publishable:bool}
     */
    public function admit(
        array $signedCommand,
        ?array $portalProof,
        ?array $externalProof,
        string $commandKey,
        string $portalKey,
        string $externalKey,
        string $siteRef,
        int $now,
    ): array {
        self::assertDisposableLab();
        if ($now < 1 || $this->database->inTransaction()) {
            throw new DomainException('F1-364: no nested transaction or invalid clock.');
        }
        // Verify the *actual* DB identity as well as the environment. CI flags
        // alone are not a trustworthy identity or a production security gate.
        $who=$this->database->query(
            'SELECT current_database(),session_user'
        )->fetch(PDO::FETCH_NUM);
        if ($who !== ['sites_f360_lab','sites_f360_b']) {
            throw new DomainException('F1-364: refusing unexpected SQL login or DB.');
        }
        if (!isset($signedCommand['payload']) ||
            !is_array($signedCommand['payload']) ||
            !isset($signedCommand['payload']['tenantRef']) ||
            !is_string($signedCommand['payload']['tenantRef'])) {
            throw new DomainException('F1-364: signed payload has no tenant reference.');
        }

        $this->database->beginTransaction();
        try {
            // A tenant hint here is NOT authority: the signed verifier calls
            // rememberOnce only *after* checking HMAC and both proofs. The DB
            // independently matches the tenant hint to session_user.
            $replay=new LabPgReplayNonceStoreV1(
                $this->database,$signedCommand['payload']['tenantRef'],$now
            );
            $command=SyntheticSignedAuthorityAdmission::admit(
                $signedCommand,$portalProof,$externalProof,
                $commandKey,$portalKey,$externalKey,$now,$replay
            );
            $status=self::insertCommand($this->database,$command,$siteRef);
            $this->database->commit();
            return [
                'status'=>$status,
                'tenant_ref'=>$command->tenantRef,
                'operation_ref'=>$command->idempotencyKey,
                'publishable'=>false,
            ];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    private static function insertCommand(
        PDO $db,SiteProjectCommand $command,string $siteRef
    ): string {
        $sql=$db->prepare(
            'SELECT sites_f360.admit_project(?,?,?,?,?,?,?)'
        );
        $sql->execute([
            $command->tenantRef,
            $command->idempotencyKey,
            $siteRef,
            $command->contractRef,
            $command->productCode,
            $command->contractRevision,
            $command->semanticFingerprint(),
        ]);
        $status=$sql->fetchColumn();
        if (!in_array($status,['queued','duplicate_ignored'],true)) {
            throw new RuntimeException('F1-364: unexpected database response.');
        }
        return $status;
    }

    private static function assertDisposableLab():void
    {
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F360_PG_LAB')!=='1' ||
            getenv('SITES_F363_NONCE_LAB')!=='1' ||
            getenv('SITES_F364_BRIDGE_LAB')!=='1' ||
            getenv('DB_CONNECTION')!=='pgsql' ||
            getenv('DB_HOST')!=='127.0.0.1' ||
            getenv('DB_PORT')!=='5432' ||
            getenv('DB_DATABASE')!=='sites_f360_lab' ||
            getenv('DB_USERNAME')!=='postgres' ||
            getenv('DB_PASSWORD')!=='f360-fictional-admin-disposable-only') {
            throw new DomainException('F1-364: CI-only transactional adapter is disabled.');
        }
    }
}

/** @internal Synthetic-only PDO replay fence — never shared with production. */
final class LabPgReplayNonceStoreV1 implements ReplayStore
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $expectedTenant,
        private readonly int $now,
    ) {}

    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $q=$this->db->prepare(
            'SELECT sites_f360.remember_nonce(?,?,?,?)'
        );
        $q->execute([
            $this->expectedTenant,
            $issuer,
            $nonce,
            $expiresAt-$this->now,
        ]);
        return in_array($q->fetchColumn(),[true,'t','1',1],true);
    }
}
