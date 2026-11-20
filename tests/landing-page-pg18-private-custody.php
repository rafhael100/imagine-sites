<?php

declare(strict_types=1);

// Disposable PG18 custody proof with real, non-owner login roles.
// Synthetic Portal head/admission: NEVER client approval or publishing authority.
if (getenv('SITES_LANDING_CUSTODY_CI') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('LANDING_CUSTODY_FIXTURE_CI_ONLY');
}

$portal = __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/';
require_once $portal.'ApprovedLandingPageBriefHead.php';
require_once $portal.'LandingPagePrivateBriefEnvelopeCandidate.php';
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
    'PortalLandingPageEditorialDraftCandidate',
    'PortalLandingPagePrivateCustodyCandidate',
] as $name) {
    require_once __DIR__.'/../apps/sites/app/Integration/'.$name.'.php';
}

use App\Domain\SitesIntegration\ApprovedLandingPageBriefHead as PortalHead;
use App\Domain\SitesIntegration\LandingPagePrivateBriefEnvelopeCandidate as PortalWire;
use App\Integration\CurrentPortalLandingPageAdmissionSource;
use App\Integration\CurrentPortalLandingPageApprovedBriefSource;
use App\Integration\PortalLandingPageAdmissionReadbackCandidate;
use App\Integration\PortalLandingPageApprovedBriefReadbackCandidate;
use App\Integration\PortalLandingPageEditorialDraftCandidate;
use App\Integration\PortalLandingPagePrivateCustodyCandidate;

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=postgres';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false];
$admin = new PDO($dsn,'postgres','ci-only',$options);
$admin->exec(file_get_contents(
    __DIR__.'/../contracts/portal-sites/candidates/landing-private-custody-pg18.sql'
));
foreach (['a','b','unbound'] as $who) {
    $admin->exec("CREATE ROLE sites_landing_ci_$who LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD 'ci-only-$who'");
    $admin->exec("GRANT USAGE ON SCHEMA sites_landing_custody TO sites_landing_ci_$who");
}
foreach (['a','b'] as $who) {
    $admin->exec("GRANT EXECUTE ON FUNCTION sites_landing_custody.record_pending(text,text,text,text) TO sites_landing_ci_$who");
}
$site='11111111-1111-4111-8111-111111111111';
$seed=$admin->prepare(
    "INSERT INTO sites_landing_custody.principals(login_name,tenant_ref,active) VALUES(?,?,true)"
);
foreach (['a'=>'tenant-A','b'=>'tenant-B'] as $who=>$tenant) {
    $seed->execute(["sites_landing_ci_$who",$tenant]);
    $admin->prepare("INSERT INTO sites_landing_custody.admitted_sites
      (tenant_ref,order_ref,site_ref,contract_ref,contract_revision,active)
      VALUES(?,'order-A',?,'contract-A',7,true)")->execute([$tenant,$site]);
}
$login=static fn(string $who): PDO => new PDO(
    $dsn,"sites_landing_ci_$who","ci-only-$who",$options
);
$dbA=$login('a');$dbB=$login('b');$dbUnbound=$login('unbound');
$state=(object)[
    'bytes'=>json_encode([
        'schemaVersion'=>'portal.landing-content.candidate.v1',
        'headline'=>'Imagine para sua empresa <script>alert(1)</script>',
        'subheadline'=>'Uma apresentação digital clara, segura e transparente.',
        'benefits'=>['Identidade visual Imagine','Informações organizadas'],
        'ctaLabel'=>'Solicitar contato'
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
    'version'=>1,'calls'=>0,'cutoff'=>null,'revoked'=>false
];
$source=new class($state,$site) implements CurrentPortalLandingPageApprovedBriefSource {
    public function __construct(private object $s,private string $site) {}
    public function readCurrent(string $t,string $o,string $site):?string {
        if (!in_array($t,['tenant-A','tenant-B'],true) || $o!=='order-A' || $site!==$this->site) return null;
        ++$this->s->calls;
        if ($this->s->revoked || ($this->s->cutoff!==null && $this->s->calls>=$this->s->cutoff)) return null;
        $v=$this->s->version;
        return PortalWire::encode(new PortalHead(
            $t,$o,$site,'contract-A',7,$v,hash('sha256',$this->s->bytes),
            "approval-$v",$v,$this->s->bytes
        ));
    }
};
$admission=new class($site) implements CurrentPortalLandingPageAdmissionSource {
    public function __construct(private string $site) {}
    public function readAdmission(string $t,string $o,string $site):?string {
        if (!in_array($t,['tenant-A','tenant-B'],true) || $o!=='order-A' || $site!==$this->site) return null;
        return json_encode([
            'tenantRef'=>$t,'orderRef'=>$o,'siteRef'=>$site,
            'contractRef'=>'contract-A','contractRevision'=>7,'productCode'=>'landing_page'
        ],JSON_THROW_ON_ERROR);
    }
};
$draft=new PortalLandingPageEditorialDraftCandidate(
    new PortalLandingPageApprovedBriefReadbackCandidate(
        $source,new PortalLandingPageAdmissionReadbackCandidate($admission)
    )
);
$a=new PortalLandingPagePrivateCustodyCandidate($dbA,$draft);
$b=new PortalLandingPagePrivateCustodyCandidate($dbB,$draft);
$run=static function(
    PortalLandingPagePrivateCustodyCandidate $app,string $op,string $tenant='tenant-A',
    ?int $version=null,
)use($state,$site):array {
    $v=$version??$state->version;
    return $app->stage($op,$tenant,'order-A',$site,'contract-A',7,$v,
      "approval-$v",$v,hash('sha256',$state->bytes));
};
$checks=0;
$ok=static function(bool $value,string $label)use(&$checks):void {
    if (!$value) throw new RuntimeException('LANDING CUSTODY FIXTURE FAILURE: '.$label);
    ++$checks;
};
$deny=static function(callable $fn,string $label)use($ok):void {
    ob_start();$denied=false;
    try {$fn();} catch (RuntimeException $e) {
        $denied=$e->getMessage()==='SITES_LANDING_PRIVATE_CUSTODY_DENIED' && $e->getPrevious()===null;
    }
    $output=ob_get_clean();
    $ok($denied && $output==='', $label);
};
$count=static fn():int => (int)$admin->query(
    'SELECT count(*) FROM sites_landing_custody.pending_artifacts'
)->fetchColumn();
$ok($dbA->query('SELECT current_user')->fetchColumn()==='sites_landing_ci_a','non-owner A role');
$ok($dbB->query('SELECT current_user')->fetchColumn()==='sites_landing_ci_b','non-owner B role');
$ok($count()===0,'empty starting custody');
$first=$run($a,'operation-A1');
$ok($first['state']==='pending_private_only' && strlen($first['reviewSha256'])===64,
  'A stores pending private pointer, not approval');
$ok($count()===1,'one durable private pending artifact');
$ok($admin->query("SELECT recorded_by FROM sites_landing_custody.pending_artifacts WHERE tenant_ref='tenant-A'")->fetchColumn()==='sites_landing_ci_a',
  'immutable write audit captures authenticated database login');
$stored=$admin->query('SELECT artifact_bytes,review_bytes,artifact_sha256,review_sha256 FROM sites_landing_custody.pending_artifacts')->fetch(PDO::FETCH_ASSOC);
$ok(hash_equals(hash('sha256',$stored['artifact_bytes']),$stored['artifact_sha256']),
    'stored bytes cryptographically tied to artifact digest');
$ok($stored['review_sha256']===$first['reviewSha256'],
    'stored exact review pointer hash');
$ok(str_contains($stored['artifact_bytes'],'&lt;script&gt;') &&
    !str_contains($stored['artifact_bytes'],'<script>'),
    'stored HTML is inert and escaped');
$ok($run($a,'operation-A1')===$first && $count()===1,
    'idempotent exact replay does not add a row');
$deny(static fn()=>$run($a,'operation-A2'),'same source version cannot fork with new operation');
$deny(static fn()=>$run($a,'operation-A1','tenant-B'),'A role cannot stage B despite synthetic admission');
$ok($count()===1,'negative attempts do not add rows');
$deniedRead=false;
try {$dbA->query('SELECT * FROM sites_landing_custody.pending_artifacts');}
catch(PDOException) {$deniedRead=true;}
$ok($deniedRead,'role A has no direct SELECT of private artifacts');
$deniedRead=false;
try {$dbUnbound->query('SELECT * FROM sites_landing_custody.pending_artifacts');}
catch(PDOException) {$deniedRead=true;}
$ok($deniedRead,'unbound role has no direct SELECT');
$deny(static fn()=>$run($b,'operation-B-mismatch','tenant-A'),
    'B role cannot use A tenant admission');
$ok($run($b,'operation-B1','tenant-B')['state']==='pending_private_only',
    'separate B tenant can store under its own authenticated role');
$ok($count()===2,'same site UUID kept tenant-keyed without collisions');
$state->version=2;
$deny(static fn()=>$run($a,'operation-A-old','tenant-A',1),
    'stale prior source version refused after supersession');
$ok($run($a,'operation-A-v2')['state']==='pending_private_only' && $count()===3,
    'new approved revision stored as immutable independent entry');
$packet=$draft->prepareForInternalReview(
    'tenant-A','order-A',$site,'contract-A',7,2,'approval-2',2,hash('sha256',$state->bytes)
);
$sql='SELECT sites_landing_custody.record_pending(?,?,?,?)';
$direct=static function(string $op,string $artifact,string $review,string $sha)use($dbA,$sql):void {
    $stmt=$dbA->prepare($sql);$stmt->execute([$op,$artifact,$review,$sha]);$stmt->fetchColumn();
};
$directDenied=static function(callable $fn,string $label)use($ok):void {
    $denied=false;try{$fn();}catch(PDOException){$denied=true;}
    $ok($denied,$label);
};
$directDenied(static fn()=>$direct('operation-A-v2',$packet['artifactBytes'].'tamper',
    $packet['reviewBytes'],$packet['reviewSha256']),
    'mutated artifact bytes refused by database even with prior operation');
$directDenied(static fn()=>$direct('operation-A-v2',$packet['artifactBytes'],
    str_replace('"canPublish":false','"canPublish":true',$packet['reviewBytes']),$packet['reviewSha256']),
    'forged publication authority refused');
$directDenied(static fn()=>$direct('operation-A-v2',$packet['artifactBytes'],
    $packet['reviewBytes'],str_repeat('0',64)),
    'false pointer digest refused');
$directDenied(static fn()=>$direct('operation-A-v2',$packet['artifactBytes'],
    str_replace('"tenantRef":"tenant-A"','"tenantRef":"tenant-B"',$packet['reviewBytes']),$packet['reviewSha256']),
    'tenant selector falsification refused');
$directDenied(static fn()=>$direct('operation-A-v2',$packet['artifactBytes'],
    str_replace('"sourceVersion":2','"sourceVersion":2.0',$packet['reviewBytes']),$packet['reviewSha256']),
    'noncanonical integer refused');
$directDenied(static fn()=>$direct('operation-A-v2',$packet['artifactBytes'],
    str_replace('"clientAccepted":false','"clientAccepted":true',$packet['reviewBytes']),$packet['reviewSha256']),
    'client acceptance flag falsification refused');
$directDenied(static fn()=>$direct('operation-A-v2',$packet['artifactBytes'],
    substr_replace($packet['reviewBytes'],'"state":"approved",',1,0),$packet['reviewSha256']),
    'duplicate or extra approval field refused');
// Database-level monotonicity: an attacker can bypass a PHP candidate
// and submit a fully valid old packet after a newer version has been stored.
$state->version=1;
$latePacket=$draft->prepareForInternalReview(
    'tenant-A','order-A',$site,'contract-A',7,1,'approval-1',1,
    hash('sha256',$state->bytes)
);
$state->version=2;
$directDenied(static fn()=>$direct('operation-A-late-v1',
    $latePacket['artifactBytes'],$latePacket['reviewBytes'],$latePacket['reviewSha256']),
    'SQL refuses valid but out-of-order new version, not just PHP readback');
$direct('operation-A1',$latePacket['artifactBytes'],
    $latePacket['reviewBytes'],$latePacket['reviewSha256']);
$ok($count()===3,'exact historical replay remains idempotent without new row');
$ok($count()===3,'no attacker row persisted');
$state->version=3;
$state->calls=0;$state->cutoff=5;
$deny(static fn()=>$run($a,'operation-A-drift'),
    'Portal revocation during post-write readback rolls back entire transaction');
$state->cutoff=null;$state->calls=0;
$ok($count()===3 && !$dbA->inTransaction(),
    'denied post-write revalidation leaves no durable row and closes transaction');
$state->revoked=true;
$deny(static fn()=>$run($a,'operation-A-revoked'),'revoked head denied before write');
$state->revoked=false;
$admin->exec("UPDATE sites_landing_custody.admitted_sites SET active=false WHERE tenant_ref='tenant-A'");
$deny(static fn()=>$run($a,'operation-A-disabled'),'independent site admission revoked');
$ok($count()===3,'site revocation added no rows');
$deniedMutation=false;
try {$admin->exec("UPDATE sites_landing_custody.pending_artifacts SET operation_ref='tamper' WHERE tenant_ref='tenant-A'");}
catch(PDOException){$deniedMutation=true;}
$ok($deniedMutation,'append-only custody trigger forbids changes');
$ok(!$dbA->inTransaction()&&!$dbB->inTransaction(),
    'all app transactions closed');
echo "SITES LANDING PG18 PRIVATE CUSTODY PASS: $checks checks; synthetic admissions only.\n";
