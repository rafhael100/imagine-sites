<?php
declare(strict_types=1);

// Pair test against immutable Portal #1010 (not an operational service).
require_once __DIR__.'/../src/Production/BriefCandidateJson.php';
foreach ([
    'PortalLandingPageAdmissionProjectionCandidate',
    'CurrentPortalLandingPageAdmissionSource',
    'DenyingCurrentPortalLandingPageAdmissionSource',
    'PortalLandingPageAdmissionReadbackCandidate',
    'PortalLandingPageHeadRequestCandidate',
] as $class) require_once __DIR__.'/../apps/sites/app/Integration/'.$class.'.php';
foreach ([
    'SiteContractSnapshot','PortalSiteContractSource','DenyingPortalSiteContractSource',
    'PortalLandingPageHeadServiceWitness','DenyingPortalLandingPageHeadServiceWitness',
    'LandingPageSiteOrderBinding','PortalLandingPageSiteOrderBindingSource',
    'DenyingPortalLandingPageSiteOrderBindingSource',
    'LandingPageCurrentHeadS2sRequestCandidate',
    'LandingPageCurrentHeadAdmissionCandidate',
] as $class) require_once __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/'.$class.'.php';

use App\Integration\CurrentPortalLandingPageAdmissionSource;
use App\Integration\PortalLandingPageAdmissionReadbackCandidate as Readback;
use App\Integration\PortalLandingPageHeadRequestCandidate as Request;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\PortalLandingPageHeadServiceWitness;
use App\Domain\SitesIntegration\PortalLandingPageSiteOrderBindingSource;
use App\Domain\SitesIntegration\LandingPageSiteOrderBinding;
use App\Domain\SitesIntegration\LandingPageCurrentHeadAdmissionCandidate as PortalAdmission;

$tenant='tenant-landing-A';$order='order-landing-A';
$site='11111111-1111-4111-8111-111111111111';
$peer=new class implements PortalLandingPageHeadServiceWitness {
    public bool $allowed=true; public int $calls=0;
    public function permits(string $service,string $tenant,string $order,string $site): bool {
        ++$this->calls;
        return $this->allowed && $service==='verified-synthetic-peer' &&
            $tenant==='tenant-landing-A' && $order==='order-landing-A' &&
            $site==='11111111-1111-4111-8111-111111111111';
    }
};
$contract=new class implements PortalSiteContractSource {
    public int $calls=0; public int $revision=7; public string $product='landing_page';
    public function findAuthorizedSiteOrder(string $order): ?SiteContractSnapshot {
        ++$this->calls;
        return new SiteContractSnapshot(
            'tenant-landing-A','order-landing-A','contract-landing-A',
            $this->revision,$this->product);
    }
};
$binding=new class implements PortalLandingPageSiteOrderBindingSource {
    public int $calls=0; public int $revision=7;
    public bool $present=true;
    public string $site='11111111-1111-4111-8111-111111111111';
    public function readCurrentBinding(string $tenant,string $order,string $site): ?LandingPageSiteOrderBinding {
        ++$this->calls;
        return $this->present ? new LandingPageSiteOrderBinding(
            'tenant-landing-A','order-landing-A',$this->site,
            'contract-landing-A',$this->revision,'landing_page') : null;
    }
};
$portal=new PortalAdmission($peer,$contract,$binding);
$wire=Request::encode($tenant,$order,$site);
$source=new class($portal,$wire) implements CurrentPortalLandingPageAdmissionSource {
    public int $calls=0; public ?Closure $onSecond=null;
    public function __construct(private PortalAdmission $portal, private string $wire) {}
    public function readAdmission(string $tenant,string $order,string $site): ?string {
        ++$this->calls;
        if ($this->calls===2 && $this->onSecond!==null) ($this->onSecond)();
        return json_encode($this->portal->inspect($this->wire,'verified-synthetic-peer'),
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
};
$gate=new Readback($source);
$checks=0;
$ok=static function(bool $condition,string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('SITES LANDING READBACK FAILURE: '.$message);
    ++$checks;
};
$deny=static function(callable $action,string $label) use ($ok):void {
    ob_start(); $rejected=false;
    try { $action(); }
    catch (RuntimeException $e) {
        $rejected=$e->getMessage()==='SITES_LANDING_PAGE_ADMISSION_READBACK_DENIED' &&
            $e->getPrevious()===null;
    }
    $output=ob_get_clean();
    $ok($rejected && $output==='',$label.' redacted deny');
};
$reset=static function() use ($source,$peer,$contract,$binding):void {
    $source->calls=0; $source->onSecond=null;
    $peer->calls=0; $peer->allowed=true;
    $contract->calls=0; $contract->revision=7; $contract->product='landing_page';
    $binding->calls=0; $binding->revision=7;
    $binding->present=true; $binding->site='11111111-1111-4111-8111-111111111111';
};
$expected=['tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
    'contractRef'=>'contract-landing-A','contractRevision'=>7,'productCode'=>'landing_page'];
$ok($gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7)===$expected,
    'two Portal current admission projections agree on expected contract');
$ok($source->calls===2 && $peer->calls===4 && $contract->calls===4 && $binding->calls===4,
    'each Site read fully revisits Portal service, order and site custody');
$ok(!array_intersect(['canPublish','canAcceptRealOrders','authorityVerified',
    'paymentAuthorized','clientAccepted','approvalRef','sourceBytes'],array_keys($expected)),
    'projection contains no client approval, publishing or payment authority');
$reset();
$deny(static fn()=>(new Readback())->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
    'default denied source');
$reset();
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-B',7),
    'different independently expected contract');
