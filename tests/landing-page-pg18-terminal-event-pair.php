<?php
declare(strict_types=1);

// Cross-repository DISPOSABLE PG18 security regression. No real Portal
// approvals, authorized actor, S2S, artifact, publication or payment.
$portal = __DIR__.'/../.pinned-portal/apps/portal/';
require_once __DIR__.'/../src/Production/BriefCandidateJson.php';
foreach ([
    'PortalLandingPageAdmissionProjectionCandidate',
    'CurrentPortalLandingPageAdmissionSource',
    'DenyingCurrentPortalLandingPageAdmissionSource',
    'PortalLandingPageAdmissionReadbackCandidate',
    'PortalLandingPageApprovedBriefEnvelopeCandidate',
    'CurrentPortalLandingPageApprovedBriefSource',
    'DenyingCurrentPortalLandingPageApprovedBriefSource',
    'PortalLandingPageApprovedBriefReadbackCandidate',
] as $class) require_once __DIR__.'/../apps/sites/app/Integration/'.$class.'.php';
foreach ([
    'ApprovedLandingPageBriefHead',
    'CurrentApprovedLandingPageBriefHeadSource',
    'Pg18LandingPageApprovedBriefHeadSourceCandidate',
    'LandingPagePrivateBriefEnvelopeCandidate',
] as $class) require_once $portal.'app/Domain/SitesIntegration/'.$class.'.php';

use App\Integration\CurrentPortalLandingPageAdmissionSource;
use App\Integration\CurrentPortalLandingPageApprovedBriefSource;
use App\Integration\PortalLandingPageAdmissionReadbackCandidate as AdmissionGate;
use App\Integration\PortalLandingPageApprovedBriefReadbackCandidate as SitesGate;
use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate as PgHead;
use App\Domain\SitesIntegration\LandingPagePrivateBriefEnvelopeCandidate as PortalEncoder;

$db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres','postgres','ci-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents($portal.'labs/landing-pg-head/schema.sql'));
$site = '11111111-1111-4111-8111-111111111111';
$order = 'order-A';
$contract = 'contract-A';
$bytes = '{"headline":"landing proof fixture, no customer data"}';
$sha = hash('sha256', $bytes);
$ins = $db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.brief_revisions
(tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
 source_sha256,source_bytes,state,approval_ref,approval_epoch,approved_actor_ref)
VALUES (:tenant,:order_ref,:site,:contract,7,:version,:digest,:body,'approved',:approval,:epoch,:actor)
SQL);
$evt = $db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events
(event_ref,tenant_ref,order_ref,site_ref,source_version,approval_epoch,
 approval_ref,actor_ref,event_type)
VALUES (:event_ref,:tenant,:order_ref,:site,:version,:epoch,:approval,:actor,:kind)
SQL);
$revision = static function (string $tenant, int $version) use ($ins,$order,$site,$contract,$bytes,$sha):void {
    $ins->execute([
        'tenant'=>$tenant,'order_ref'=>$order,'site'=>$site,'contract'=>$contract,
        'version'=>$version,'digest'=>$sha,'body'=>$bytes,
        'approval'=>"approval-$tenant-$version",'epoch'=>$version,'actor'=>"actor-$tenant",
    ]);
};
$event = static function (string $tenant, int $version, string $kind) use ($evt,$order,$site):void {
    $evt->execute([
        'event_ref'=>"$tenant-$version-$kind",'tenant'=>$tenant,'order_ref'=>$order,
        'site'=>$site,'version'=>$version,'epoch'=>$version,
        'approval'=>"approval-$tenant-$version",'actor'=>"actor-$tenant",'kind'=>$kind,
    ]);
};

$admissionSource = new class($site,$order,$contract) implements CurrentPortalLandingPageAdmissionSource {
    public function __construct(
        private string $site, private string $order, private string $contract
    ) {}
    public function readAdmission(string $t,string $o,string $s):?string {
        if (!in_array($t,['tenant-A','tenant-B'],true) || $o!==$this->order ||
            $s!==$this->site) return null;
        return json_encode([
            'tenantRef'=>$t,'orderRef'=>$o,'siteRef'=>$s,'contractRef'=>$this->contract,
            'contractRevision'=>7,'productCode'=>'landing_page',
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
};
$headSource = new class($db) implements CurrentPortalLandingPageApprovedBriefSource {
    private PgHead $pg;
    public function __construct(PDO $db) { $this->pg=new PgHead($db); }
    public function readCurrent(string $t,string $o,string $s):?string {
        $head=$this->pg->readCurrent($t,$o,$s);
        return $head===null ? null : PortalEncoder::encode($head);
    }
};
$gate = new SitesGate($headSource,new AdmissionGate($admissionSource));
$checks=0;
$ok=static function (bool $condition,string $case) use (&$checks):void {
    if (!$condition) throw new RuntimeException('SITES TERMINAL PAIR FAILURE: '.$case);
    ++$checks;
};
$deny=static function (callable $fn,string $case) use ($ok):void {
    ob_start();$rejected=false;
    try { $fn(); } catch (RuntimeException $e) {
        $rejected=$e->getMessage()==='SITES_LANDING_APPROVAL_READBACK_DENIED'
            && $e->getPrevious()===null;
    }
    $output=ob_get_clean();
    $ok($rejected && $output==='',$case);
};
$read=static fn(string $tenant='tenant-A',int $v=1) =>
    $gate->assertCurrent($tenant,$order,$site,$contract,7,$v,
        "approval-$tenant-$v",$v,$sha);

$revision('tenant-A',1);$event('tenant-A',1,'approved');
$head=$read();
$ok($head->sourceVersion===1 && $head->sourceBytes()===$bytes,
    'original approved Portal bytes pass Sites decoder and readback');
$ok(hash_equals($sha,$head->sourceSha256),'original SHA matches source bytes');
$revision('tenant-B',1);$event('tenant-B',1,'approved');
$ok($read('tenant-B')->tenantRef==='tenant-B','tenant B can read its own head');

$revision('tenant-A',2);
$event('tenant-A',2,'revoked');
$event('tenant-A',2,'approved'); // invalid resurrection AFTER terminal event
$deny(static fn()=>$read('tenant-A',2),
    'Portal PG18 terminal event before approval denies Sites current readback');
$deny(static fn()=>$read('tenant-A',1),
    'Sites cannot fall back to previously valid v1 after invalid v2');
$ok($read('tenant-B')->tenantRef==='tenant-B',
    'terminal event cannot affect another tenant');

$revision('tenant-A',3);
$event('tenant-A',3,'superseded');
$event('tenant-A',3,'approved');
$deny(static fn()=>$read('tenant-A',3),
    'supersession before approval denies Sites private envelope');
$revision('tenant-A',4);$event('tenant-A',4,'approved');
$ok($read('tenant-A',4)->sourceVersion===4,
    'fresh distinct revision can be read despite old terminal events');
$deny(static fn()=>$read('tenant-A',2),
    'newer version does not permit replay of terminal version');
$ok(!$db->inTransaction(),'all PG18 read-only transactions close');
$db->exec('DROP SCHEMA portal_landing_candidate CASCADE');

echo 'SITES LANDING PG18 TERMINAL PAIR PASS: '.$checks.
    ' checks; isolated PostgreSQL fixture and synthetic admissions only.'."\n";
