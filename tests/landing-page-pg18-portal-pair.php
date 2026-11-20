<?php
declare(strict_types=1);

// Disposable PG18 cross-repository proof, no official Portal actor or live S2S.
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
] as $name) require_once __DIR__.'/../apps/sites/app/Integration/'.$name.'.php';
foreach ([
    'ApprovedLandingPageBriefHead',
    'CurrentApprovedLandingPageBriefHeadSource',
    'Pg18LandingPageApprovedBriefHeadSourceCandidate',
    'LandingPagePrivateBriefEnvelopeCandidate',
] as $name) require_once $portal.'app/Domain/SitesIntegration/'.$name.'.php';

use App\Integration\CurrentPortalLandingPageAdmissionSource;
use App\Integration\CurrentPortalLandingPageApprovedBriefSource;
use App\Integration\PortalLandingPageAdmissionReadbackCandidate as AdmissionGate;
use App\Integration\PortalLandingPageApprovedBriefReadbackCandidate as SitesGate;
use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate as PgHead;
use App\Domain\SitesIntegration\LandingPagePrivateBriefEnvelopeCandidate as PortalEncoder;

$db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres','postgres','ci-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents($portal.'labs/landing-pg-head/schema.sql'));
$tenant='tenant-A'; $order='order-A';
$site='11111111-1111-4111-8111-111111111111';
$contract='contract-A'; $approval='approval-A';
$bytes='{"headline":"exemplo publico ficticio","cta":"Contato"}';
$digest=hash('sha256',$bytes);
$revision=$db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.brief_revisions
(tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
 source_sha256,source_bytes,state,approval_ref,approval_epoch,approved_actor_ref)
VALUES (:tenant,:order_ref,:site,:contract,7,:source_version,:source_sha256,:source_bytes,
 :state,:approval_ref,:approval_epoch,:approved_actor_ref)
SQL);
$revision->execute([
    'tenant'=>$tenant,'order_ref'=>$order,'site'=>$site,'contract'=>$contract,
    'source_version'=>1,'source_sha256'=>$digest,'source_bytes'=>$bytes,'state'=>'approved',
    'approval_ref'=>$approval,'approval_epoch'=>1,'approved_actor_ref'=>'actor-fixture',
]);
$event=$db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events
(event_ref,tenant_ref,order_ref,site_ref,source_version,approval_epoch,
 approval_ref,actor_ref,event_type)
VALUES (:event_ref,:tenant,:order_ref,:site,1,1,:approval_ref,'actor-fixture',:event_type)
SQL);
$event->execute([
    'event_ref'=>'approved-A','tenant'=>$tenant,'order_ref'=>$order,
    'site'=>$site,'approval_ref'=>$approval,'event_type'=>'approved',
]);
$projection=json_encode([
    'tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
    'contractRef'=>$contract,'contractRevision'=>7,'productCode'=>'landing_page',
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$admissionSource=new class($projection) implements CurrentPortalLandingPageAdmissionSource {
    public int $calls=0;
    public function __construct(public string $wire) {}
    public function readAdmission(string $t,string $o,string $s):?string {
        ++$this->calls;
        return $t==='tenant-A' && $o==='order-A' &&
            $s==='11111111-1111-4111-8111-111111111111' ? $this->wire : null;
    }
};
$headSource=new class($db) implements CurrentPortalLandingPageApprovedBriefSource {
    public int $calls=0;
    public ?Closure $beforeRead=null;
    private PgHead $pg;
    public function __construct(PDO $db) { $this->pg=new PgHead($db); }
    public function readCurrent(string $t,string $o,string $s):?string {
        ++$this->calls;
        if($this->beforeRead!==null) ($this->beforeRead)($this->calls);
        $head=$this->pg->readCurrent($t,$o,$s);
        return $head===null ? null : PortalEncoder::encode($head);
    }
};
$gate=new SitesGate($headSource,new AdmissionGate($admissionSource));
$checks=0;
$ok=static function(bool $yes,string $label)use(&$checks):void{
    if(!$yes)throw new RuntimeException('SITES LANDING PG18 PAIR FAILURE: '.$label);
    ++$checks;
};
$deny=static function(callable $fn,string $label)use($ok):void{
    ob_start();$redacted=false;
    try{$fn();}catch(RuntimeException $e){
        $redacted=$e->getMessage()==='SITES_LANDING_APPROVAL_READBACK_DENIED'
            && $e->getPrevious()===null;
    }
    $out=ob_get_clean();
    $ok($redacted && $out==='',$label);
};
$read=static fn(string $t='tenant-A',int $version=1,int $epoch=1)
    =>$gate->assertCurrent($t,'order-A',
        '11111111-1111-4111-8111-111111111111','contract-A',7,
        $version,'approval-A',$epoch,$digest);

$head=$read();
$ok($head->sourceVersion===1 && $head->approvalEpoch===1,
    'latest Portal head from real PG18 matches expected version and approval epoch');
$ok(hash_equals($head->sourceSha256,$digest) && $head->sourceBytes()===$bytes,
    'Portal wire to Sites exact original source bytes');
$ok($headSource->calls===2 && $admissionSource->calls===4,
    'two independent PG snapshots and admission checks before/after');
$ok(!$db->inTransaction(),'both PG read-only transactions closed');
$deny(static fn()=>$read('tenant-B'),'foreign tenant cannot reuse tenant A approval');
$deny(static fn()=>$read('tenant-A',2),'caller cannot claim higher version');
$deny(static fn()=>$read('tenant-A',1,2),'caller cannot claim higher epoch');

$headSource->calls=0;
$headSource->beforeRead=static function(int $calls)use($event,$tenant,$order,$site,$approval):void{
    if($calls===2) $event->execute([
        'event_ref'=>'revoked-between-reads','tenant'=>$tenant,'order_ref'=>$order,
        'site'=>$site,'approval_ref'=>$approval,'event_type'=>'revoked',
    ]);
};
$deny(static fn()=>$read(),'real PostgreSQL revocation between independent snapshots denies');
$headSource->beforeRead=null;
$deny(static fn()=>$read(),'revoked head is not reusable on retry');
$ok(!$db->inTransaction(),'rejected read leaves no PG transaction');

echo 'SITES LANDING PG18 PORTAL PAIR PASS: '.$checks.
 ' checks; PG18 fixture, Portal approval actor and admissions NOT operational.'."\n";
