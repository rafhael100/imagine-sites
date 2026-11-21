<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Commerce\PostgresPortalSiteContractSource;
use App\Integration\StoredBriefSnapshotReader;
use Imagine\Sites\Production\BriefSnapshotInput;
use SitesStage3CiOnly\Stage3ExactBriefRoundTrip;
use SitesStage3CiOnly\Stage3RenderStoredBrief;
use SensitiveParameter;
use Illuminate\Database\PostgresConnection;
use PDO;
use ReflectionClass;
use RuntimeException;
use Throwable;

/** Cumulative #592 fixture: keep the refusals, then use explicitly synthetic approval. */
final class Stage3M4Driver
{
    private const REFS=[
        'portal'=>'4dc198d2109fe1c7590b5f012e77757ed593e1d5',
        'overlay'=>'d1aaab523848baf80325a023f3ebbf4998a3607f',
        'sites'=>'9b80387fe1c580436b444a6aad2b63f3baafa37d',
        'snapshot'=>'99aabc8300b58a1494287a841c71bb302224de44',
    ];
    private const FIXTURES=[
        'Stage3PortalOrderSeed.php'=>'6893fe479908b1c2d99003385b9112cf94b76e8db1bd8491faf2c663fe6c47a7',
        'Stage3PortalFinanceSeed.php'=>'f66ad556b036fa0f4c5f4cb611d14329b593b64ba663a5008ec69ac570250d2e',
        'Stage3InboxSeed.php'=>'8c67b258f7ce10563545020a9d16e9d2192947d447d3720be601505d441d0c1e',
        'Stage3NativePaidEntry.php'=>'323c8f7c075c1f7ecca21622270551d003bcee99eb3107ef6588d3a88494063c',
        'Stage3M2IntakeDdl.php'=>'a03d7c5c5f1760f1a46cf5ae40c8b10a404e94a4b75307f891ff87b3954ec72f',
        'Stage3M2IntakeSlots.php'=>'95aeb0fe8b98fede5ab0390b34c215572dfdae30103ac9d5f7d3a09a3bb16927',
        'Stage3NativeIntakeAssertion.php'=>'63434812bbd79f0dd11d7d88b07157d9a53e14b7e2ef4354e8b28eca7ae7ee8b',
        'Stage3M3MissingApprovedSource.php'=>'69224eaa587876aacd8cba4337f77ff1f3c9c64044a85a815c43d4540ef0af47',
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

    private const M3_NATIVE_CONTRACT=[
        'apps/portal/app/Domain/SitesIntegration/PortalSiteContractSource.php'=>'9733da25486bc038b63a5621698b29c918f6a970',
        'apps/portal/app/Domain/SitesIntegration/SiteContractSnapshot.php'=>'44cbfad8ed0c112455ccc7b862a86b57f6c5f9a9',
        'apps/portal/app/Infrastructure/Commerce/PostgresPortalSiteContractSource.php'=>'17e92fb3d3b23a7af402feeb813b7351b4618aa7',
    ];
    // Exact additional passive declarations reached by the two refusal paths.
    // Encoder, renderer, providers and alternate App Composer maps stay unloaded.
    private const M3_APPROVED=[
        'apps/portal/app/Domain/SitesIntegration/ApprovedOnepageBriefSnapshotSource.php'=>
            ['b34c87563456b12cde3c883939f9deccdffbdc79','App\Domain\SitesIntegration\ApprovedOnepageBriefSnapshotSource',true],
        'apps/portal/app/Domain/SitesIntegration/CurrentOnepageBriefApprovalWitness.php'=>
            ['9fb98c381bb3a644f1491a9f3d9e0e38f1e58834','App\Domain\SitesIntegration\CurrentOnepageBriefApprovalWitness',true],
        'apps/portal/app/Domain/SitesIntegration/ApprovedOnepageBriefSnapshot.php'=>
            ['beba2be5329116180aa1be922e7af0fd5bbbd737','App\Domain\SitesIntegration\ApprovedOnepageBriefSnapshot',false],
        'apps/portal/app/Domain/SitesIntegration/DenyingApprovedOnepageBriefSnapshotSource.php'=>
            ['60b1efa325b7859256c5ab2683fbac525b289804','App\Domain\SitesIntegration\DenyingApprovedOnepageBriefSnapshotSource',false],
        'apps/portal/app/Domain/SitesIntegration/DenyingCurrentOnepageBriefApprovalWitness.php'=>
            ['fdc95dec632554071ea5313cd8f1d6c4333929cd','App\Domain\SitesIntegration\DenyingCurrentOnepageBriefApprovalWitness',false],
        'apps/portal/app/Domain/SitesIntegration/OnepageApprovedBriefExportGate.php'=>
            ['58000f18eddce8cc93b7c76a8135817b9b426b07','App\Domain\SitesIntegration\OnepageApprovedBriefExportGate',false],
    ];
    private const M3_PREVIEW=[
        'src/Production/OnepageBuyerPrivatePreviewCandidate.php'=>
            ['571838eefc9c4872515c5c2a2c8453328febd47b','Imagine\Sites\Production\OnepageBuyerPrivatePreviewCandidate',false],
    ];

    private const M4_FIXTURES=[
        'Stage3ExactBriefDdl.php'=>'fb527391297ff20e42fb9f29f41819fa48af0613a66e87145d84ed8c19a9d204',
        'Stage3ExactBriefSlots.php'=>'414f25984fb704cc86493807977bc236fecde4eb3f785becceda606507d0b3a8',
        'Stage3ExactSyntheticApprovedBrief.php'=>'ee0a1bce2572827e00f0b954a532220a2c527ddef0fd0e21c64b98486bb12831',
        'Stage3ExactBriefRoundTrip.php'=>'0749dd96a40dd884dabfc4473849fb07dd35f8c94e3542a9611244db2ea1206f',
        'Stage3RenderStoredBrief.php'=>'92df52f04fe076891318c4bb069c8b99e93752384f9f7566aba315995b31e2fc',
    ];
    private const M4_EXPORTER=[
        'apps/portal/app/Domain/SitesIntegration/OnepageBriefSnapshotCandidateEncoder.php'=>
            ['5e8285da6ae8ec7151396c12d7b9699bd583312d','App\Domain\SitesIntegration\OnepageBriefSnapshotCandidateEncoder'],
        'apps/portal/app/Domain/SitesIntegration/OnepageRenderPlanCandidateEncoder.php'=>
            ['761a35ac168dd792c7e5469ba459f878a6dd3483','App\Domain\SitesIntegration\OnepageRenderPlanCandidateEncoder'],
    ];
    private const M4_SNAPSHOT_AND_RENDER=[
        'src/Production/BriefCandidateJson.php'=>['5930299ec3d284aa23278053915a5a0c63f28c1c','Imagine\Sites\Production\BriefCandidateJson'],
        'src/Production/BriefSnapshotInput.php'=>['769a943249d826b5e8e9a37b5c6d7cf3dbc1ec2b','Imagine\Sites\Production\BriefSnapshotInput'],
        'src/Production/ReleaseArtifact.php'=>['deafa62e5a741fab8f97b94d853f72f7bd5a8f0c','Imagine\Sites\Production\ReleaseArtifact'],
        'src/Production/SiteBrief.php'=>['0c6a8cdfb4e5fe023fe1db169a79f47d74b650a8','Imagine\Sites\Production\SiteBrief'],
        'src/Production/StaticSiteRenderer.php'=>['9f3f3e116fe02b22363b14cd277365d9f5466827','Imagine\Sites\Production\StaticSiteRenderer'],
        'src/Production/InertPreviewAudit.php'=>['538d06508fe3d4d420621e5ca73e41c93a903720','Imagine\Sites\Production\InertPreviewAudit'],
        'src/Production/LocalSiteRendering.php'=>['93b76a1223287362ec32a147e33cdfd96edce16c','Imagine\Sites\Production\LocalSiteRendering'],
        'src/Production/BriefRenderPlanInput.php'=>['66f61f6ef95d31bd36b3d4e8ac61d162eb21fb02','Imagine\Sites\Production\BriefRenderPlanInput'],
        'src/Production/BriefRenderProvenance.php'=>['34d59f4a0791079b9e1bc42677fa18f3f85e4af1','Imagine\Sites\Production\BriefRenderProvenance'],
        'apps/sites/app/Integration/BriefSnapshotTransaction.php'=>['1358b65d76a81d3e6ef18169a53d830c352bc401','App\Integration\BriefSnapshotTransaction'],
        'apps/sites/app/Integration/PortalBriefSnapshotRecorder.php'=>['8e49511e9a9cd43e3e42a43f82714ed793c3dd6d','App\Integration\PortalBriefSnapshotRecorder'],
        'apps/sites/app/Integration/StoredBriefSnapshotReader.php'=>['f81df95f595621eb1290ea68dafcc7f734aa1e31','App\Integration\StoredBriefSnapshotReader'],
    ];
    private const M4_REVIEW=[
        'src/Production/OnepageReviewHandoffCandidate.php'=>['b3a7bfdced9d79a3600219ced1fd43dc4794161c','Imagine\Sites\Production\OnepageReviewHandoffCandidate'],
    ];
    private const M4_EDITORIAL='contracts/portal-sites/candidates/brief-production-v1/examples/render-input.synthetic.json';
    private const M4_GOLDEN='contracts/portal-sites/candidates/brief-production-v1/examples/rendered.synthetic.html';

    /**
     * Trusted entrypoint only, after M1's two root attestations and exact three
     * effective PostgreSQL slots. The future outer source admission must pin
     * whole commits/trees, signed receipt and immutable helper bytes.
     * @param array<string,PostgresConnection> $connections
     */
    public static function run(Stage3SupervisedSlots $slots,array $connections,
        string $portal982Root,string $portal954Root,string $sites512Root,string $portal976Root,string $sites570Root,string $sites541Root):void {
        $stage='M2_SOURCE_CLOSURE';
        try {
            $roots=['portal'=>$portal982Root,'overlay'=>$portal954Root,'sites'=>$sites512Root,
                'approved'=>$portal976Root,'preview'=>$sites570Root,'snapshot'=>$sites541Root];
            self::need(array_keys($connections)===['portal_native_orders_ci','portal_asaas_ci','portal_sites_pair_ci']
                && count(array_unique($roots))===6);
            foreach($roots as $root) self::need(realpath($root)===$root && is_dir($root));
            self::need(basename($portal976Root)==='portal-976' && basename($sites570Root)==='sites-570'
                && dirname($portal976Root)===dirname($sites570Root)
                && basename($sites541Root)==='sites-541' && dirname($sites541Root)===dirname($sites570Root));
            foreach([$portal976Root,$sites570Root] as $leafRoot)
                self::need(!file_exists($leafRoot.'/.git') && !is_link($leafRoot.'/.git'));
            // The original three M2 roots and the #541 snapshot root are Git checkouts. Outer admission
            // acquired the two leaf-only roots by exact public commit URLs.
            foreach(array_intersect_key($roots,self::REFS) as $k=>$root) {
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
            $stage='M3_PASSIVE_SOURCE_CLOSURE';
            foreach(self::M3_NATIVE_CONTRACT as $file=>$blob)
                self::blob($portal982Root.'/'.$file,$blob);
            $passive=[[$portal976Root,self::M3_APPROVED],[$sites570Root,self::M3_PREVIEW]];
            foreach($passive as [$root,$map]) {
                foreach($map as $file=>[$blob,$name,$isInterface]) {
                    self::blob($root.'/'.$file,$blob);
                    self::need(!class_exists($name,false) && !interface_exists($name,false));
                }
            }
            foreach($passive as [$root,$map]) {
                foreach($map as $file=>[$blob,$name,$isInterface]) {
                    require $root.'/'.$file;
                    $exists=$isInterface?interface_exists($name,false):class_exists($name,false);
                    self::need($exists);
                    $decl=new ReflectionClass($name);
                    self::need($decl->isInterface()===$isInterface && $decl->getFileName()===$root.'/'.$file);
                }
            }

            $stage='M4_SOURCE_PINS';
            $m4sources=[[$portal976Root,self::M4_EXPORTER],[$sites541Root,self::M4_SNAPSHOT_AND_RENDER],
                [$sites570Root,self::M4_REVIEW]];
            foreach($m4sources as [$root,$map]) {
                foreach($map as $file=>[$oid,$name]) {
                    self::blob($root.'/'.$file,$oid);
                    self::need(!class_exists($name,false) && !interface_exists($name,false));
                }
            }
            foreach(self::M4_FIXTURES as $file=>$sha)
                self::need(hash('sha256',self::bytes(__DIR__.'/'.$file,65536))===$sha);
            self::blob($sites541Root.'/'.self::M4_EDITORIAL,'f4243f74206fcabfcd8c1a2331e899afec005a51');
            self::blob($sites541Root.'/'.self::M4_GOLDEN,'975da5d1863c9629fd427948e346cff9a1afd195');

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
            $stage='M3_NATIVE_SAME_ORDER_REFUSAL';
            // Keep the already-green M2 assertion byte-identical. Get only
            // its native buyer projection again, from an independent PDO.
            $readerPdo=($buyer['openPortalFreshRead'])();
            self::need($readerPdo instanceof PDO);
            $reader=new PostgresOnepageOrders($readerPdo,'portal_native_orders_ci');
            $visible=$reader->findOwnedSiteIntake($fresh,$order->id);
            self::need(is_array($visible) && ($visible['status']??null)==='recorded'
                && ($visible['state']??null)==='awaiting_official_authority'
                && ($visible['authorityVerified']??null)===false && ($visible['clientAccepted']??null)===false
                && ($visible['canPublish']??null)===false);
            Stage3M3MissingApprovedSource::run($slots,$portal,$sites,$reader,$order,$visible,
                $fresh,$buyer['initial'],$portal976Root,$sites570Root);
            fwrite(STDOUT,"STAGE3_M3_APPROVED_SOURCE_MISSING_ZERO_RENDER_AND_LEDGER_UNCHANGED_OK\n");

            $stage='M4_EXACT_CLASSES_AND_DDL';
            foreach($m4sources as [$root,$map]) {
                foreach($map as $file=>[$oid,$name]) {
                    self::blob($root.'/'.$file,$oid);
                    self::need(!class_exists($name,false) && !interface_exists($name,false));
                    require $root.'/'.$file;
                    self::need(class_exists($name,false) && (new ReflectionClass($name))->getFileName()===$root.'/'.$file);
                }
            }
            foreach(self::M4_FIXTURES as $file=>$sha) require __DIR__.'/'.$file;
            Stage3ExactBriefDdl::install($slots,$sites,$sites541Root);
            $stage='M4_SYNTHETIC_EXACT_APPROVAL';
            self::approvedBrief($slots,$sites,$reader,$order,$ports['tenant'],$visible,$fresh,$sites541Root);
            fwrite(STDOUT,"STAGE3_M4_SYNTHETIC_APPROVED_BRIEF_PERSISTED_AND_PRIVATE_HTML_OK\n");
        } catch(Throwable) {throw new RuntimeException('STAGE3_M4_REFUSED:'.$stage);}
    }

    private static function approvedBrief(Stage3SupervisedSlots $slots,PostgresConnection $sites,
        PostgresOnepageOrders $orders,OnepageAcceptedOrder $order,string $tenant,array $visible,
        #[SensitiveParameter] string $fresh,string $sitesRoot):void {
        $site=$visible['siteRef']??null;
        self::need(is_string($site) && $site!==$order->id
            && ($visible['state']??null)==='awaiting_official_authority'
            && $orders->findOwnedSiteIntake($fresh,$order->id)===$visible);
        $source=new PostgresPortalSiteContractSource($orders,$fresh);
        $contract=$source->findAuthorizedSiteOrder($order->id);
        self::need($contract instanceof SiteContractSnapshot && $contract->orderRef===$order->id
            && $contract->tenantRef===$order->companyReference && $contract->contractRef===$order->contractRef
            && $contract->contractRevision===$order->contractRevision && $contract->productCode==='onepage');
        $admin=$slots->beforeDdl('portal_sites_pair_ci',$sites);
        self::need(self::snapshotCount($admin)===0);
        $roles=Stage3ExactBriefSlots::prepare($slots,$sites,$order,$tenant,$site);
        $roundTrip=new Stage3ExactBriefRoundTrip($roles['receiver'],$roles['reader']);
        $editorial=self::bytes($sitesRoot.'/'.self::M4_EDITORIAL,32768);
        $golden=self::bytes($sitesRoot.'/'.self::M4_GOLDEN,150000);
        $expected=['sha256'=>'ab999fc8c61d4f22ab250c6d5d86442a0ceca24659d1f4173db3580e4694740e','sizeBytes'=>5223];
        self::need(strlen($golden)===$expected['sizeBytes'] && hash('sha256',$golden)===$expected['sha256']);
        $planSelection=['operationId'=>'stage3-m4-render-a','idempotencyKey'=>'stage3-m4-render-a-idem',
            'releaseRef'=>'stage3-m4-synthetic-a','renderInputRef'=>'stage3-m4-editorial-golden'];
        $writes=0;$selected=null;$renderExact=null;$revocation=null;
        // The only write callback is handed off by fixture's real #976 gate
        // after its official parser has validated the exact synthetic A/12.
        \SitesStage3CiOnly\assertExactSyntheticApprovedBrief($source,$contract,$site,
            static function(BriefSnapshotInput $input,\Closure $isAStillCurrent)use(
                &$writes,&$selected,&$renderExact,&$revocation,$order,$contract,$site,$roles,$roundTrip,
                $editorial,$golden,$planSelection,$expected,$admin,$slots,$sites
            ):void {
                self::need($writes===0 && $isAStillCurrent()===true
                    && $input->tenantRef===$contract->tenantRef && $input->orderRef===$contract->orderRef
                    && $input->contractRef===$contract->contractRef && $input->contractRevision===$contract->contractRevision
                    && $input->productCode==='onepage' && $input->siteRef===$site
                    && $input->snapshotRef==='22222222-2222-4222-8222-222222222222'
                    && $input->sourceVersion===12 && $input->briefRevision===1
                    && $input->payloadSha256==='4887417fdeaf589129602753a2fc31e8426eace8205b4f75cc980dc25ff9298c');
                self::need($slots->beforeDdl('portal_sites_pair_ci',$sites)===$admin);
                ++$writes;
                $roundTrip($input); // real independent restricted recorder transaction + read
                self::need(self::snapshotCount($admin)===1);

                // These are explicitly separate fictitious witness facts, tied
                // to the already validated A/12 selection, never taken from a DB
                // receipt/flags and never installed as application authority.
                $bound=['tenantRef'=>$contract->tenantRef,'orderRef'=>$order->id,'siteRef'=>$site,
                    'snapshotRef'=>'22222222-2222-4222-8222-222222222222','sourceVersion'=>12,'briefRevision'=>1,
                    'manifestSha256'=>$input->manifestSha256,
                    'payloadSha256'=>'4887417fdeaf589129602753a2fc31e8426eace8205b4f75cc980dc25ff9298c'];
                $head=$bound+['contractRef'=>$contract->contractRef,'contractRevision'=>$contract->contractRevision,
                    'productCode'=>'onepage'];
                $buyerFact=$bound+['buyerRef'=>$order->buyerId,'confirmationRef'=>'only-synthetic-rights-confirmation-a',
                    'rightsDeclared'=>true,'confirmed'=>true];
                $reviewerFact=$bound+['reviewerRef'=>'synthetic-stage3-independent-reviewer',
                    'approvalRef'=>'synthetic-editorial-A','approved'=>true];
                self::need($reviewerFact['reviewerRef']!==$buyerFact['buyerRef']);
                $matches=static function(array $actual)use($head):bool {
                    $expected=$head;ksort($actual);ksort($expected);return $actual===$expected;
                };
                $headPort=static fn(string $t,string $o,string $s):?array=>
                    [$t,$o,$s]===[$head['tenantRef'],$head['orderRef'],$head['siteRef']]?$head:null;
                $buyerPort=static fn(array $selection):?array=>$matches($selection)?$buyerFact:null;
                $reviewerPort=static fn(array $selection):?array=>
                    $matches($selection)&&$isAStillCurrent()===true?$reviewerFact:null;

                $renderExact=static fn(StoredBriefSnapshotReader $read):array=>
                    Stage3RenderStoredBrief::render($read,$input,$order->buyerId,$editorial,$planSelection,
                        $expected,$headPort,$buyerPort,$reviewerPort);
                $revocation=$isAStillCurrent;
                // Separate PDO opened AFTER writer committed; no shared PHP
                // transaction or object can stand in for independent readback.
                $freshReader=new StoredBriefSnapshotReader(($roles['openFreshReader'])());
                self::readExact($freshReader,$input);
                $preview=$renderExact($freshReader);
                self::need($preview['html']===$golden);
                $selected=$input;
            });
        self::need($writes===1 && $selected instanceof BriefSnapshotInput
            && $renderExact instanceof \Closure && $revocation instanceof \Closure
            && $revocation()===false && $slots->beforeDdl('portal_sites_pair_ci',$sites)===$admin);
        $history=new StoredBriefSnapshotReader(($roles['openFreshReader'])());
        self::readExact($history,$selected);
        $denied=false;
        try { $renderExact($history); }
        catch(RuntimeException $e) {
            self::need($e->getMessage()==='ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED' && $e->getPrevious()===null);
            $denied=true;
        }
        self::need($denied && self::snapshotCount($admin)===1
            && $orders->findOwnedSiteIntake($fresh,$order->id)===$visible);
    }

    private static function readExact(StoredBriefSnapshotReader $reader,BriefSnapshotInput $expected):void {
        $actual=$reader->readExact($expected->siteRef,$expected->snapshotRef,$expected->briefRevision,$expected->manifestSha256);
        self::need($actual==$expected && $actual->sourceJson===$expected->sourceJson
            && $actual->snapshotJson===$expected->snapshotJson);
    }

    private static function snapshotCount(PDO $p):int {
        $q=$p->query(<<<'SQL'
SELECT (SELECT count(*)::int FROM sites_core.portal_brief_snapshots) AS snapshots,
       NOT EXISTS(SELECT 1 FROM sites_preview.versions) AND NOT EXISTS(SELECT 1 FROM sites_preview.reviews)
         AND NOT EXISTS(SELECT 1 FROM sites_core.release_artifacts)
         AND NOT EXISTS(SELECT 1 FROM sites_core.release_binding_events)
         AND NOT EXISTS(SELECT 1 FROM sites_core.site_outbox WHERE delivery_state<>'blocked') AS inert
SQL);
        self::need($q!==false);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        self::need(count($rows)===1 && $rows[0]['inert']===true && is_int($rows[0]['snapshots']));
        return $rows[0]['snapshots'];
    }

    private static function blob(string $path,string $oid):void {
        $data=self::bytes($path,262144);
        self::need(sha1('blob '.strlen($data)."\0".$data)===$oid);
    }

    private static function bytes(string $p,int $max):string {
        self::need(realpath($p)===$p && is_file($p));
        $data=file_get_contents($p,false,null,0,$max+1);
        self::need(is_string($data) && strlen($data)>0 && strlen($data)<=$max);
        return $data;
    }
    private static function need(bool $ok):void {if(!$ok) throw new RuntimeException();}
}
