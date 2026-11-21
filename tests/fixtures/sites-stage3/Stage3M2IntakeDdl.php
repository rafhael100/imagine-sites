<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PDO;
use RuntimeException;
use Throwable;

final class Stage3M2IntakeDdl
{
    private const PORTAL='portal_native_orders_ci';
    private const SITES='portal_sites_pair_ci';
    private const LEDGER='apps/portal/database/security/portal_onepage_site_receipts.sql';
    private const LEDGER_GIT='2102fb9399ec8f43b5cca1768ee33b61339de3c0';
    private const SITES_PINS=[
        'apps/sites/database/migrations/2026_11_14_000001_create_sites_core_foundation.php'=>'93044f3c607c7394765eed80cfce0a3be568a8e2c50a4d8c770ef1db97b0ed5e',
        'apps/sites/database/migrations/2026_11_14_000002_add_tenant_bound_read_api.php'=>'08ed812fbba8fda7ea44fc5cd2a29d9f204f031585a9eae1646de656df17d9af',
        'apps/sites/database/migrations/2026_11_14_000003_add_editorial_revision_api.php'=>'1ded1d792575e3e591f7511d41ecbaf129267a4b672f7b1af72ec6596dcb728b',
        'apps/sites/database/migrations/2026_11_16_000004_add_private_preview_workspace.php'=>'d6309a9a72353c02590392ab65e00616b3fca7acafb44315f0a7957aafddc091',
        'apps/sites/database/migrations/2026_11_17_000005_add_release_registry.php'=>'cec39d11b46b27f3daf4d034361a202ae975b51deb0bad71548173fc1f8ad2b0',
        'apps/sites/database/migrations/2026_11_17_000006_allow_editorial_reopen.php'=>'a5b313d73b62cd2a5ef0c4735c4471a0a00a1c20f09e42f3fd04d54cf11ac642',
        'apps/sites/database/migrations/2026_11_18_000007_add_publication_journal.php'=>'8679fda416e7a075d95e2f3b72208fc95a230cf2e81b825624f32e8ff58b7e97',
        'apps/sites/database/migrations/2026_11_18_000008_add_private_preview_review_notes.php'=>'847cf90f9f3c69270162d8745907ea4e702012623d497c45a04e602734312996',
        'apps/sites/database/migrations/2026_11_18_000009_add_editorial_head_read_api.php'=>'14e3fb5ea71153891602920c1b5dad96989073167facb4cf27e4f3c46c9952d9',
        'apps/sites/database/migrations/2026_11_18_000010_add_release_binding_head_read_api.php'=>'7408b277cff67189346be250dd5e8bd73c0794126c958aa28e14ad603a37f590',
        'apps/sites/database/migrations/2026_11_18_000011_add_portal_project_intake.php'=>'db0a9eda8f9478d5390b52ad97b284ee94773e87415d0b1ba47971901e579c00',
        'contracts/editorial/002-reopen-reviewed-release-down.sql'=>'13c3d411014e732f25915524303b80fabcd611497ffeb247c5a7b67ad87553e4',
        'contracts/editorial/002-reopen-reviewed-release.sql'=>'6f734f9ab7fa21dc89996046cb0ac9c4222a4dfc0328f402d7996380eb589479',
        'contracts/editorial/003-read-editorial-head.sql'=>'44e4075df3136353fb0437906d8e8c4e731be4b7bbfdda96bdca0b6b887f4060',
        'contracts/portal-sites/001-project-intake.sql'=>'1f0f25b7f810a6e6893de67480e4bad81000d29e5af3d7c0facfdda42f242ab6',
        'contracts/private-preview/001-workspace.sql'=>'5ecfa4e626c622b387047d51e3a672b733d5830283b4e34d847f7469df5279eb',
        'contracts/private-preview/002-review-notes.sql'=>'ad7122ba7abf3183d52660d1495a7fe0e8a9a161eb2d0e24467645023192ad54',
        'contracts/publication-journal/001-journal.sql'=>'30797fb9b572f5feea6f30189d45b9d177e5d3bad5e4c2ce230eb73ed223a8e0',
        'contracts/release-registry/001-registry.sql'=>'8b50c348a21eb58fcd0628abd11a467f450c02557506bc521ad116951b20e259',
        'contracts/release-registry/003-read-binding-head.sql'=>'c5a62ac65d4dcad98a6ef68f7c87b80f424cd0427cd262d52cdf374789bf02a1',
    ];

