<?php
declare(strict_types=1);

// Synthetic independent providers, but real pinned Portal encoder and Sites
// current-admission / private-brief parsers. No network, database or authority.
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
] as $class) {
    require_once __DIR__.'/../apps/sites/app/Integration/'.$class.'.php';
}
require_once __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/LandingPagePrivateBriefEnvelopeCandidate.php';

use App\Integration\CurrentPortalLandingPageAdmissionSource;
use App\Integration\CurrentPortalLandingPageApprovedBriefSource;
use App\Integration\PortalLandingPageAdmissionReadbackCandidate as AdmissionReadback;
use App\Integration\PortalLandingPageApprovedBriefReadbackCandidate as Readback;
use App\Domain\SitesIntegration\ApprovedLandingPageBriefHead as PortalHead;
use App\Domain\SitesIntegration\LandingPagePrivateBriefEnvelopeCandidate as PortalEncoder;

$tenant='tenant-A'; $order='order-A';
$site='11111111-1111-4111-8111-111111111111';
$contract='contract-A'; $approval='approval-A';
$bytes='{"headline":"Teste privado de Landing","cta":"Contato"}';
$sha=hash('sha256',$bytes);
$head=static function(?string $content=null,int $version=3,int $epoch=2,
    ?string $t=null,?string $s=null)
    use ($bytes,$tenant,$order,$site,$contract,$approval):string {
    $body=$content ?? $bytes;
    return PortalEncoder::encode(new PortalHead(
        $t ?? $tenant,$order,$s ?? $site,$contract,7,$version,
        hash('sha256',$body),$approval,$epoch,$body,
    ));
};
$validWire=$head();
$projection=json_encode([
    'tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
    'contractRef'=>$contract,'contractRevision'=>7,'productCode'=>'landing_page',
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$admissionSource=new class($projection) implements CurrentPortalLandingPageAdmissionSource {
    public int $calls=0; public ?Closure $beforeRead=null;
    public function __construct(public ?string $wire) {}
    public function readAdmission(string $t,string $o,string $s):?string {
        ++$this->calls;
        if ($this->beforeRead !== null) ($this->beforeRead)($this->calls);
        return $this->wire;
    }
};
$headSource=new class($validWire) implements CurrentPortalLandingPageApprovedBriefSource {
    public int $calls=0; public ?Closure $beforeRead=null;
    public function __construct(public ?string $wire) {}
    public function readCurrent(string $t,string $o,string $s):?string {
        ++$this->calls;
        if ($this->beforeRead !== null) ($this->beforeRead)($this->calls);
        return $this->wire;
    }
};
$gate=new Readback($headSource,new AdmissionReadback($admissionSource));
$checks=0;
$ok=static function(bool $pass,string $label) use (&$checks):void {
    if (!$pass) throw new RuntimeException('LANDING CURRENT READBACK FAILED: '.$label);
    ++$checks;
};
$deny=static function(callable $f,string $label) use ($ok):void {
    ob_start(); $rejected=false;
    try { $f(); } catch (RuntimeException $e) {
        $rejected=$e->getMessage()==='SITES_LANDING_APPROVAL_READBACK_DENIED'
            && $e->getPrevious()===null;
    }
    $output=ob_get_clean();
    $ok($rejected && $output==='',$label.' failed closed without leaking selectors');
};
$read=static function(?string $t=null,?string $o=null,?string $s=null,
    ?string $c=null,int $contractRevision=7,int $version=3,
    ?string $a=null,int $epoch=2,?string $digest=null)
    use ($gate,$tenant,$order,$site,$contract,$approval,$sha) {
    return $gate->assertCurrent($t ?? $tenant,$o ?? $order,$s ?? $site,
        $c ?? $contract,$contractRevision,$version,$a ?? $approval,$epoch,
        $digest ?? $sha);
};
$reset=static function() use ($admissionSource,$headSource,$projection,$validWire):void {
    $admissionSource->calls=0; $admissionSource->beforeRead=null; $admissionSource->wire=$projection;
    $headSource->calls=0; $headSource->beforeRead=null; $headSource->wire=$validWire;
};

$actual=$read();
$ok($actual->sourceBytes()===$bytes && $actual->sourceSha256===$sha,
    'original private bytes and independent digest match pinned Portal encoder');
$ok($headSource->calls===2 && $admissionSource->calls===4,
    'two head reads bracketed by two independent double custody reads');
$ok(!property_exists($actual,'canPublish') &&
    !property_exists($actual,'clientAccepted') &&
    !property_exists($actual,'paymentAuthorized'),
    'readback never grants publication, acceptance or payment');
try { json_encode($actual,JSON_THROW_ON_ERROR); $serialized=true; }
catch (Throwable) { $serialized=false; }
$ok(!$serialized,'private source cannot be serialized to JSON');
$ok(!str_contains(print_r($actual,true),'Teste privado de Landing'),
    'debug output never leaks private briefing');
$reset();
$deny(static fn()=>(new Readback())->assertCurrent(
    $tenant,$order,$site,$contract,7,3,$approval,2,$sha),
    'both default sources deny');
$reset();
$deny(static fn()=>(new Readback($headSource))->assertCurrent(
    $tenant,$order,$site,$contract,7,3,$approval,2,$sha),
    'admission default denies even with head source');
$ok($headSource->calls===0,'untrusted admission cannot query private head');
$reset();
$deny(static fn()=>(new Readback(
    new \App\Integration\DenyingCurrentPortalLandingPageApprovedBriefSource(),
    new AdmissionReadback($admissionSource)
))->assertCurrent($tenant,$order,$site,$contract,7,3,$approval,2,$sha),
    'private head default denies after admitted custody');
$reset();
foreach ([
    ['tenant selector','tenant*unsafe',$order,$site],
    ['order selector',$tenant,'order*unsafe',$site],
    ['site selector',$tenant,$order,'NOT-UUID'],
] as [$label,$t,$o,$s]) {
    $deny(static fn()=>$read($t,$o,$s),$label);
    $ok($admissionSource->calls===0 && $headSource->calls===0,
        $label.' invalid prior to reads');
}
foreach ([
    ['contract revision',0,3,2],
    ['content version',7,0,2],
    ['approval epoch',7,3,0],
] as [$label,$revision,$version,$epoch]) {
    $reset();
    $deny(static fn()=>$read($tenant,$order,$site,$contract,$revision,$version,
        $approval,$epoch,$sha),$label);
    $ok($admissionSource->calls===0 && $headSource->calls===0,
        $label.' invalid prior to reads');
}
$reset();
$deny(static fn()=>$read($tenant,$order,$site,$contract,7,3,$approval,2,
    str_repeat('f',64)),'independently expected SHA mismatch');
$ok($headSource->calls===1,'wrong expected SHA denies before second head read');
$reset();
$deny(static fn()=>$read($tenant,$order,$site,$contract,7,3,$approval,2,'NOT-HASH'),
    'invalid independently expected SHA');
$ok($headSource->calls===0 && $admissionSource->calls===0,'invalid SHA before IO');
$reset(); $headSource->wire=null;
$deny($read,'missing current approved brief');
$reset(); $headSource->wire='{';
$deny($read,'malformed current approved brief');
$reset(); $headSource->wire=$head($bytes,4);
$deny($read,'source version superseded');
$reset(); $headSource->wire=$head($bytes,3,3);
$deny($read,'approval epoch revoked');
$reset(); $headSource->wire=$head($bytes,3,2,'tenant-B');
$deny($read,'foreign tenant despite valid head');
$reset(); $headSource->wire=$head($bytes,3,2,$tenant,'22222222-2222-4222-8222-222222222222');
$deny($read,'foreign site despite valid head');
$reset(); $headSource->wire=str_replace('"productCode":"landing_page"',
    '"productCode":"onepage"',$validWire);
$deny($read,'Onepage never admitted as Landing');
$reset(); $headSource->wire=substr($validWire,0,-1).',"canPublish":true}';
$deny($read,'forged publish permission');
$reset(); $headSource->wire=substr($validWire,0,-1).',"clientAccepted":true}';
$deny($read,'forged client acceptance');
$reset(); $headSource->beforeRead=static function(int $calls) use ($headSource,$head):void {
    if ($calls===2) $headSource->wire=$head('{"headline":"Nova revisão","cta":"Contato"}');
};
$deny($read,'briefing changed between head reads');
$ok($headSource->calls===2 && $admissionSource->calls===2,
    'changed head rejects without final custody acceptance');
$reset(); $headSource->beforeRead=static function(int $calls) use ($headSource):void {
    if ($calls===2) $headSource->wire=null;
};
$deny($read,'approval missing on second head read');
$reset(); $admissionSource->beforeRead=static function(int $calls) use ($admissionSource):void {
    if ($calls===3) $admissionSource->wire=null;
};
$deny($read,'custody revoked after both head reads');
$ok($headSource->calls===2 && $admissionSource->calls===3,
    'late revocation denies final custody');
$reset(); $admissionSource->beforeRead=static function(int $calls):void {
    if ($calls===4) throw new RuntimeException('PRIVATE_CUSTODY_SECRET');
};
$deny($read,'provider exception redacted');
$reset();
$ok($read()->sourceBytes()===$bytes,'valid readback after all negative attempts');
$ok($headSource->calls===2 && $admissionSource->calls===4,
    'replay always rereads current sources; no stale cache');
echo 'SITES LANDING PRIVATE CURRENT READBACK PASS: '.$checks.
    " checks; laboratory only, non-atomic, not a Portal approval or publishing gate.\n";
