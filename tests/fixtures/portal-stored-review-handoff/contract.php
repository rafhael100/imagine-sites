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

$crChecks = 0;
$crOk = static function (bool $condition, string $label) use (&$crChecks): void {
    if (!$condition) throw new RuntimeException('Onepage current readback PG18: '.$label);
    ++$crChecks;
};
$crDeny = static function (callable $callback, string $label) use ($crOk): void {
    $denied = false;
    ob_start();
    try { $callback(); } catch (RuntimeException $error) {
        $denied = $error->getMessage() === 'ONEPAGE_STORED_REVIEW_DENIED' &&
            $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $crOk($denied && $output === '', $label.' is redacted');
};
$crNoMutation = static function (string $label) use ($crOk, $srRows, $srCatalog, $bapAdmin): void {
    $crOk(bapRows($bapAdmin) === $srRows && bapCatalog($bapAdmin) === $srCatalog,
        $label.' preserves every row and catalog object');
};
$allowed->assertCurrent($packet, $export, $bapPlans['main'], $bapEditorial);
$crOk(true, 'valid persisted pointer can be revalidated exactly');
$allowed->assertCurrent($packet, $export, $bapPlans['main'], $bapEditorial);
$crOk(true, 'readback replay is deterministic');
$crNoMutation('positive readback');

$claim = $packet;
$claim['reviewSha256'] = str_repeat('a', 64);
$crDeny(static fn() => $allowed->assertCurrent($claim, $export, $bapPlans['main'], $bapEditorial),
    'altered digest');
$claim = $packet;
$reviewData = json_decode($claim['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
$reviewData['state'] = 'approved_for_publication';
$claim['reviewBytes'] = bapJson($reviewData);
$claim['reviewSha256'] = hash('sha256', "SITES_ONEPAGE_REVIEW_HANDOFF_CANDIDATE_V1\0".$claim['reviewBytes']);
$crDeny(static fn() => $allowed->assertCurrent($claim, $export, $bapPlans['main'], $bapEditorial),
    'approval spoofed with valid recalculated digest');
$claim = $packet;
$reviewData = json_decode($claim['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
$reviewData['tenantRef'] = 'other-company';
$claim['reviewBytes'] = bapJson($reviewData);
$claim['reviewSha256'] = hash('sha256', "SITES_ONEPAGE_REVIEW_HANDOFF_CANDIDATE_V1\0".$claim['reviewBytes']);
$crDeny(static fn() => $allowed->assertCurrent($claim, $export, $bapPlans['main'], $bapEditorial),
    'different tenant forged with valid recalculated digest');
$claim = $packet;
$claim['canPublish'] = true;
$crDeny(static fn() => $allowed->assertCurrent($claim, $export, $bapPlans['main'], $bapEditorial),
    'added authority flag');
$claim = $packet;
unset($claim['reviewBytes']);
$crDeny(static fn() => $allowed->assertCurrent($claim, $export, $bapPlans['main'], $bapEditorial),
    'missing payload');
$claim = $packet;
$claim['reviewSha256'] = false;
$crDeny(static fn() => $allowed->assertCurrent($claim, $export, $bapPlans['main'], $bapEditorial),
    'wrong digest type');
$crNoMutation('forged claimed packets');

$otherPlan = json_decode($bapPlans['main'], true, 16, JSON_THROW_ON_ERROR);
$otherPlan['releaseRef'] = 'other-review-release';
$otherPlanBytes = bapJson($otherPlan);
$otherPacket = OnepageReviewHandoffCandidate::build($export, $otherPlanBytes, $bapEditorial);
$crOk($otherPacket['reviewSha256'] !== $packet['reviewSha256'],
    'different release binds different packet');
$crDeny(static fn() => $allowed->assertCurrent($packet, $export, $otherPlanBytes, $bapEditorial),
    'old release reused for a new release');
$crDeny(static fn() => $allowed->assertCurrent($otherPacket, $export, $bapPlans['main'], $bapEditorial),
    'new release reused for an old release');
$crDeny(static fn() => $reader($bapRoles['source_reader_b'])->assertCurrent(
    $packet, $export, $bapPlans['main'], $bapEditorial), 'foreign tenant SQL reader');
$otherExport = $export;
$otherExport['sourceBytes'] .= ' ';
$otherExport['payloadSha256'] = hash('sha256', $otherExport['sourceBytes']);
$crDeny(static fn() => $allowed->assertCurrent(
    $packet, $otherExport, $bapPlans['main'], $bapEditorial), 'changed source from earlier review');
$crNoMutation('stale release and denied tenant');
$allowed->assertCurrent($packet, $export, $bapPlans['main'], $bapEditorial);
$crOk(true, 'original pointer remains valid after all denials');
$crNoMutation('final valid replay');
echo 'ONEPAGE CURRENT READBACK PG18 PASS: '.$crChecks.
    " checks; no publication, approval or mutation.\n";

echo 'ONEPAGE STORED REVIEW PG18 PASS: '.$srChecks.
    " checks; tenant-scoped current reader; zero real approval or publication.\n";