$reset();
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',8),
    'different expected revision');
foreach ([
    ['invalid tenant','tenant*bad',$order,$site],
    ['invalid order',$tenant,'order*bad',$site],
    ['invalid site',$tenant,$order,'NOT-UUID'],
] as [$label,$t,$o,$s]) {
    $reset();
    $deny(static fn()=>$gate->assertConsistent($t,$o,$s,'contract-landing-A',7),$label);
    $ok($source->calls===0,'invalid selector never contacts Portal');
}
$reset();
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',0),
    'zero contract revision');
$ok($source->calls===0,'invalid revision never contacts Portal');
$reset(); $binding->site='22222222-2222-4222-8222-222222222222';
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
    'foreign site binding despite valid order');
$ok($source->calls===1 && $binding->calls===1,'wrong site denied in Portal first read');
$reset(); $binding->present=false;
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
    'missing independent site binding');
$reset(); $peer->allowed=false;
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
    'service revoked before first read');
$ok($contract->calls===0 && $binding->calls===0,'untrusted peer does not reach private custody');
$reset(); $source->onSecond=static function() use ($binding):void {$binding->present=false;};
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
    'site binding revoked between Portal reads');
$ok($source->calls===2,'new Site read required after Portal revocation');
$reset(); $source->onSecond=static function() use ($contract,$binding):void {
    $contract->revision=8; $binding->revision=8;
};
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
    'contract and binding advance between reads');
$reset(); $source->onSecond=static function() use ($peer):void {$peer->allowed=false;};
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
    'service revoked between reads');
$reset(); $contract->product='onepage';
$deny(static fn()=>$gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
    'no Onepage cross-product fallback');
$reset();
$badSource=new class implements CurrentPortalLandingPageAdmissionSource {
    public string $payload='{';
    public function readAdmission(string $tenant,string $order,string $site): ?string {
        return $this->payload;
    }
};
$badGate=new Readback($badSource);
foreach (['{', 'null', '', str_repeat('x',4097)] as $bad) {
    $badSource->payload=$bad;
    $deny(static fn()=>$badGate->assertConsistent($tenant,$order,$site,'contract-landing-A',7),
        'malformed or overlimit source');
}
$reset();
$ok($gate->assertConsistent($tenant,$order,$site,'contract-landing-A',7)===$expected,
    'success after denials requires independent new custody reads');
$ok($source->calls===2 && $binding->calls===4,'idempotent success never skips revalidation');
echo 'SITES LANDING BOUND READBACK PASS: '.$checks.
    " checks; synthetic custody, no official approved briefing, transport or publication.\n";
