<?php

declare(strict_types=1);

// Contract-only Landing Page selector. No approved Landing Page source,
// Portal receiver, service peer, payment, public endpoint or publishing.
require_once __DIR__.'/../src/Production/BriefCandidateJson.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalOnepageHeadRequestCandidate.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalLandingPageHeadRequestCandidate.php';
require_once __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/OnepageCurrentHeadS2sRequestCandidate.php';

use App\Integration\PortalOnepageHeadRequestCandidate as OnepageRequest;
use App\Integration\PortalLandingPageHeadRequestCandidate as LandingRequest;
use App\Domain\SitesIntegration\OnepageCurrentHeadS2sRequestCandidate as PortalOnepageParser;

$checks = 0;
$ok = static function(bool $value, string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('SITES LANDING HEAD REQUEST FAILURE: '.$label);
    ++$checks;
};
$reject = static function(callable $operation, string $message, string $expected) use ($ok): void {
    ob_start();
    $failed = false;
    try { $operation(); }
    catch (RuntimeException $error) {
        $failed = $error->getMessage() === $expected && $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $ok($failed && $output === '', $message.' denies without disclosure');
};
$tenant = 'tenant-landing-lab-A';
$order = 'order-landing-lab-A';
$site = '11111111-1111-4111-8111-111111111111';
$wire = LandingRequest::encode($tenant, $order, $site);
$expected = json_encode([
    'schemaVersion' => 'sites.landing-page-current-head.fetch.v1',
    'tenantRef' => $tenant,
    'orderRef' => $order,
    'siteRef' => $site,
    'productCode' => 'landing_page',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$ok($wire === $expected && strlen($wire) <= 1024, 'exact five-field Landing Page request');
$ok(hash_equals(hash('sha256',$wire),hash('sha256',LandingRequest::encode($tenant,$order,$site))),
    'replay is byte-identical, never an approval claim');
$ok(!str_contains($wire, 'authenticatedServiceRef') &&
    !str_contains($wire,'canPublish') && !str_contains($wire,'approval'),
    'wire contains no service identity or authority fields');
$onepage = OnepageRequest::encode($tenant,$order,$site);
$ok($wire !== $onepage, 'Landing Page and Onepage have distinct envelopes');
$ok(json_decode($wire, true, 8, JSON_THROW_ON_ERROR)['productCode'] === 'landing_page',
    'Landing Page never masquerades as Onepage');
$reject(static fn() => PortalOnepageParser::decode($wire),
    'actual Portal Onepage decoder rejects Landing Page',
    'PORTAL_ONEPAGE_CURRENT_HEAD_REQUEST_DENIED');
$ok(PortalOnepageParser::decode($onepage) === [
    'tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
], 'existing Onepage parser remains untouched');
foreach ([
    ['invalid tenant', 'tenant invalid', $order, $site],
    ['invalid order', $tenant, 'order/invalid', $site],
    ['invalid site', $tenant, $order, 'not-a-uuid'],
    ['upper-case site', $tenant, $order, 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'],
    ['empty tenant', '', $order, $site],
    ['empty order', $tenant, '', $site],
    ['overlong tenant', str_repeat('t', 129), $order, $site],
] as [$label,$candidateTenant,$candidateOrder,$candidateSite]) {
    $reject(static fn()=>LandingRequest::encode($candidateTenant,$candidateOrder,$candidateSite),
        $label,'LANDING_PAGE_PORTAL_HEAD_REQUEST_DENIED');
}
$ok(LandingRequest::encode($tenant,$order,$site)===$wire,
    'all denials preserve deterministic valid request');

echo 'SITES LANDING HEAD REQUEST PASS: '.$checks.
    " checks; dedicated product envelope only, no Portal Landing Page authority.\n";