    public static function install(Stage3SupervisedSlots $slots,PostgresConnection $portalAdmin,
        PostgresConnection $sitesAdmin,string $portal954Root,string $sites512Root):void
    {
        $phase='INPUTS';
        try {
            if (PHP_SAPI!=='cli' || $portalAdmin===$sitesAdmin) self::fail();
            $ledgerPath=self::file($portal954Root,self::LEDGER);
            $ledger=file_get_contents($ledgerPath);
            if (!is_string($ledger) || hash('sha1','blob '.strlen($ledger)."\0".$ledger)!==self::LEDGER_GIT) self::fail();
            foreach(self::SITES_PINS as $rel=>$sha) self::pin($sites512Root,$rel,$sha);
            $phase='QUALIFIED_BASELINE';
            $p=$slots->beforeDdl(self::PORTAL,$portalAdmin);
            $s=$slots->beforeDdl(self::SITES,$sitesAdmin);
            if ($p===$s) self::fail();
            self::truth($s,<<<'SQL'
SELECT to_regnamespace('sites_core') IS NULL AND to_regnamespace('sites_preview') IS NULL
 AND to_regnamespace('sites_publication') IS NULL
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_roles WHERE rolname LIKE 'sites_core_%'
    OR rolname LIKE 'sites_preview_%' OR rolname LIKE 'sites_publication_%'
    OR rolname='portal_sites_pair_receiver')
SQL);
            self::truth($p,<<<'SQL'
SELECT to_regclass('portal_onepage.pending_orders') IS NOT NULL
 AND to_regclass('public.portal_organizations') IS NOT NULL
 AND to_regrole('portal_onepage_owner') IS NOT NULL AND to_regrole('portal_onepage_runtime') IS NOT NULL
 AND to_regclass('portal_onepage.site_intake_receipts') IS NULL
 AND to_regrole('portal_onepage_intake_recorder') IS NULL
SQL);
            $phase='SITES_MIGRATIONS';
            self::sites($slots,$sitesAdmin,$s,$sites512Root);
            self::truth($slots->beforeDdl(self::SITES,$sitesAdmin),<<<'SQL'
SELECT to_regprocedure('sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)') IS NOT NULL
 AND (SELECT relrowsecurity AND relforcerowsecurity FROM pg_catalog.pg_class
      WHERE oid='sites_core.portal_project_intakes'::regclass)
 AND NOT EXISTS(SELECT 1 FROM sites_core.portal_project_intakes)
SQL);
            $phase='PORTAL_LEDGER';
            if ($slots->beforeDdl(self::PORTAL,$portalAdmin)!==$p || $p->exec($ledger)===false || $p->inTransaction()) self::fail();
            self::truth($slots->beforeDdl(self::PORTAL,$portalAdmin),<<<'SQL'
SELECT to_regprocedure('portal_onepage.record_pending_site_intake(jsonb)') IS NOT NULL
 AND to_regprocedure('portal_onepage.read_my_site_intake(text,uuid)') IS NOT NULL
 AND (SELECT NOT rolcanlogin AND NOT rolinherit FROM pg_catalog.pg_roles WHERE rolname='portal_onepage_intake_recorder')
 AND (SELECT relrowsecurity AND relforcerowsecurity FROM pg_catalog.pg_class
      WHERE oid='portal_onepage.site_intake_receipts'::regclass)
 AND NOT EXISTS(SELECT 1 FROM portal_onepage.site_intake_receipts)
SQL);
        } catch(Throwable) {
            // Never retry on the same cluster: migrations and ledger commit separately.
            throw new RuntimeException('STAGE3_M2_INTAKE_DDL_REFUSED:'.$phase);
        }
    }

    private static function sites(Stage3SupervisedSlots $slots,PostgresConnection $c,PDO $p,string $root):void
    {
        $primary=Container::getInstance();
        $previous=Facade::getFacadeApplication();
        if (!($primary instanceof Application) || $primary!==$previous) self::fail();
        try {
            // No Sites Laravel bootstrap/providers/connection factory. DB facade
            // may dispatch only against the exact object already witnessed.
            $isolated=new Application($root.'/apps/sites');
            $isolated->instance('db',new Stage3M2FixedSitesDb($c));
            Facade::clearResolvedInstance('db');
            Facade::setFacadeApplication($isolated);
            foreach(self::SITES_PINS as $rel=>$sha) {
                if (!str_starts_with($rel,'apps/sites/database/migrations/')) continue;
                // The pinned migration may use its own transaction; no outer wrapper.
                foreach(self::SITES_PINS as $dep=>$digest) self::pin($root,$dep,$digest);
                if ($slots->beforeDdl(self::SITES,$c)!==$p) self::fail();
                $m=require self::file($root,$rel);
                if (!$m instanceof Migration || $slots->beforeDdl(self::SITES,$c)!==$p) self::fail();
                $m->up();
                if ($slots->beforeDdl(self::SITES,$c)!==$p) self::fail();
            }
        } finally {
            Container::setInstance($primary);
            Facade::setFacadeApplication($previous);
            Facade::clearResolvedInstance('db');
        }
    }

    private static function pin(string $root,string $relative,string $expected):void
    {
        if (hash_file('sha256',self::file($root,$relative))!==$expected) self::fail();
    }

    private static function file(string $root,string $relative):string
    {
        $p=$root.'/'.$relative;
        if (!str_starts_with($root,'/') || $root==='/' || realpath($root)!==$root
            || realpath($p)!==$p || !is_file($p) || strlen($relative)>160) self::fail();
        return $p;
    }

    private static function truth(PDO $p,string $sql):void
    {
        $q=$p->query($sql);
        if ($q===false || $q->fetchColumn()!==true) self::fail();
    }

    private static function fail():never {throw new RuntimeException('INTAKE_INPUT_REFUSED');}
}

/** Caller cannot select a named connection, open a second PDO or change a config. */
final readonly class Stage3M2FixedSitesDb
{
    public function __construct(private PostgresConnection $connection) {}
    public function connection():PostgresConnection {return $this->connection;}
    public function __call(string $name,array $args):mixed
    {
        if (!in_array($name,['getDriverName','unprepared','transaction','statement','selectOne'],true)) {
            throw new RuntimeException('INTAKE_SITES_FACADE_REFUSED');
        }
        return $this->connection->{$name}(...$args);
    }
}
