<?php

declare(strict_types=1);

use App\Integration\StoredBriefSnapshotReader;
use App\Integration\StoredOnepageReviewHandoff;
use Imagine\Sites\Production\OnepageReviewHandoffCandidate;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_ONEPAGE_STORED_REVIEW_CI') !== '1' ||
    getenv('SITES_PORTAL_APPROVED_GATE_CI') !== '1' ||
    !isset($bapAdmin, $bapDb, $bapRoles, $bapSnapshots, $bapPortalPairs, $bapPlans, $bapEditorial)) {
    throw new RuntimeException('Exact disposable stored Onepage review laboratory required');
}
$srChecks = 0;
$srOk = static function (bool $value, string $label) use (&$srChecks): void {
    if (!$value) throw new RuntimeException('Stored Onepage review PG18: '.$label);
    ++$srChecks;
};
$srDeny = static function (callable $callback, string $label) use ($srOk): void {
    $denied = false;
    ob_start();
    try { $callback(); } catch (RuntimeException $error) {
        $denied = $error->getMessage() === 'ONEPAGE_STORED_REVIEW_DENIED' && $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $srOk($denied && $output === '', $label.' is redacted');
};
$srRows = bapRows($bapAdmin);
$srCatalog = bapCatalog($bapAdmin);
$srNoMutation = static function (string $label) use ($srOk, $srRows, $srCatalog, $bapAdmin): void {
    $srOk(bapRows($bapAdmin) === $srRows && bapCatalog($bapAdmin) === $srCatalog,
        $label.' preserved every row and catalog object');
};
$main = $bapSnapshots['main'];
$pair = $bapPortalPairs[$main->snapshotRef] ?? null;
$srOk(is_array($pair), 'exact pinned Portal bytes exist in Sites snapshot fixture');
$export = [
    'manifestBytes' => $pair['manifestBytes'],
    'sourceBytes' => $pair['sourceBytes'],
    'manifestSha256' => $pair['manifestSha256'],
    'payloadSha256' => $pair['payloadSha256'],
    'authorityVerified' => false,
    'clientAccepted' => false,
    'canPublish' => false,
    'canAcceptRealOrders' => false,
];
$reader = static fn(string $role): StoredOnepageReviewHandoff =>
    new StoredOnepageReviewHandoff(new StoredBriefSnapshotReader(bapConnection($bapDb($role, true))));
$allowed = $reader($bapRoles['source_reader_a']);
$packet = $allowed->prepare($export, $bapPlans['main'], $bapEditorial);
$srOk($packet === OnepageReviewHandoffCandidate::build($export, $bapPlans['main'], $bapEditorial),
    'stored Onepage content yields the same exact pure review pointer');
$srOk($allowed->prepare($export, $bapPlans['main'], $bapEditorial) === $packet,
    'current stored source replays without creating another review');
$packetData = json_decode($packet['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
$srOk($packetData['siteRef'] === $main->siteRef &&
    $packetData['snapshotRef'] === $main->snapshotRef &&
    $packetData['manifestSha256'] === $main->manifestSha256 &&
    $packetData['tenantRef'] === $main->tenantRef &&
    $packetData['state'] === 'pending_independent_review' &&
    $packetData['clientAccepted'] === false && $packetData['canPublish'] === false,
    'tenant, immutable source, exact revision and blocked flags are bound');
$srNoMutation('positive replay');

$srDeny(static fn() => $reader($bapRoles['source_reader_b'])->prepare(
    $export, $bapPlans['main'], $bapEditorial), 'foreign tenant SQL reader');
$srNoMutation('foreign tenant');

$other = $export;
$other['sourceBytes'] .= " \n";
$other['payloadSha256'] = hash('sha256', $other['sourceBytes']);
$srDeny(static fn() => $allowed->prepare($other, $bapPlans['main'], $bapEditorial),
    'edited source bytes with recalculated hash');
$srNoMutation('edited source');

$other = $export;
$other['manifestBytes'] .= " \n";
$other['manifestSha256'] = hash('sha256', $other['manifestBytes']);
$srDeny(static fn() => $allowed->prepare($other, $bapPlans['main'], $bapEditorial),
    'edited manifest bytes with recalculated hash');
$srNoMutation('edited manifest');

$plan = json_decode($bapPlans['main'], true, 16, JSON_THROW_ON_ERROR);
foreach (['snapshotRef', 'briefRevision', 'manifestSha256', 'siteRef', 'tenantRef'] as $field) {
    $changed = $plan;
    if ($field === 'snapshotRef') $changed['snapshot']['snapshotRef'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    elseif ($field === 'briefRevision') ++$changed['snapshot']['briefRevision'];
    elseif ($field === 'manifestSha256') $changed['snapshot']['manifestSha256'] = str_repeat('0', 64);
    elseif ($field === 'siteRef') $changed['siteRef'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    else $changed['tenantRef'] = 'org-forged';
    $json = bapJson($changed);
    $srDeny(static fn() => $allowed->prepare($export, $json, $bapEditorial),
        'stale or foreign plan '.$field);
    $srNoMutation('stale or foreign plan '.$field);
}
$other = $export;
$other['canPublish'] = true;
$srDeny(static fn() => $allowed->prepare($other, $bapPlans['main'], $bapEditorial),
    'forged upstream authority flag');
$srNoMutation('forged authority flag');
$srOk($allowed->prepare($export, $bapPlans['main'], $bapEditorial) === $packet,
    'valid original selection remains readable after every refusal');
$srNoMutation('final valid replay');
echo 'ONEPAGE STORED REVIEW PG18 PASS: '.$srChecks.
    " checks; tenant-scoped current reader; zero real approval or publication.\n";
