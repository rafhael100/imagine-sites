<?php

declare(strict_types=1);

use Imagine\Sites\Production\OnepageReviewHandoffCandidate;
use Imagine\Sites\Production\LocalSiteRendering;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $name) use ($root): void {
    $prefix = 'Imagine\\Sites\\Production\\';
    if (str_starts_with($name, $prefix)) {
        $file = $root.'/src/Production/'.substr($name, strlen($prefix)).'.php';
        if (is_file($file)) require $file;
    }
});
$files = $root.'/contracts/portal-sites/candidates/brief-production-v1/examples/';
$read = static function (string $name) use ($files): string {
    $value = file_get_contents($files.$name);
    if (!is_string($value)) throw new RuntimeException('Missing tracked synthetic example');
    return $value;
};
$asJson = static fn(array $value): string => json_encode(
    $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";
$source = $read('onepage-brief.synthetic.json');
$editorial = $read('render-input.synthetic.json');
$manifest = json_decode($read('brief-snapshot.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$manifest['payload']['sizeBytes'] = strlen($source);
$manifest['payload']['sha256'] = hash('sha256', $source);
$manifestBytes = $asJson($manifest);
$plan = json_decode($read('brief-render-plan.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$plan['snapshot']['manifestSha256'] = hash('sha256', $manifestBytes);
$plan['renderInput']['sizeBytes'] = strlen($editorial);
$plan['renderInput']['sha256'] = hash('sha256', $editorial);
$expected = LocalSiteRendering::render($editorial);
$plan['expectedArtifact'] = ['sha256' => $expected->digest, 'sizeBytes' => $expected->sizeBytes];
$planBytes = $asJson($plan);
$export = [
    'manifestBytes' => $manifestBytes,
    'sourceBytes' => $source,
    'manifestSha256' => hash('sha256', $manifestBytes),
    'payloadSha256' => hash('sha256', $source),
    'authorityVerified' => false,
    'clientAccepted' => false,
    'canPublish' => false,
    'canAcceptRealOrders' => false,
];
$checks = 0;
$ok = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Onepage review handoff: '.$name);
    ++$checks;
};
$deny = static function (callable $call, string $name) use ($ok): void {
    $message = null;
    ob_start();
    try { $call(); } catch (RuntimeException $error) {
        $message = $error->getMessage() === 'ONEPAGE_REVIEW_HANDOFF_DENIED' && $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $ok($message === true && $output === '', $name);
};
$make = static fn(array $export, string $planBytes, string $editorial): array =>
    OnepageReviewHandoffCandidate::build($export, $planBytes, $editorial);
$packet = $make($export, $planBytes, $editorial);
$packetAgain = $make(array_reverse($export, true), $planBytes, $editorial);
$ok($packet === $packetAgain, 'replay produces identical original bytes and commitment');
OnepageReviewHandoffCandidate::assertMatches($packet, $export, $planBytes, $editorial);
$ok(true, 'packet equality checks exact origin bytes and original plan');
$review = json_decode($packet['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
$ok($review['tenantRef'] === $manifest['tenantRef'] && $review['orderRef'] === $manifest['orderRef'] &&
    $review['siteRef'] === $manifest['siteRef'] && $review['snapshotRef'] === $manifest['snapshotRef'] &&
    $review['sourceVersion'] === $manifest['sourceVersion'] && $review['briefRevision'] === $manifest['briefRevision'],
    'review contains explicit tenant/order and immutable selected revisions');
$ok($review['productCode'] === 'onepage' && $review['releaseRef'] === $plan['releaseRef'] &&
    $review['artifactSha256'] === $plan['expectedArtifact']['sha256'] &&
    $review['artifactSizeBytes'] === $plan['expectedArtifact']['sizeBytes'],
    'real Sites renderer determines the exact selected release artifact');
foreach (['portalApprovalAuthenticated', 'clientAccepted', 'internalReviewApproved', 'canPublish', 'canAcceptRealOrders'] as $flag) {
    $ok($review[$flag] === false, 'never promotes authority flag '.$flag);
}
$ok(!str_contains($packet['reviewBytes'], 'company_intro') &&
    !str_contains($packet['reviewBytes'], 'Oficina Horizonte') &&
    !str_contains($packet['reviewBytes'], '<html') &&
    strlen($packet['reviewBytes']) < 4096,
    'review packet contains no source text or rendered HTML');
foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) {
    $altered = $export;
    $altered[$flag] = true;
    $deny(static fn() => $make($altered, $planBytes, $editorial), 'upstream flag '.$flag.' cannot elevate');
}
foreach (['manifestSha256', 'payloadSha256'] as $key) {
    $altered = $export;
    $altered[$key] = str_repeat('a', 64);
    $deny(static fn() => $make($altered, $planBytes, $editorial), 'corrupt digest '.$key);
}
foreach (['siteRef', 'tenantRef', 'snapshotRef', 'briefRevision', 'manifestSha256'] as $field) {
    $altered = $plan;
    if ($field === 'tenantRef') $altered['tenantRef'] = 'tenant-crossed';
    elseif ($field === 'siteRef') $altered['siteRef'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    elseif ($field === 'snapshotRef') $altered['snapshot']['snapshotRef'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    elseif ($field === 'briefRevision') $altered['snapshot']['briefRevision']++;
    else $altered['snapshot']['manifestSha256'] = str_repeat('b', 64);
    $deny(static fn() => $make($export, $asJson($altered), $editorial),
        'crossed or stale render selection '.$field);
}
$other = $plan;
$other['expectedArtifact']['sha256'] = str_repeat('c', 64);
$deny(static fn() => $make($export, $asJson($other), $editorial), 'unrendered replacement digest');
$other = $plan;
$other['renderInput']['sha256'] = str_repeat('d', 64);
$deny(static fn() => $make($export, $asJson($other), $editorial), 'changed editorial bytes');
$other = $manifest;
$other['productCode'] = 'landing-page';
$changed = $export;
$changed['manifestBytes'] = $asJson($other);
$changed['manifestSha256'] = hash('sha256', $changed['manifestBytes']);
$deny(static fn() => $make($changed, $planBytes, $editorial), 'Landing Page cannot impersonate Onepage');
$other = $manifest;
$other['orderRef'] = 'different-order';
$changed = $export;
$changed['manifestBytes'] = $asJson($other);
$changed['manifestSha256'] = hash('sha256', $changed['manifestBytes']);
$deny(static fn() => $make($changed, $planBytes, $editorial), 'order change cannot reuse selected manifest');
$other = $export;
$other['canPublish'] = false;
$other['clientAccepted'] = 'false';
$deny(static fn() => $make($other, $planBytes, $editorial), 'string false does not count');
$other = $export;
$other['newAuthorityFlag'] = true;
$deny(static fn() => $make($other, $planBytes, $editorial), 'extra export authority is refused');
$other = $packet;
$other['reviewBytes'] = str_replace('pending_independent_review', 'approved_for_publication__', $other['reviewBytes']);
$deny(static fn() => OnepageReviewHandoffCandidate::assertMatches($other, $export, $planBytes, $editorial),
    'changed review state is not accepted under original digest');
$other = $packet;
$other['reviewSha256'] = str_repeat('0', 64);
$deny(static fn() => OnepageReviewHandoffCandidate::assertMatches($other, $export, $planBytes, $editorial),
    'counterfeit digest is denied');
$other = $packet;
$other['canPublish'] = true;
$deny(static fn() => OnepageReviewHandoffCandidate::assertMatches($other, $export, $planBytes, $editorial),
    'no extra acceptance flag in packet envelope');
$other = $plan;
$other['releaseRef'] = 'second-synthetic-release';
$second = $make($export, $asJson($other), $editorial);
$ok($second['reviewSha256'] !== $packet['reviewSha256'], 'distinct release produces distinct commitment');
$deny(static fn() => OnepageReviewHandoffCandidate::assertMatches($packet, $export, $asJson($other), $editorial),
    'different release cannot replay the original packet');
$ok($packet === $make($export, $planBytes, $editorial), 'denied mutations did not change the original candidate');
echo 'ONEPAGE REVIEW HANDOFF OFFLINE PASS: '.$checks." checks; zero real approval or publication.\n";
