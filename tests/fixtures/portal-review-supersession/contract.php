<?php

declare(strict_types=1);

use App\Integration\StoredBriefSnapshotReader;
use App\Integration\StoredOnepageReviewHandoff;
use App\Integration\StoredOnepageReviewSupersession;

// Included only AFTER the second immutable revision was written by the
// existing disposable PG18 fixture. Every authority field remains false.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_ONEPAGE_SUPERSESSION_CI') !== '1' ||
    getenv('SITES_ONEPAGE_STORED_REVIEW_CI') !== '1' ||
    !isset($bapAdmin, $bapDb, $bapRoles, $bapSnapshots,
        $secondSnapshot, $bapPortalPairs, $bapPlans, $bapEditorial, $bapPlan)) {
    throw new RuntimeException('Exact disposable two-revision PG18 laboratory required');
}

$suChecks = 0;
$suOk = static function (bool $ok, string $label) use (&$suChecks): void {
    if (!$ok) throw new RuntimeException('Onepage supersession PG18: '.$label);
    ++$suChecks;
};
$suDeny = static function (callable $callback, string $label) use ($suOk): void {
    $denied = false;
    ob_start();
    try {
        $callback();
    } catch (RuntimeException $error) {
        $denied = $error->getMessage() === 'ONEPAGE_REVIEW_SUPERSESSION_DENIED' &&
            $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $suOk($denied && $output === '', $label.' denied without disclosure');
};
$suRows = bapRows($bapAdmin);
$suCatalog = bapCatalog($bapAdmin);
$suNoWrite = static function (string $label) use ($suOk, $suRows, $suCatalog, $bapAdmin): void {
    $suOk($suRows === bapRows($bapAdmin) &&
        $suCatalog === bapCatalog($bapAdmin), $label.' preserves rows and catalog');
};

$suOld = $bapPortalPairs[$bapSnapshots['main']->snapshotRef] ?? null;
$suNext = $bapPortalPairs[$secondSnapshot->snapshotRef] ?? null;
$suOk(is_array($suOld) && is_array($suNext) &&
    $bapSnapshots['main']->briefRevision === 1 &&
    $secondSnapshot->briefRevision === 2,
    'two real Portal-produced candidates are stored at revisions one and two');
$suOldPlan = $bapPlans['main'];
$suNextData = json_decode($bapPlan('main', $secondSnapshot), true, 16, JSON_THROW_ON_ERROR);
$suNextData['releaseRef'] .= '-revision-2';
$suNextData['operationId'] .= '-revision-2';
$suNextData['idempotencyKey'] .= '-revision-2';
$suNextPlan = bapJson($suNextData);

$suHandoff = static fn(string $role): StoredOnepageReviewHandoff =>
    new StoredOnepageReviewHandoff(new StoredBriefSnapshotReader(
        bapConnection($bapDb($role, true)),
    ));
$suAllowed = $suHandoff($bapRoles['source_reader_a']);
$suService = new StoredOnepageReviewSupersession($suAllowed);
$suPrevious = $suAllowed->prepare($suOld, $suOldPlan, $bapEditorial);
$suNextPacket = $suAllowed->prepare($suNext, $suNextPlan, $bapEditorial);
$suOk($suPrevious['reviewSha256'] !== $suNextPacket['reviewSha256'],
    'even identical HTML produces distinct revision-bound review pointers');

$suMake = static fn(array $oldClaim, array $oldExport, string $oldPlan,
    array $newExport, string $newPlan, ?StoredOnepageReviewSupersession $service = null): array =>
    ($service ?? $suService)->prepareNext($oldClaim, $oldExport, $oldPlan, $bapEditorial,
        $newExport, $newPlan, $bapEditorial);
$suProof = $suMake($suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan);
$suOk($suProof === $suMake($suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'two current SQL readbacks produce deterministic no-write supersession evidence');
$suData = json_decode($suProof['supersessionBytes'], true, 16, JSON_THROW_ON_ERROR);
$suOldPacket = json_decode($suPrevious['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
$suNewPacket = json_decode($suNextPacket['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
$suOk($suData['state'] === 'requires_independent_rereview' &&
    $suData['tenantRef'] === $suOldPacket['tenantRef'] &&
    $suData['orderRef'] === $suNewPacket['orderRef'] &&
    $suData['previousBriefRevision'] === 1 &&
    $suData['nextBriefRevision'] === 2 &&
    $suData['previousReviewSha256'] === $suPrevious['reviewSha256'] &&
    $suData['nextReviewSha256'] === $suNextPacket['reviewSha256'],
    'same order/source selects a fresh required review, not approval');
foreach (['portalApprovalAuthenticated', 'internalReviewApproved',
    'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) {
    $suOk(($suData[$flag] ?? null) === false, 'supersession keeps '.$flag.' blocked');
}
$suOk($suOldPacket['artifactSha256'] === $suNewPacket['artifactSha256'] &&
    !str_contains($suProof['supersessionBytes'], $suOld['sourceBytes']) &&
    !str_contains($suProof['supersessionBytes'], $suNext['manifestBytes']),
    'same visual HTML does not waive review, source and manifest not disclosed');
$suNoWrite('positive and replay');

$suDeny(static fn() => $suMake(
    $suPrevious, $suOld, $suOldPlan, $suOld, $suOldPlan), 'same revision replay');
$suDeny(static fn() => $suMake(
    $suNextPacket, $suNext, $suNextPlan, $suOld, $suOldPlan), 'reverse chronology');
$suDeny(static fn() => $suMake(
    $suPrevious, $suOld, $suOldPlan, $suNext, $bapPlan('main', $secondSnapshot)),
    'same release reused across revisions');
$suNoWrite('revision and release denials');

$suFakeOld = $suPrevious;
$suFakeOld['canPublish'] = true;
$suDeny(static fn() => $suMake(
    $suFakeOld, $suOld, $suOldPlan, $suNext, $suNextPlan), 'forged publication right');
$suFakeOld = $suPrevious;
$suDecoded = json_decode($suFakeOld['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
$suDecoded['internalReviewApproved'] = true;
$suFakeOld['reviewBytes'] = bapJson($suDecoded);
$suFakeOld['reviewSha256'] = hash('sha256',
    "SITES_ONEPAGE_REVIEW_HANDOFF_CANDIDATE_V1\0".$suFakeOld['reviewBytes']);
$suDeny(static fn() => $suMake(
    $suFakeOld, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'falsified reviewer approval with recalculated digest');

$suFakeNext = $suNext;
$suFakeNext['sourceBytes'] .= " ";
$suFakeNext['payloadSha256'] = hash('sha256', $suFakeNext['sourceBytes']);
$suDeny(static fn() => $suMake(
    $suPrevious, $suOld, $suOldPlan, $suFakeNext, $suNextPlan),
    'second source drift with coherent digest');

$suForeign = new StoredOnepageReviewSupersession($suHandoff($bapRoles['source_reader_b']));
$suDeny(static fn() => $suMake(
    $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan, $suForeign),
    'cross-tenant SQL reader');
$suNoWrite('tampering and tenant denials');

$suOk($suProof === $suMake($suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'legitimate replay remains identical after every negative case');
$suNoWrite('final replay');
if (getenv('SITES_ONEPAGE_SUPERSESSION_READBACK_CI') === '1') {
    require __DIR__.'/current-readback.php';
}

echo 'ONEPAGE REVIEW SUPERSESSION PG18 PASS: '.$suChecks.
    " checks; fresh revision needs independent re-review, no approval/publication.\n";
