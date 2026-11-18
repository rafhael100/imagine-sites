<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integration\PortalProjectIntakeRecorder;
use DomainException;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Integration\SiteProjectCommand;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortalProjectIntakeRecorderTest extends TestCase
{
    private function command(): SiteProjectCommand
    {
        return new SiteProjectCommand('1','operation-distinct','portal-org-reference','order-reference',
            'contract-reference',3,'onepage','idempotency-distinct');
    }

    private function row(): object
    {
        $command=$this->command();
        return (object)[
            'tenant_ref'=>$command->tenantRef,'order_ref'=>$command->orderRef,
            'contract_ref'=>$command->contractRef,'contract_revision'=>$command->contractRevision,
            'product_code'=>$command->productCode,'operation_id'=>$command->operationId,
            'idempotency_key'=>$command->idempotencyKey,
            'site_ref'=>'11c7222c-6177-4222-a59a-e10222c8c511',
            'command_digest'=>$command->semanticFingerprint(),
        ];
    }

    private function recorder(mixed $row, bool &$committed, bool $commitFails=false): PortalProjectIntakeRecorder
    {
        $pdo=$this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $db=$this->getMockBuilder(PostgresConnection::class)
            ->setConstructorArgs([$pdo,'disconnected-test','',['driver'=>'pgsql']])
            ->onlyMethods(['selectOne','statement','transaction'])->getMock();
        $db->method('selectOne')->willReturn($row);
        $db->method('statement')->willReturn(true);
        $db->expects($this->once())->method('transaction')->willReturnCallback(
            function ($callback,$attempts) use (&$committed,$commitFails) {
                self::assertSame(1,$attempts,'There is no automatic retry after uncertain commit.');
                $receipt=$callback();
                if ($commitFails) throw new PDOException('Sensitive connection/commit diagnostic');
                $committed=true;
                return $receipt;
            },
        );
        return new PortalProjectIntakeRecorder($db);
    }

    public function test_pending_receipt_has_exact_types_and_preserves_distinct_references_after_commit(): void
    {
        $committed=false;
        $result=$this->recorder($this->row(),$committed)->record($this->command());
        self::assertTrue($committed);
        self::assertSame([
            'schemaVersion'=>'sites.project-intake.receipt.v1',
            'tenantRef'=>'portal-org-reference','orderRef'=>'order-reference',
            'contractRef'=>'contract-reference','contractRevision'=>3,
            'productCode'=>'onepage','operationId'=>'operation-distinct',
            'idempotencyKey'=>'idempotency-distinct','siteRef'=>'11c7222c-6177-4222-a59a-e10222c8c511',
            'commandDigest'=>$this->command()->semanticFingerprint(),
            'status'=>'recorded','state'=>'awaiting_official_authority',
            'authorityVerified'=>false,'clientAccepted'=>false,'canPublish'=>false,
        ],$result);
    }

    public static function malformedRows(): iterable
    {
        yield 'revision string'=>['contract_revision','3'];
        yield 'revision float'=>['contract_revision',3.0];
        yield 'revision bool'=>['contract_revision',true];
        yield 'tenant is not org'=>['tenant_ref','11c7222c-6177-4222-a59a-e10222c8c511'];
        yield 'wrong order'=>['order_ref','other'];
        yield 'wrong contract'=>['contract_ref','other'];
        yield 'wrong product'=>['product_code','landing_page'];
        yield 'collapsed operation'=>['operation_id','idempotency-distinct'];
        yield 'collapsed key'=>['idempotency_key','operation-distinct'];
        yield 'wrong digest'=>['command_digest',str_repeat('0',64)];
        yield 'site array'=>['site_ref',[]];
        yield 'site string'=>['site_ref','some-site'];
        yield 'extra authority'=>['canPublish',true];
        yield 'missing field'=>['order_ref',null];
    }

    #[DataProvider('malformedRows')]
    public function test_unexpected_database_result_is_rejected_before_commit(string $field,mixed $value): void
    {
        $row=$this->row();
        if ($value===null) unset($row->{$field});
        else $row->{$field}=$value;
        $committed=false;
        try {
            $this->recorder($row,$committed)->record($this->command());
            self::fail('Invalid response must not become a receipt.');
        } catch (DomainException $error) {
            self::assertSame('Sites project intake unavailable.',$error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertFalse($committed);
        }
    }

    public function test_commit_failure_returns_no_receipt_or_internal_diagnostics(): void
    {
        $committed=false;
        try {
            $this->recorder($this->row(),$committed,true)->record($this->command());
            self::fail('Commit failure cannot return a receipt.');
        } catch (DomainException $error) {
            self::assertSame('Sites project intake unavailable.',$error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertFalse($committed);
        }
    }

    public function test_external_pdo_transaction_is_rejected_without_queries(): void
    {
        $pdo=$this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $db=$this->getMockBuilder(PostgresConnection::class)
            ->setConstructorArgs([$pdo,'disconnected-test','',['driver'=>'pgsql']])
            ->onlyMethods(['selectOne','transaction'])->getMock();
        $db->expects($this->never())->method('selectOne');
        $db->expects($this->never())->method('transaction');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Sites project intake requires an independent transaction.');
        (new PortalProjectIntakeRecorder($db))->record($this->command());
    }

    public function test_initial_connection_failure_is_sanitized(): void
    {
        $db=new PostgresConnection(static fn()=>throw new PDOException('Sensitive connection details'),
            'disconnected-test','',['driver'=>'pgsql']);
        try {
            (new PortalProjectIntakeRecorder($db))->record($this->command());
            self::fail('Connection failure must deny receipt.');
        } catch (DomainException $error) {
            self::assertSame('Sites project intake unavailable.',$error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
