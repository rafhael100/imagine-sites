<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Labs\ProjectOutboxLabService;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests use only a GitHub Actions PostgreSQL 18 service with fictional rows.
 * A CI owner connection executes SET ROLE once to model the restricted runtime.
 * NEVER reuse this lab credential/role pattern in production.
 */
final class LabProjectOutboxPgTest extends TestCase
{
    private Connection $db;
    private ProjectOutboxLabService $lab;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_SAPI !== 'cli' || getenv('SITES_F1_19_PG_LAB') !== '1' ||
            !app()->environment('testing')) {
            $this->markTestSkipped('F1-19 PostgreSQL lab disabled by default.');
        }

        $settings = config('database.connections.pgsql');
        $settings['host'] = '127.0.0.1';
        $settings['port'] = 5432;
        $settings['database'] = 'sites_lab';
        $settings['username'] = 'postgres'; // ONLY disposable schema owner in CI.
        $settings['password'] = 'sites-outbox-lab-fictional-only';
        config(['database.connections.pgsql' => $settings]);
        DB::purge('pgsql');

        $this->db = DB::connection('pgsql');
        $this->db->statement('SET ROLE sites_lab_tenant_a');
        $this->lab = new ProjectOutboxLabService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            try {
                $this->db->statement('RESET ROLE');
            } finally {
                DB::disconnect('pgsql');
            }
        }
        parent::tearDown();
    }

    private function commandCount(string $operation): int
    {
        return (int)$this->db->table('lab_sites.project_commands')
            ->where('operation_id', $operation)->count();
    }

    private function eventCount(string $operation): int
    {
        return (int)$this->db->table('lab_sites.project_outbox')
            ->where('operation_id', $operation)->count();
    }

    public function test_laravel_transaction_persists_command_and_outbox_atomically(): void
    {
        $this->lab->admit('tenant-fake-a','site-fake-a','op-f19-ok','idem-f19-ok',
            str_repeat('a',64),1,'evt-f19-ok');
        $this->assertSame(1,$this->commandCount('op-f19-ok'));
        $this->assertSame(1,$this->eventCount('op-f19-ok'));
    }

    public function test_invalid_event_rolls_back_successfully_prepared_command(): void
    {
        try {
            $this->lab->admit('tenant-fake-a','site-fake-a','op-f19-rollback','idem-f19-rollback',
                str_repeat('a',64),1,'evt-f19-invalid','sites.delivery.forged.v1');
            $this->fail('Invalid publication/fulfilment event should be denied.');
        } catch (QueryException) {
            $this->assertSame(0,$this->commandCount('op-f19-rollback'));
            $this->assertSame(0,$this->eventCount('op-f19-rollback'));
        }
    }

    public function test_cross_tenant_command_is_rejected_by_postgresql_rls(): void
    {
        try {
            $this->lab->admit('tenant-fake-b','site-fake-b','op-f19-cross','idem-f19-cross',
                str_repeat('b',64),1,'evt-f19-cross');
            $this->fail('Foreign tenant should be denied.');
        } catch (QueryException) {
            $this->assertSame(0,$this->commandCount('op-f19-cross'));
        }
    }

    public function test_duplicate_idempotency_key_is_rejected(): void
    {
        $this->lab->admit('tenant-fake-a','site-fake-a','op-f19-dedup','idem-f19-unique',
            str_repeat('a',64),1,'evt-f19-dedup');
        try {
            $this->lab->admit('tenant-fake-a','site-fake-a','op-f19-duplicate','idem-f19-unique',
                str_repeat('b',64),1,'evt-f19-duplicate');
            $this->fail('Conflicting idempotency key should be rejected.');
        } catch (QueryException) {
            $this->assertSame(0,$this->commandCount('op-f19-duplicate'));
            $this->assertSame(0,$this->eventCount('op-f19-duplicate'));
        }
    }

    public function test_privileged_owner_connection_is_refused_by_application_lab_guard(): void
    {
        $this->db->statement('RESET ROLE');
        try {
            $this->expectException(DomainException::class);
            $this->lab->admit('tenant-fake-a','site-fake-a','op-f19-owner','idem-f19-owner',
                str_repeat('a',64),1,'evt-f19-owner');
        } finally {
            $this->db->statement('SET ROLE sites_lab_tenant_a');
        }
    }

    public function test_revocation_blocks_new_command_and_hides_existing_records(): void
    {
        $this->lab->admit('tenant-fake-a','site-fake-a','op-f19-revoke-seed','idem-f19-revoke',
            str_repeat('a',64),1,'evt-f19-revoke');
        $this->db->statement('RESET ROLE');
        $this->db->table('lab_sites.authority_grants')
            ->where('tenant_id','tenant-fake-a')
            ->where('role_name','sites_lab_tenant_a')
            ->update(['status'=>'revoked']);
        $this->db->statement('SET ROLE sites_lab_tenant_a');

        try {
            $this->assertSame(0,$this->commandCount('op-f19-revoke-seed'));
            try {
                $this->lab->admit('tenant-fake-a','site-fake-a','op-f19-after-revoke','idem-f19-after-revoke',
                    str_repeat('a',64),1,'evt-f19-after-revoke');
                $this->fail('Revoked tenant must not write.');
            } catch (QueryException) {
                $this->assertSame(0,$this->eventCount('op-f19-after-revoke'));
            }
        } finally {
            $this->db->statement('RESET ROLE');
            $this->db->table('lab_sites.authority_grants')
                ->where('tenant_id','tenant-fake-a')
                ->where('role_name','sites_lab_tenant_a')
                ->update(['status'=>'active']);
            $this->db->statement('SET ROLE sites_lab_tenant_a');
        }
    }
}
