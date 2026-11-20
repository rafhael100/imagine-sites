<?php

declare(strict_types=1);

// Cross-repository, exact-SHA laboratory contract for the *request* half of
// the future private Portal Onepage current-head route. No HTTP, mTLS peer,
// approved briefing, tenant authority, money, review decision or publication.
require_once __DIR__.'/../src/Production/BriefCandidateJson.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalOnepageHeadRequestCandidate.php';
require_once __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/OnepageCurrentHeadS2sRequestCandidate.php';

use App\Integration\PortalOnepageHeadRequestCandidate as SitesRequest;
use App\Domain\SitesIntegration\OnepageCurrentHeadS2sRequestCandidate as PortalRequest;

$checks = 0;
$ok = static function (bool $valid, string $label) use (&$checks): void {
    if (!$valid) throw new RuntimeException('SITES PORTAL REQUEST PAIR FAILED: '.$label);
    ++$checks;
};
$deny = static function(callable $action, string $message, string $expected) use ($ok): void {
    ob_start();
    $failed = false;
    try {
        $action();
    } catch (RuntimeException $error) {
        $failed = $error->getMessage() === $expected && $error->getPrevious() === null;
    }
    $stdout = ob_get_clean();
    $ok($failed && $stdout === '', $message.' must fail closed');
};

$site = '11111111-1111-4111-8111-111111111111';
$tenant = 'tenant-lab-A';
$order = 'order-lab-A';
$expected = [
    'tenantRef' => $tenant,
    'orderRef' => $order,
    'siteRef' => $site,
];
$wire = SitesRequest::encode($tenant,$order,$site);
$ok($wire === json_encode([
    'schemaVersion'=>'sites.onepage-current-head.fetch.v1',
    'tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
    'productCode'=>'onepage',
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES), 'Sites emits exact canonical five-field request');
$ok(PortalRequest::decode($wire) === $expected, 'Portal independently parses the exact Sites bytes');
$ok(PortalRequest::decode(SitesRequest::encode($tenant,$order,$site)) === $expected,
    'deterministic request replay contains no hidden service identity');
$ok(!str_contains($wire, 'authenticatedServiceRef') &&
    !str_contains($wire, 'approval') && !str_contains($wire, 'canPublish'),
    'wire carries neither verified identity nor fabricated authority');

foreach ([
    ['bad tenant', $order, $site],
    [$tenant, "order/invalid", $site],
    [$tenant, $order, 'INVALID'],
    [$tenant, $order, strtoupper('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')],
] as [$badTenant,$badOrder,$badSite]) {
    $deny(
        static fn() => SitesRequest::encode($badTenant,$badOrder,$badSite),
        'Sites rejects malformed selector',
        'ONEPAGE_PORTAL_HEAD_REQUEST_DENIED',
    );
}
foreach ([
    str_replace('"productCode":"onepage"', '"productCode":"landing_page"', $wire),
    str_replace('"schemaVersion":"sites.onepage-current-head.fetch.v1"',
        '"schemaVersion":"sites.onepage-current-head.fetch.v2"', $wire),
    substr($wire,0,-1).',"canPublish":true}',
    substr($wire,0,-1).',"tenantRef":"tenant-foreign"}',
    $wire."\n",
] as $tampered) {
    $deny(
        static fn() => PortalRequest::decode($tampered),
        'Portal rejects altered or authority-injected Sites body',
        'PORTAL_ONEPAGE_CURRENT_HEAD_REQUEST_DENIED',
    );
}

// Format parsing is not tenant authorization. The other tenant's *canonical*
// request parses as another selector; the future authenticated service MUST
// compare it against the independently scoped identity before any source read.
$other = PortalRequest::decode(SitesRequest::encode('tenant-lab-B',$order,$site));
$ok($other['tenantRef'] !== $expected['tenantRef'],
    'foreign tenant is distinguishable but never authorized by this parser');
$ok(PortalRequest::decode($wire) === $expected,
    'valid request remains unchanged after all adversarial cases');

echo 'SITES PORTAL ONEPAGE REQUEST PARITY PASS: '.$checks.
    " checks; canonical request bytes only, no official Portal read or mTLS exchange.\n";
