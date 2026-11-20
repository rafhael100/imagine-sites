<?php
declare(strict_types=1);

// Offline parity with real pinned Portal admission candidate (not a live service).
require_once __DIR__.'/../src/Production/BriefCandidateJson.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalLandingPageHeadRequestCandidate.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalOnepageHeadRequestCandidate.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalLandingPageAdmissionProjectionCandidate.php';
foreach ([
    'SiteContractSnapshot', 'PortalSiteContractSource', 'DenyingPortalSiteContractSource',
    'PortalLandingPageHeadServiceWitness','DenyingPortalLandingPageHeadServiceWitness',
    'LandingPageCurrentHeadS2sRequestCandidate','LandingPageCurrentHeadAdmissionCandidate',
] as $class) {
    require_once __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/'.$class.'.php';
}
use App\Integration\PortalLandingPageHeadRequestCandidate as LandingRequest;
use App\Integration\PortalOnepageHeadRequestCandidate as OnepageRequest;
use App\Integration\PortalLandingPageAdmissionProjectionCandidate as Projection;
use App\Domain\SitesIntegration\PortalLandingPageHeadServiceWitness;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\LandingPageCurrentHeadAdmissionCandidate as PortalAdmission;

$checks=0;
$ok = static function(bool $valid,string $label) use (&$checks): void {
    if (!$valid) throw new RuntimeException('LANDING PROJECTION FAILURE: '.$label);
    ++$checks;
};
$deny = static function(callable $action,string $label) use ($ok): void {
    ob_start(); $rejected=false;
    try { $action(); }
    catch (RuntimeException $e) {
        $rejected=$e->getMessage()==='SITES_LANDING_PAGE_ADMISSION_PROJECTION_DENIED'
            && $e->getPrevious()===null;
    }
    $output=ob_get_clean();
    $ok($rejected && $output==='',$label.' redacted');
};
$tenant='tenant-landing-A';$order='order-landing-A';
$site='11111111-1111-4111-8111-111111111111';
$peer = new class implements PortalLandingPageHeadServiceWitness {
    public int $calls=0;
    public function permits(string $service,string $tenant,string $order,string $site):bool {
        ++$this->calls;
        return $service==='verified-synthetic-peer' && $tenant==='tenant-landing-A' &&
            $order==='order-landing-A' && $site==='11111111-1111-4111-8111-111111111111';
    }
};
$contracts = new class implements PortalSiteContractSource {
    public int $calls=0;
    public string $product='landing_page';
    public function findAuthorizedSiteOrder(string $order):?SiteContractSnapshot {
        ++$this->calls;
        return new SiteContractSnapshot(
            'tenant-landing-A','order-landing-A','contract-landing-A',7,$this->product);
    }
};
$portal = new PortalAdmission($peer,$contracts);
$portalRequest=LandingRequest::encode($tenant,$order,$site);
$projection=$portal->inspect($portalRequest,'verified-synthetic-peer');
$bytes=json_encode($projection,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$ok(Projection::decode($bytes,$tenant,$order,$site)===$projection,
    'real Portal projection matches actual Sites response parser');
$ok($peer->calls===2 && $contracts->calls===2,
    'Portal admission rereads independent service and order');
$ok($projection['productCode']==='landing_page' &&
    $projection['contractRevision']===7,
    'response retains correct product and exact contract revision');
$ok(!array_intersect(['approvalRef','sourceBytes','canPublish','clientAccepted',
    'paymentAuthorized','authorityVerified','canAcceptRealOrders'],array_keys($projection)),
    'no authority or private briefing content in projection');
$ok(Projection::decode($bytes,$tenant,$order,$site)===
    Projection::decode($bytes,$tenant,$order,$site),'read-only idempotent replay');

foreach ([
    ['wrong expected tenant',$bytes,'tenant-landing-B',$order,$site],
    ['wrong expected order',$bytes,$tenant,'order-landing-B',$site],
    ['wrong expected site',$bytes,$tenant,$order,'22222222-2222-4222-8222-222222222222'],
    ['invalid expected site',$bytes,$tenant,$order,'NOT-UUID'],
    ['wrong product',str_replace('"productCode":"landing_page"','"productCode":"onepage"',$bytes),$tenant,$order,$site],
    ['tampered contract',str_replace('contract-landing-A','contract-landing-B',$bytes),$tenant,$order,$site],
    ['wrong tenant in response',str_replace($tenant,'tenant-landing-B',$bytes),$tenant,$order,$site],
    ['extra publishing grant',substr($bytes,0,-1).',"canPublish":true}',$tenant,$order,$site],
    ['extra acceptance',substr($bytes,0,-1).',"clientAccepted":true}',$tenant,$order,$site],
    ['duplicate revision',substr($bytes,0,-1).',"contractRevision":8}',$tenant,$order,$site],
    ['non integer revision',str_replace('"contractRevision":7','"contractRevision":"7"',$bytes),$tenant,$order,$site],
    ['floating revision',str_replace('"contractRevision":7','"contractRevision":7.0',$bytes),$tenant,$order,$site],
    ['zero revision',str_replace('"contractRevision":7','"contractRevision":0',$bytes),$tenant,$order,$site],
    ['reordered JSON','{"productCode":"landing_page","contractRevision":7,"contractRef":"contract-landing-A","siteRef":"'.$site.'","orderRef":"'.$order.'","tenantRef":"'.$tenant.'"}',$tenant,$order,$site],
    ['trailing whitespace',$bytes."\n",$tenant,$order,$site],
    ['invalid JSON','{',$tenant,$order,$site],
    ['oversized',$bytes.str_repeat(' ',2100),$tenant,$order,$site],
] as [$label,$payload,$wantTenant,$wantOrder,$wantSite]) {
    $deny(static fn()=>Projection::decode($payload,$wantTenant,$wantOrder,$wantSite),$label);
}
$ok($peer->calls===2 && $contracts->calls===2,
    'response parsing never makes additional Portal authority reads');
$contracts->product='onepage';
$portalRejected=false;
try {
    $portal->inspect($portalRequest,'verified-synthetic-peer');
} catch (RuntimeException $error) {
    $portalRejected=$error->getMessage()==='PORTAL_LANDING_PAGE_HEAD_ADMISSION_DENIED';
}
$ok($portalRejected,'real Portal rejects a Onepage contract for Landing request');
$portalRejected=false;
try {
    $portal->inspect(OnepageRequest::encode($tenant,$order,$site),'verified-synthetic-peer');
} catch (RuntimeException $error) {
    $portalRejected=$error->getMessage()==='PORTAL_LANDING_PAGE_HEAD_ADMISSION_DENIED';
}
$ok($portalRejected,'real Portal rejects Onepage wire format for Landing');
$ok(Projection::decode($bytes,$tenant,$order,$site)===$projection,
    'negative probes do not mutate deterministic projection');
echo 'SITES LANDING ADMISSION PARITY PASS: '.$checks.
    " checks; synthetic admission only, NO official head, mTLS route, approval, release or payment.\n";
