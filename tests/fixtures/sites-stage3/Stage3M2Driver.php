<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use Illuminate\Database\PostgresConnection;
use ReflectionClass;
use RuntimeException;
use Throwable;

/** Single #590 successor scenario callback, never an executable bootstrap. */
final class Stage3M2Driver
{
    private const REFS=[
        'portal'=>'4dc198d2109fe1c7590b5f012e77757ed593e1d5',
        'overlay'=>'d1aaab523848baf80325a023f3ebbf4998a3607f',
        'sites'=>'9b80387fe1c580436b444a6aad2b63f3baafa37d',
    ];
    private const FIXTURES=[
        'Stage3PortalOrderSeed.php'=>'6893fe479908b1c2d99003385b9112cf94b76e8db1bd8491faf2c663fe6c47a7',
        'Stage3PortalFinanceSeed.php'=>'f66ad556b036fa0f4c5f4cb611d14329b593b64ba663a5008ec69ac570250d2e',
        'Stage3InboxSeed.php'=>'8c67b258f7ce10563545020a9d16e9d2192947d447d3720be601505d441d0c1e',
        'Stage3NativePaidEntry.php'=>'323c8f7c075c1f7ecca21622270551d003bcee99eb3107ef6588d3a88494063c',
        'Stage3M2IntakeDdl.php'=>'a03d7c5c5f1760f1a46cf5ae40c8b10a404e94a4b75307f891ff87b3954ec72f',
        'Stage3M2IntakeSlots.php'=>'95aeb0fe8b98fede5ab0390b34c215572dfdae30103ac9d5f7d3a09a3bb16927',
        'Stage3NativeIntakeAssertion.php'=>'63434812bbd79f0dd11d7d88b07157d9a53e14b7e2ef4354e8b28eca7ae7ee8b',
    ];
    private const OVERLAY=[
        'apps/portal/app/Infrastructure/Commerce/OnepageOrderCommands.php'=>
            ['7bf3be1d3d95a276795654fc913704d99a2780c9','App\Infrastructure\Commerce\OnepageOrderCommands'],
        'apps/portal/app/Infrastructure/Commerce/PostgresOnepageOrders.php'=>
            ['183f84f99abb7a6e3c6244504529d57da0d6b248','App\Infrastructure\Commerce\PostgresOnepageOrders'],
        'apps/portal/app/Domain/SitesIntegration/PortalSitesPendingReceiptValidator.php'=>
            ['cdb26e8e5b8ebe6349f6f29fba183fd78bc54710','App\Domain\SitesIntegration\PortalSitesPendingReceiptValidator'],
        'apps/portal/app/Infrastructure/Commerce/PostgresOnepageSiteReceiptWriter.php'=>
            ['f84ee07e704a83d8c997f8ad558b977bac8f0798','App\Infrastructure\Commerce\PostgresOnepageSiteReceiptWriter'],
    ];
    private const SITES=[
        'src/Integration/SiteProjectCommand.php'=>
            ['914a25dbf435437ac0143f11ebc2f72a5e5b3d70','Imagine\Sites\Integration\SiteProjectCommand'],
        'apps/sites/app/Integration/PortalProjectIntakeRecorder.php'=>
            ['c9e9173b9f915d3d546970356617cd5c366e37c3','App\Integration\PortalProjectIntakeRecorder'],
    ];
    private const PRODUCER=[
        'apps/portal/labs/onepage-finance-integrated/Financial.php'=>
            ['df330f740adc9f4cb909b10e042735a1b5946610','PrivateD0Lab\Reconcile'],
        'apps/portal/labs/onepage-service-receipt-ci/ServiceFinancialPort.php'=>
            ['6706943155d79317c60277d7672cd6657709c5d5','PrivateD0IntegratedLab\ServiceFinancialPort'],
        'apps/portal/labs/onepage-dual-inbox/Ingress.php'=>
            ['755824b5b2a899e7229ae71461f6f6d8498da5ec','PrivateOnepageIngress\Ingress'],
        'apps/portal/labs/onepage-dual-inbox/SharedInboxPort.php'=>
            ['2d4498b255879dec9140afae869d58fca11ae9d8','PrivateOnepageIngress\SharedInboxPort'],
        'apps/portal/labs/onepage-dual-inbox/CaptureHarness.php'=>
            ['7e35155b6fce494f51cee795b609172f8cd253c7','PrivateSharedIngress\CaptureHarness'],
        'apps/portal/labs/onepage-dual-inbox/Consumer.php'=>
            ['57ce83cd8b4a15a1f20799b1b69cf3cec19435d2','PrivateD0Consumption\Consumer'],
    ];

