<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ReadModels\TenantSiteStatusReader;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F1-384: tests the genuine Laravel query service against an ephemeral PG18
 * connection authenticated as a restricted SQL login. No user HTTP routes.
 */
final class TenantSiteStatusReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F384_READER_LAB')!=='1' ||
            getenv('DB_DATABASE')!=='sites_f383_lab') {
            $this->markTestSkipped('F1-384 disposable-only test disabled.');
        }
    }

    private function readerFor(string $role,string $password): TenantSiteStatusReader
    {
        $settings=config('database.connections.pgsql');
        $settings['database']='sites_f383_lab';
        $settings['host']='127.0.0.1';
        $settings['port']='5432';
        $settings['username']=$role;
        $settings['password']=$password;
        config(['database.connections.f384_reader_'.$role=>$settings]);
        DB::purge('f384_reader_'.$role);
        return new TenantSiteStatusReader(
            DB::connection('f384_reader_'.$role)
        );
    }

    public function test_authenticated_database_login_a_reads_only_its_project(): void
    {
        $results=$this->readerFor(
            'sites_f384_a','f384-fictional-a-only'
        )->list();
        $this->assertCount(1,$results);
        $this->assertSame([
            'siteId'=>'00000000-0000-4000-8000-000000000011',
            'productCode'=>'onepage',
            'state'=>'awaiting_official_authority',
            'reviewRevision'=>1,
            'reviewState'=>'draft',
            'publishable'=>false,
        ],$results[0]);
    }

    public function test_authenticated_database_login_b_reads_its_own_project_only(): void
    {
        $results=$this->readerFor(
            'sites_f384_b','f384-fictional-b-only'
        )->list();
        $this->assertCount(1,$results);
        $this->assertSame('00000000-0000-4000-8000-000000000022',$results[0]['siteId']);
        $this->assertSame('landing_page',$results[0]['productCode']);
        $this->assertSame(2,$results[0]['reviewRevision']);
        $this->assertSame('internal_review',$results[0]['reviewState']);
        $this->assertFalse($results[0]['publishable']);
        $this->assertArrayNotHasKey('tenantId',$results[0]);
        $this->assertArrayNotHasKey('contractRef',$results[0]);
    }

    public function test_database_administrator_cannot_impersonate_customer(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Sites status unavailable.');
        $this->readerFor(
            'postgres','f383-fictional-admin-only'
        )->list();
    }

    protected function tearDown():void
    {
        foreach ([
            'sites_f384_a','sites_f384_b','postgres',
        ] as $role) {
            DB::disconnect('f384_reader_'.$role);
        }
        parent::tearDown();
    }
}
