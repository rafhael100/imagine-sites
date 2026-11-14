<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Labs\LaravelSignedAdmissionLab;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\SyntheticSignedCommand;
use PDO;
use Tests\TestCase;

/** Explicit fictional-only authority used in ONE test process; not bound in Laravel. */
final class F375FictionalAuthority implements PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        return new PolicyDecision(
            $command->contractRevision===1 &&
            in_array($command->tenantRef,['tenant-f360-b','tenant-f360-a'],true) &&
            $command->contractRef==='contract-f375',
            ['fixture_only_not_a_real_contract'],
        );
    }
}

final class LaravelSignedAdmissionPgTest extends TestCase
{
    private const KEY='F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI';
    private Connection $labDb;
    private PDO $adminDb;

    protected function setUp():void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F375_LARAVEL_PG_LAB')!=='1' ||
            !app()->environment('testing')) {
            $this->markTestSkipped('F1-375 disposable PG18 CI fixture disabled.');
        }
        $settings=config('database.connections.pgsql');
        $settings['host']='127.0.0.1';
        $settings['port']='5432';
        $settings['database']='sites_f360_lab';
        $settings['username']='sites_f360_b';
        $settings['password']='f360-fictional-tenant-b-only';
        $settings['sslmode']='prefer'; // disposable CI only; no real staging policy.
        config(['database.default'=>'pgsql','database.connections.pgsql'=>$settings]);
        DB::purge('pgsql');
        $this->labDb=DB::connection('pgsql');
        $this->adminDb=new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab',
            'postgres','f360-fictional-admin-disposable-only',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function tearDown():void
    {
        if (isset($this->labDb)) DB::disconnect('pgsql');
        parent::tearDown();
    }

    private function lab():LaravelSignedAdmissionLab
    {
        return new LaravelSignedAdmissionLab($this->labDb,new F375FictionalAuthority());
    }

    private function command(
        string $id,string $tenant='tenant-f360-b',
        string $product='onepage',int $revision=1,
    ):SiteProjectCommand {
        return new SiteProjectCommand(
            '1','operation-'.$id,$tenant,'order-f375','contract-f375',
            $revision,$product,'idem-'.$id
        );
    }

    /** @return array<string,mixed> */
    private function signed(SiteProjectCommand $cmd,string $nonce):array
    {
        return SyntheticSignedCommand::sign(
            $cmd,self::KEY,$nonce,time(),time()+60
        );
    }

    private function countTable(string $table,string $operation):int
    {
        if (!in_array($table,['admissions','outbox'],true)) {
            throw new \LogicException('Unexpected fixture table.');
        }
        $q=$this->adminDb->prepare(
            'SELECT count(*) FROM sites_f360.'.$table.' WHERE operation_ref=?'
        );
        $q->execute([$operation]);
        return (int)$q->fetchColumn();
    }

    private function nonceCount(string $nonce):int
    {
        $q=$this->adminDb->prepare(
            'SELECT count(*) FROM sites_f360.replay_nonces WHERE nonce=?'
        );
        $q->execute([$nonce]);
        return (int)$q->fetchColumn();
    }

    public function test_laravel_signed_onepage_and_landing_are_atomic_with_outbox():void
    {
        foreach(['onepage','landing_page'] as $product) {
            $id='f375-'.$product;
            $command=$this->command($id,'tenant-f360-b',$product);
            $receipt=$this->lab()->admit(
                $this->signed($command,'nonce-'.$id.'-valid'),
                'site-'.$id,self::KEY,time()
            );
            $this->assertSame('queued',$receipt['status']);
            $this->assertSame($command->idempotencyKey,$receipt['operationRef']);
            $this->assertFalse($receipt['publishable']);
            $this->assertSame(1,$this->countTable('admissions','idem-'.$id));
            $this->assertSame(1,$this->countTable('outbox','idem-'.$id));
            $this->assertSame(1,$this->nonceCount('nonce-'.$id.'-valid'));
        }
    }

    public function test_replay_fails_but_new_nonce_deduplicates_same_contract():void
    {
        $cmd=$this->command('f375-repeat');
        $wire=$this->signed($cmd,'nonce-f375-repeat-first');
        $lab=$this->lab();
        $this->assertSame('queued',$lab->admit($wire,'site-f375-repeat',self::KEY,time())['status']);
        try {
            $lab->admit($wire,'site-f375-repeat',self::KEY,time());
            $this->fail('Signed replay must be denied.');
        } catch(DomainException $e) {
            $this->assertSame(1,$this->countTable('outbox','idem-f375-repeat'));
        }
        $fresh=$lab->admit(
            $this->signed($cmd,'nonce-f375-repeat-second'),
            'site-f375-repeat',self::KEY,time()
        );
        $this->assertSame('duplicate_ignored',$fresh['status']);
        $this->assertSame(1,$this->countTable('admissions','idem-f375-repeat'));
        $this->assertSame(1,$this->countTable('outbox','idem-f375-repeat'));
        $this->assertSame(1,$this->nonceCount('nonce-f375-repeat-second'));
    }

    public function test_different_signed_payload_for_same_idempotency_key_rolls_back_nonce():void
    {
        $lab=$this->lab();
        $base=$this->command('f375-conflict');
        $lab->admit(
            $this->signed($base,'nonce-f375-conflict-first'),
            'site-f375-conflict',self::KEY,time()
        );
        $changed=$this->command('f375-conflict','tenant-f360-b','landing_page');
        try {
            $lab->admit(
                $this->signed($changed,'nonce-f375-conflict-second'),
                'site-f375-conflict',self::KEY,time()
            );
            $this->fail('Conflicting durable idempotency must be denied.');
        } catch(QueryException $e) {
            $this->assertSame('23505',$e->getCode());
            $this->assertSame(0,$this->nonceCount('nonce-f375-conflict-second'));
            $this->assertSame(1,$this->countTable('outbox','idem-f375-conflict'));
        }
    }

    public function test_outbox_error_rolls_back_nonce_and_admission():void
    {
        $command=$this->command('f375-failure');
        // A narrow SQL fixture-only constraint simulates a failing outbox.
        $wire=$this->signed(new SiteProjectCommand(
            '1','operation-f375-failure','tenant-f360-b','order-f375',
            'contract-f375',1,'onepage','failoutbox-f360'
        ),'nonce-f375-outbox-fail');
        for($i=0;$i<2;$i++) {
            try {
                $this->lab()->admit($wire,'site-f375-failure',self::KEY,time());
                $this->fail('Outbox failure should rollback the whole transaction.');
            } catch(QueryException $e) {
                $this->assertSame('23514',$e->getCode());
            }
        }
        $this->assertSame(0,$this->nonceCount('nonce-f375-outbox-fail'));
        $this->assertSame(0,$this->countTable('admissions','failoutbox-f360'));
        $this->assertSame(0,$this->countTable('outbox','failoutbox-f360'));
    }

    public function test_valid_hmac_other_tenant_is_denied_by_authenticated_db_login():void
    {
        $cmd=$this->command('f375-foreign','tenant-f360-a');
        try {
            $this->lab()->admit(
                $this->signed($cmd,'nonce-f375-foreign-tenant'),
                'site-f375-foreign',self::KEY,time()
            );
            $this->fail('Wrong tenant must fail despite valid signature.');
        } catch(QueryException $e) {
            $this->assertSame('42501',$e->getCode());
            $this->assertSame(0,$this->nonceCount('nonce-f375-foreign-tenant'));
            $this->assertSame(0,$this->countTable('outbox','idem-f375-foreign'));
        }
    }

    public function test_tampered_hmac_is_denied_without_nonce_side_effects():void
    {
        $cmd=$this->command('f375-tampered');
        $wire=$this->signed($cmd,'nonce-f375-tampered');
        $wire['payload']['productCode']='landing_page';
        try {
            $this->lab()->admit($wire,'site-f375-tampered',self::KEY,time());
            $this->fail('Tampered signed payload must not be accepted.');
        } catch(DomainException $e) {
            $this->assertSame(0,$this->nonceCount('nonce-f375-tampered'));
            $this->assertSame(0,$this->countTable('outbox','idem-f375-tampered'));
        }
    }

    public function test_default_gateway_denies_and_reverts_nonce():void
    {
        $cmd=$this->command('f375-no-gateway');
        try {
            (new LaravelSignedAdmissionLab($this->labDb))->admit(
                $this->signed($cmd,'nonce-f375-unauthorized'),
                'site-f375-unauthorized',self::KEY,time()
            );
            $this->fail('No default Portal authority permitted.');
        } catch(DomainException $e) {
            $this->assertSame(0,$this->nonceCount('nonce-f375-unauthorized'));
            $this->assertSame(0,$this->countTable('admissions','idem-f375-no-gateway'));
        }
    }

    public function test_revoked_db_tenant_binding_denies_and_reverts_nonce():void
    {
        $this->adminDb->exec(
            "UPDATE sites_f360.login_bindings SET active=false WHERE login_name='sites_f360_b'"
        );
        try {
            $cmd=$this->command('f375-revoked');
            try {
                $this->lab()->admit(
                    $this->signed($cmd,'nonce-f375-revoked'),
                    'site-f375-revoked',self::KEY,time()
                );
                $this->fail('Revoked SQL login must fail.');
            } catch(QueryException $e) {
                $this->assertSame('42501',$e->getCode());
                $this->assertSame(0,$this->nonceCount('nonce-f375-revoked'));
            }
        } finally {
            $this->adminDb->exec(
                "UPDATE sites_f360.login_bindings SET active=true WHERE login_name='sites_f360_b'"
            );
        }
    }

    public function test_service_denies_execution_without_ci_lab_opt_in():void
    {
        $old=getenv('SITES_F375_LARAVEL_PG_LAB');
        putenv('SITES_F375_LARAVEL_PG_LAB=0');
        try {
            $this->expectException(DomainException::class);
            $this->lab()->admit(
                $this->signed($this->command('f375-no-flag'),'nonce-f375-no-flag'),
                'site-f375-no-flag',self::KEY,time()
            );
        } finally {
            putenv('SITES_F375_LARAVEL_PG_LAB='.($old===false?'1':$old));
        }
    }

    public function test_admin_db_session_cannot_impersonate_authenticated_tenant():void
    {
        $settings=config('database.connections.pgsql');
        $settings['username']='postgres';
        $settings['password']='f360-fictional-admin-disposable-only';
        config(['database.connections.f375_admin'=>$settings]);
        try {
            $owner=DB::connection('f375_admin');
            $this->expectException(DomainException::class);
            (new LaravelSignedAdmissionLab($owner,new F375FictionalAuthority()))
                ->admit(
                    $this->signed($this->command('f375-owner'),'nonce-f375-owner'),
                    'site-f375-owner',self::KEY,time()
                );
        } finally {
            DB::disconnect('f375_admin');
        }
    }
}