    /**
     * Trusted entrypoint only, after M1's two root attestations and exact three
     * effective PostgreSQL slots. The future outer source admission must pin
     * whole commits/trees, signed receipt and immutable helper bytes.
     * @param array<string,PostgresConnection> $connections
     */
    public static function run(Stage3SupervisedSlots $slots,array $connections,
        string $portal982Root,string $portal954Root,string $sites512Root):void {
        $stage='M2_SOURCE_CLOSURE';
        try {
            self::need(array_keys($connections)===['portal_native_orders_ci','portal_asaas_ci','portal_sites_pair_ci']
                && count(array_unique([$portal982Root,$portal954Root,$sites512Root]))===3);
            foreach(['portal'=>$portal982Root,'overlay'=>$portal954Root,'sites'=>$sites512Root] as $k=>$root) {
                self::need(realpath($root)===$root);
                // This is an additional exact detached-pin consistency check,
                // never an alternative to the independent outer Git admission.
                self::need(self::bytes($root.'/.git/HEAD',41)===self::REFS[$k]."\n");
            }
            $proven=(new ReflectionClass($slots))->getFileName();
            self::need(is_string($proven) && hash('sha256',self::bytes($proven,65536))
                ==='fc6c8b38523b6eb3b46caf132272a52c3fe42c5a9fd7cceb727a38ca7f384ea1');
            foreach(self::FIXTURES as $file=>$hash)
                self::need(hash('sha256',self::bytes(__DIR__.'/'.$file,65536))===$hash);
            // Check every exact runtime leaf before loading anything; original
            // SQL and migration leaves are rechecked by their specific helpers.
            foreach([[$portal954Root,self::OVERLAY],[$sites512Root,self::SITES],[$portal982Root,self::PRODUCER]]
                as [$root,$map]) {
                foreach($map as $file=>[$blob,$class]) {
                    $data=self::bytes($root.'/'.$file,262144);
                    self::need(sha1('blob '.strlen($data)."\0".$data)===$blob && !class_exists($class,false));
                }
            }
            // Two #954 classes share #982 FQCNs. Load the exact successor
            // before any native commerce instantiation; keep the #982 interface.
            foreach([[$portal954Root,self::OVERLAY],[$sites512Root,self::SITES],[$portal982Root,self::PRODUCER]]
                as [$root,$map]) {
                foreach($map as $file=>[,$class]) {
                    require $root.'/'.$file;
                    self::need(class_exists($class,false)
                        && (new ReflectionClass($class))->getFileName()===$root.'/'.$file);
                }
            }
            foreach(self::FIXTURES as $file=>$ignored) require_once __DIR__.'/'.$file;

            $stage='M2_NATIVE_ORDER';
            $portal=$connections['portal_native_orders_ci'];
            $inbox=$connections['portal_asaas_ci'];
            $sites=$connections['portal_sites_pair_ci'];
            foreach($connections as $name=>$connection) $slots->beforeDdl($name,$connection);
            $buyer=Stage3PortalOrderSeed::run($slots,$portal,$portal982Root);
            $order=$buyer['order'];
            $stage='M2_NATIVE_PORTS';
            $payment=Stage3PortalFinanceSeed::prepare($slots,$portal,$portal982Root,$buyer['runtime'],$order,$buyer['initial']);
            $incoming=Stage3InboxSeed::run($slots,$inbox,$portal982Root);
            $stage='M2_NATIVE_PAYMENT';
            Stage3NativePaidEntry::run($slots,$portal,$inbox,$payment['finance'],$payment['service'],
                $incoming['capture'],$incoming['worker'],$buyer['auth'],$order,$buyer['initial']);
            $stage='M2_NATIVE_INTAKE_DDL';
            Stage3M2IntakeDdl::install($slots,$portal,$sites,$portal954Root,$sites512Root);
            $ports=Stage3M2IntakeSlots::prepare($slots,$portal,$sites,$order);
            $stage='M2_NATIVE_FRESH_BUYER';
            $fresh=Stage3PortalFinanceSeed::renewBuyer(
                $slots,$portal,$buyer['auth'],$buyer['runtime'],$order,$buyer['initial']);
            $stage='M2_NATIVE_PENDING_INTAKE';
            Stage3NativeIntakeAssertion::run($slots,$portal,$sites,$ports['receiver'],$ports['ledger'],
                $buyer['runtime'],$buyer['openPortalFreshRead'],$buyer['auth'],$order,$ports['tenant'],$buyer['initial'],$fresh);
            // Full completion still also requires parent-owned cleanup and final job fence.
            fwrite(STDOUT,"STAGE3_M2_SCENARIO_COMPLETED_REQUIRES_RUNNER_CLEANUP\n");
        } catch(Throwable) {throw new RuntimeException('STAGE3_M2_REFUSED:'.$stage);}
    }

    private static function bytes(string $p,int $max):string {
        self::need(realpath($p)===$p && is_file($p));
        $data=file_get_contents($p,false,null,0,$max+1);
        self::need(is_string($data) && strlen($data)>0 && strlen($data)<=$max);
        return $data;
    }
    private static function need(bool $ok):void {if(!$ok) throw new RuntimeException();}
}
