<?php

declare(strict_types=1);

namespace App\Integration;

use DomainException;
use Illuminate\Database\Connection;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use Throwable;

/** Reads the native receipt for one exact command through a dedicated reader. */
final readonly class StoredProjectIntakeReceiptReader
{
    public function __construct(private Connection $database) {}

    /**
     * A historical receipt does not identify which call created it or authorize retry.
     * @return array{schemaVersion:string,tenantRef:string,orderRef:string,contractRef:string,
     * contractRevision:int,productCode:string,operationId:string,idempotencyKey:string,
     * siteRef:string,commandDigest:string,status:string,state:string,
     * authorityVerified:false,clientAccepted:false,canPublish:false}
     */
    public function readExact(SiteProjectCommand $command): array
    {
        try {
            BriefCandidateJson::integer($command->contractRevision, 1, 2147483647);
            return BriefSnapshotTransaction::run($this->database, true, function () use ($command): array {
                $rows = $this->database->select(
                    'SELECT * FROM sites_core.read_portal_project_intake_receipt('.
                    '?::text,?::text,?::text,?::text,?::text,?::integer,?::text,?::text)',
                    [$command->schemaVersion, $command->operationId, $command->tenantRef,
                        $command->orderRef, $command->contractRef, $command->contractRevision,
                        $command->productCode, $command->idempotencyKey],
                    false,
                );
                if (count($rows) !== 1 || !is_object($rows[0])) throw new RuntimeException();
                $actual = get_object_vars($rows[0]);
                $expected = [
                    'tenant_ref' => $command->tenantRef,
                    'order_ref' => $command->orderRef,
                    'contract_ref' => $command->contractRef,
                    'contract_revision' => $command->contractRevision,
                    'product_code' => $command->productCode,
                    'operation_id' => $command->operationId,
                    'idempotency_key' => $command->idempotencyKey,
                    'command_digest' => $command->semanticFingerprint(),
                ];
                $keys = [...array_keys($expected), 'site_ref'];
                if (count($actual) !== count($keys) || array_diff($keys, array_keys($actual))) {
                    throw new RuntimeException();
                }
                // The semantic digest excludes operation/key. Equality of
                // those two selectors must be established independently.
                foreach ($expected as $key => $value) {
                    if ($actual[$key] !== $value) throw new RuntimeException();
                }
                BriefCandidateJson::uuid($actual['site_ref']);
                return [
                    'schemaVersion' => 'sites.project-intake.receipt.v1',
                    'tenantRef' => $actual['tenant_ref'], 'orderRef' => $actual['order_ref'],
                    'contractRef' => $actual['contract_ref'], 'contractRevision' => $actual['contract_revision'],
                    'productCode' => $actual['product_code'], 'operationId' => $actual['operation_id'],
                    'idempotencyKey' => $actual['idempotency_key'], 'siteRef' => $actual['site_ref'],
                    'commandDigest' => $actual['command_digest'], 'status' => 'recorded',
                    'state' => 'awaiting_official_authority',
                    'authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false,
                ];
            });
        } catch (Throwable) {
            // Unknown, foreign, revoked, mismatched and uncertain outcomes
            // expose no SQL diagnostics, private selectors or chained error.
            throw new DomainException('Sites project intake receipt unavailable.');
        }
    }
}
