<?php
declare(strict_types=1);

// Cross-repository disposable PG18 role isolation regression.
// The admission provider, actors and roles are synthetic, not Portal authority.
$portal=__DIR__.'/../.pinned-portal/apps/portal/';
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
] as $class)require_once __DIR__.'/../apps/sites/app/Integration/'.$class.'.php';
foreach ([
 'ApprovedLandingPageBriefHead','CurrentApprovedLandingPageBriefHeadSource',
 'Pg18LandingPageApprovedBriefHeadSourceCandidate',
 'LandingPagePrivateBriefEnvelopeCandidate',
] as $class)require_once $portal.'app/Domain/SitesIntegration/'.$class.'.php';

use App\Integration\CurrentPortalLandingPageAdmissionSource;
use App\Integration\CurrentPortalLandingPageApprovedBriefSource;
use App\Integration\PortalLandingPageAdmissionReadbackCandidate as Admission;
use App\Integration\PortalLandingPageApprovedBriefReadbackCandidate as SitesGate;
use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate as PortalPg;
use App\Domain\SitesIntegration\LandingPagePrivateBriefEnvelopeCandidate as PortalEnvelope;

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=postgres';
$admin=new PDO($dsn,'postgres','ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec(file_get_contents($portal.'labs/landing-pg-head/schema.sql'));
foreach (['a','b','unbound'] as $role) {
    $admin->exec("CREATE ROLE portal_landing_pair_$role LOGIN PASSWORD 'ci-pair-synthetic-$role'");
    $admin->exec("GRANT USAGE ON SCHEMA portal_landing_candidate TO portal_landing_pair_$role");
    $admin->exec("GRANT SELECT ON ALL TABLES IN SCHEMA portal_landing_candidate TO portal_landing_pair_$role");
}
foreach (['brief_revisions','approval_events'] as $table) {
    foreach (['a'=>'tenant-A','b'=>'tenant-B'] as $role=>$tenant) {
        $admin->exec("CREATE POLICY pair_{$role}_select ON portal_landing_candidate.$table
          FOR SELECT TO portal_landing_pair_$role USING (tenant_ref = '$tenant')");
    }
}
$connect=static fn(string $role):PDO => new PDO(
    $dsn,"portal_landing_pair_$role","ci-pair-synthetic-$role",
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
$pdoA=$connect('a');$pdoB=$connect('b');$pdoUnbound=$connect('unbound');
$site='11111111-1111-4111-8111-111111111111';$order='order-A';$contract='contract-A';
$bytes='{"headline":"fixture privada, sem cliente real"}';$digest=hash('sha256',$bytes);
$revision=$admin->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.brief_revisions
(tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
 source_sha256,source_bytes,state,approval_ref,approval_epoch,approved_actor_ref)
VALUES (:tenant,'order-A',:site,'contract-A',7,:version,:digest,:body,'approved',
 :approval,:version,:actor)
SQL);
$event=$admin->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events
(event_ref,tenant_ref,order_ref,site_ref,source_version,approval_epoch,
 approval_ref,actor_ref,event_type)
VALUES (:ref,:tenant,'order-A',:site,:version,:version,:approval,:actor,:kind)
SQL);
$seed=static function(string $tenant,int $v)use($revision,$event,$site,$bytes,$digest):void {
    $approval="approval-$tenant-$v";$actor="actor-$tenant";
    $revision->execute(['tenant'=>$tenant,'site'=>$site,'version'=>$v,
        'digest'=>$digest,'body'=>$bytes,'approval'=>$approval,'actor'=>$actor]);
    $event->execute(['ref'=>"$tenant-$v-approved",'tenant'=>$tenant,
        'site'=>$site,'version'=>$v,'approval'=>$approval,'actor'=>$actor,
        'kind'=>'approved']);
};
$admissionSource=new class implements CurrentPortalLandingPageAdmissionSource {
    public function readAdmission(string $tenant,string $order,string $site):?string {
        if(!in_array($tenant,['tenant-A','tenant-B'],true) || $order!=='order-A' ||
           $site!=='11111111-1111-4111-8111-111111111111')return null;
        // Deliberately claims both tenants: cannot override the scoped PG role.
        return json_encode(['tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
            'contractRef'=>'contract-A','contractRevision'=>7,
            'productCode'=>'landing_page'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
};
$gate=static function(PDO $pdo)use($admissionSource):SitesGate {
    $source=new class($pdo) implements CurrentPortalLandingPageApprovedBriefSource {
        private PortalPg $reader;
        public function __construct(private PDO $pdo){$this->reader=new PortalPg($pdo);}
        public function readCurrent(string $t,string $o,string $s):?string {
            $head=$this->reader->readCurrent($t,$o,$s);
            return $head===null?null:PortalEnvelope::encode($head);
        }
    };
    return new SitesGate($source,new Admission($admissionSource));
};
$gateA=$gate($pdoA);$gateB=$gate($pdoB);$gateUnbound=$gate($pdoUnbound);
$read=static function(SitesGate $g,string $tenant,int $v=1)use($order,$site,$contract,$digest) {
    return $g->assertCurrent($tenant,$order,$site,$contract,7,$v,
        "approval-$tenant-$v",$v,$digest);
};
$checks=0;
$ok=static function(bool $cond,string $case)use(&$checks):void{
    if(!$cond)throw new RuntimeException('SITES PG18 ROLE PAIR FAILURE: '.$case);
    ++$checks;
};
$deny=static function(callable $fn,string $case)use($ok):void {
    ob_start();$rejected=false;
    try{$fn();}catch(RuntimeException $e){
        $rejected=$e->getMessage()==='SITES_LANDING_APPROVAL_READBACK_DENIED'
            && $e->getPrevious()===null;
    }
    $out=ob_get_clean();$ok($rejected&&$out==='',$case);
};
$ok($pdoA->query('SELECT current_user')->fetchColumn()==='portal_landing_pair_a',
    'real PG18 role A not owner');
$ok($pdoB->query('SELECT current_user')->fetchColumn()==='portal_landing_pair_b',
    'real PG18 role B not owner');
$seed('tenant-A',1);$seed('tenant-B',1);
$ok($read($gateA,'tenant-A')->sourceBytes()===$bytes,
    'tenant A end-to-end PG18 Portal envelope and Sites gate');
$ok($read($gateB,'tenant-B')->sourceVersion===1,
    'tenant B end-to-end with independent role');
$deny(static fn()=>$read($gateA,'tenant-B'),
    'synthetic admission B cannot enlarge Portal role A policy');
$deny(static fn()=>$read($gateB,'tenant-A'),
    'synthetic admission A cannot enlarge Portal role B policy');
$deny(static fn()=>$read($gateUnbound,'tenant-A'),
    'unbound SELECT role gets denied despite fake admission');
$event->execute(['ref'=>'tenant-A-1-revoked','tenant'=>'tenant-A',
    'site'=>$site,'version'=>1,'approval'=>'approval-tenant-A-1',
    'actor'=>'actor-tenant-A','kind'=>'revoked']);
$deny(static fn()=>$read($gateA,'tenant-A'),
    'terminal revocation in PG18 denied by Sites under scoped role');
$ok($read($gateB,'tenant-B')->sourceVersion===1,
    'A revocation cannot revoke B');
$seed('tenant-A',2);
$ok($read($gateA,'tenant-A',2)->sourceVersion===2,
    'new distinct approved revision can cross private handoff');
$deny(static fn()=>$read($gateA,'tenant-A',1),
    'old revoked approval cannot replay after new revision');
$deny(static fn()=>$read($gateB,'tenant-A',2),
    'new A version cannot be read under B role');
$ok(!$pdoA->inTransaction()&&!$pdoB->inTransaction()&&!$pdoUnbound->inTransaction(),
    'all PG18 reader snapshots close');
$ok((int)$admin->query('SELECT count(*) FROM portal_landing_candidate.approval_events')
    ->fetchColumn()===4,'readers never wrote events');
$admin->exec('DROP SCHEMA portal_landing_candidate CASCADE');
echo "SITES LANDING PG18 TENANT ROLE PAIR PASS: $checks checks; synthetic RLS roles and admission only.\n";
