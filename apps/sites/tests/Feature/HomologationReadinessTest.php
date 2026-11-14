<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\HomologationReadiness;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class HomologationReadinessTest extends TestCase
{
    /** @return array<string,mixed> */
    private function fictionalStagingConfig(): array
    {
        return [
            'environment'=>'staging',
            'debug'=>false,
            // Deliberately NOT a live key; this is pure unit data.
            'app_key'=>'base64:'.base64_encode('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef'),
            'app_url'=>'https://sites.internal.example.test',
            'synthetic_flags_disabled'=>true,
            'db_driver'=>'pgsql',
            'db_host'=>'fictional-pg.internal.example.test',
            'db_name'=>'imagine_sites_staging',
        ];
    }

    public function test_command_is_no_go_even_when_default_container_has_no_database(): void
    {
        $exit=Artisan::call('sites:homologation-preflight');
        $text=trim(Artisan::output());
        $report=json_decode($text,true,32,JSON_THROW_ON_ERROR);

        $this->assertSame(1,$exit);
        $this->assertSame('sites.homologation.preflight.v1',$report['schemaVersion']);
        $this->assertSame('blocked',$report['status']);
        $this->assertFalse($report['can_accept_real_orders']);
        $this->assertFalse($report['can_publish']);
        $this->assertFalse($report['database_probe_attempted']);
        $this->assertContains('staging_environment',$report['blockers']);
        $this->assertContains('portal_service_identity',$report['blockers']);
        $this->assertContains('commercial_contract',$report['blockers']);
        $this->assertContains('independent_revocation',$report['blockers']);
        $this->assertContains('backup_restore',$report['blockers']);
        $this->assertNotContains('APP_KEY',array_keys($report));
        $this->assertStringNotContainsString((string) config('app.key'),$text);
        $this->assertStringNotContainsString('DB_PASSWORD',$text);
    }

    public function test_pure_diagnostics_can_verify_db_invariants_but_never_authorize_sales(): void
    {
        $called=0;
        $report=HomologationReadiness::inspect(
            $this->fictionalStagingConfig(),
            static function () use (&$called):array {
                ++$called;
                return [
                    'pg_version'=>180000,
                    'db_name'=>'imagine_sites_staging',
                    'is_superuser'=>false,
                    'can_create_db'=>false,
                    'can_create_role'=>false,
                    'tls'=>true,
                ];
            }
        );
        $this->assertSame(1,$called);
        $this->assertTrue($report['database_probe_attempted']);
        $this->assertSame('verified',$report['checks']['postgres18_identity']['result']);
        $this->assertSame('verified',$report['checks']['postgres_least_privilege']['result']);
        $this->assertSame('verified',$report['checks']['postgres_tls']['result']);
        $this->assertSame('blocked',$report['status']);
        $this->assertFalse($report['can_accept_real_orders']);
        $this->assertContains('portal_service_identity',$report['blockers']);
        $this->assertContains('appsec_lgpd',$report['blockers']);
        $this->assertStringNotContainsString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef',
            json_encode($report,JSON_THROW_ON_ERROR));
    }

    public function test_missing_database_is_not_contacted_even_if_probe_would_throw(): void
    {
        $runtime=$this->fictionalStagingConfig();
        $runtime['db_host']='sites-database-not-provisioned';
        $runtime['db_name']='imagine_sites_unprovisioned';
        $called=0;
        $report=HomologationReadiness::inspect(
            $runtime,
            static function () use (&$called):array {
                ++$called;
                throw new \RuntimeException('CI-only secret must never enter report');
            }
        );
        $this->assertSame(0,$called);
        $this->assertFalse($report['database_probe_attempted']);
        $this->assertSame('blocked',$report['checks']['database_configured']['result']);
        $this->assertSame('blocked',$report['checks']['postgres18_identity']['result']);
    }

    public function test_probe_exception_is_sanitized_and_fails_closed(): void
    {
        $report=HomologationReadiness::inspect(
            $this->fictionalStagingConfig(),
            static function ():array {
                throw new \RuntimeException('fictional-password-should-never-appear');
            }
        );
        $this->assertTrue($report['database_probe_attempted']);
        $this->assertSame('blocked',$report['checks']['postgres18_identity']['result']);
        $this->assertStringNotContainsString(
            'fictional-password-should-never-appear',
            json_encode($report,JSON_THROW_ON_ERROR)
        );
    }

    public function test_owner_role_and_non_tls_connection_are_rejected(): void
    {
        $report=HomologationReadiness::inspect(
            $this->fictionalStagingConfig(),
            static fn ():array => [
                'pg_version'=>180000,
                'db_name'=>'imagine_sites_staging',
                'is_superuser'=>true,
                'can_create_db'=>true,
                'can_create_role'=>true,
                'tls'=>false,
            ]
        );
        $this->assertSame('verified',$report['checks']['postgres18_identity']['result']);
        $this->assertSame('blocked',$report['checks']['postgres_least_privilege']['result']);
        $this->assertSame('blocked',$report['checks']['postgres_tls']['result']);
    }

    public function test_fixture_key_and_local_http_can_never_pass_staging_check(): void
    {
        $runtime=$this->fictionalStagingConfig();
        $runtime['app_key']='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        $runtime['app_url']='http://127.0.0.1:18081';
        $runtime['synthetic_flags_disabled']=false;
        $report=HomologationReadiness::inspect($runtime);
        $this->assertSame('blocked',$report['checks']['app_key_shape']['result']);
        $this->assertSame('blocked',$report['checks']['public_https_url']['result']);
        $this->assertSame('blocked',$report['checks']['synthetic_paths_disabled']['result']);
        $this->assertFalse($report['database_probe_attempted']);
        $this->assertFalse($report['can_publish']);
    }
}
