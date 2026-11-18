<?php

declare(strict_types=1);

namespace App\Integration;

use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Imagine\Sites\Integration\SiteProjectCommand;
use Throwable;

/**
 * Records a pending Portal request in the native Sites schema.
 * SQL login/capability authorizes RECEIPT only, never payment or production.
 * tenantRef is the Portal organization reference, not the Sites tenant UUID.
 */
final readonly class PortalProjectIntakeRecorder
{
    public function __construct(private Connection $database) {}

    /**
     * Stable on exact replay. "recorded" does not mean this call created a row.
     * @return array{schemaVersion:string,tenantRef:string,orderRef:string,contractRef:string,
     * contractRevision:int,productCode:string,operationId:string,idempotencyKey:string,
     * siteRef:string,commandDigest:string,status:string,state:string,
     * authorityVerified:false,clientAccepted:false,canPublish:false}
     */
    public function record(SiteProjectCommand $command): array
    {
        // A nested transaction/savepoint cannot establish durable receipt.
        if ($this->database->getDriverName() !== 'pgsql' ||
            $this->database->transactionLevel() !== 0) {
            throw new DomainException('Sites project intake requires an independent transaction.');
        }
        try {
            $alreadyInTransaction = $this->database->getPdo()->inTransaction();
        } catch (Throwable) {
            throw new DomainException('Sites project intake unavailable.');
        }
        if ($alreadyInTransaction) {
            throw new DomainException('Sites project intake requires an independent transaction.');
        }

        try {
            // Exactly one attempt. A lost commit response is ambiguous; the
            // caller must retry the SAME command, not generate a new identity.
            return $this->database->transaction(function () use ($command): array {
                $this->database->statement("SET LOCAL lock_timeout='1500ms'");
                $this->database->statement("SET LOCAL statement_timeout='5000ms'");
                $row = $this->database->selectOne(
                    'SELECT * FROM sites_core.record_portal_project_request('.
                    '?::text,?::text,?::text,?::text,?::text,?::integer,?::text,?::text)',
                    [$command->schemaVersion,$command->operationId,$command->tenantRef,
                        $command->orderRef,$command->contractRef,$command->contractRevision,
                        $command->productCode,$command->idempotencyKey],
                );
                return self::receipt($row, $command);
            }, 1);
        } catch (QueryException $error) {
            // Do not expose SQL, binds, identities, server names or exceptions
            // through the Portal boundary. No previous exception is retained.
            if (($error->errorInfo[0] ?? null) === '23505') {
                throw new DomainException('Sites project intake conflict.');
            }
            throw new DomainException('Sites project intake unavailable.');
        } catch (Throwable) {
            throw new DomainException('Sites project intake unavailable.');
        }
    }

    private static function receipt(mixed $row, SiteProjectCommand $command): array
    {
        $expected = [
            'tenant_ref'=>$command->tenantRef,'order_ref'=>$command->orderRef,
            'contract_ref'=>$command->contractRef,'contract_revision'=>$command->contractRevision,
            'product_code'=>$command->productCode,'operation_id'=>$command->operationId,
            'idempotency_key'=>$command->idempotencyKey,
            'command_digest'=>$command->semanticFingerprint(),
        ];
        if (!is_object($row)) {
            throw new DomainException('Invalid Sites intake receipt.');
        }
        $actual = get_object_vars($row);
        $keys = array_keys($actual);
        $wanted = [...array_keys($expected),'site_ref'];
        sort($keys);
        sort($wanted);
        if ($keys !== $wanted) {
            throw new DomainException('Invalid Sites intake receipt.');
        }
        foreach ($expected as $key=>$value) {
            // PDO PostgreSQL returns this integer as an int. Numeric strings,
            // truthy values and coercion are not part of the wire contract.
            if ($actual[$key] !== $value) {
                throw new DomainException('Invalid Sites intake receipt.');
            }
        }
        if (!is_string($actual['site_ref']) || !preg_match(
            '/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $actual['site_ref'],
        )) {
            throw new DomainException('Invalid Sites intake receipt.');
        }
        return [
            'schemaVersion'=>'sites.project-intake.receipt.v1',
            'tenantRef'=>$actual['tenant_ref'],'orderRef'=>$actual['order_ref'],
            'contractRef'=>$actual['contract_ref'],'contractRevision'=>$actual['contract_revision'],
            'productCode'=>$actual['product_code'],'operationId'=>$actual['operation_id'],
            'idempotencyKey'=>$actual['idempotency_key'],'siteRef'=>$actual['site_ref'],
            'commandDigest'=>$actual['command_digest'],'status'=>'recorded',
            'state'=>'awaiting_official_authority',
            'authorityVerified'=>false,'clientAccepted'=>false,'canPublish'=>false,
        ];
    }
}
