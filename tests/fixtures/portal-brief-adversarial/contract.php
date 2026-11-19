<?php

declare(strict_types=1);

use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\OnepageBriefSnapshotCandidateEncoder;
use App\Integration\PortalBriefSnapshotRecorder;
use Imagine\Sites\Production\BriefSnapshotInput;

// Included from the already authenticated/disposable PG18 provenance fixture,
// immediately after original candidate snapshots/plans exist. All test data
// is synthetic. No Portal authority, network/provider or production target.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_PORTAL_BRIEF_EXPORT_CI') !== '1' ||
    getenv('SITES_PORTAL_BRIEF_ADVERSARIAL_CI') !== '1' ||
    !isset($bapAdmin, $bapProjects, $bapSnapshots, $bapSource, $bapDb,
        $bapRoles, $bapPortalPairs, $bapPortalChecks)) {
    throw new RuntimeException('The verified Portal candidate and PG18 fixture are required');
}

$advChecks = 0;
$advCheck = static function (bool $condition, string $label) use (&$advChecks): void {
    if (!$condition) throw new RuntimeException('Portal candidate adversarial failure: '.$label);
    ++$advChecks;
};
$advBase = bapRows($bapAdmin);
$advCatalog = bapCatalog($bapAdmin);
$advMain = $bapProjects['main']['receipt'];
$advCompanyB = $bapProjects['company_b']['receipt'];
$advStored = $bapSnapshots['main'];
$advCheck(isset($bapPortalPairs[$advStored->snapshotRef]) &&
    $bapPortalChecks >= 2 && $advStored->sourceJson === $bapSource,
    'positive Portal candidate snapshot was actually stored before negative controls');
$advReplay = (new PortalBriefSnapshotRecorder(bapConnection(
    $bapDb($bapRoles['source_receiver_a']),
)))->record($advStored);
$advCheck($advReplay['manifestSha256'] === $advStored->manifestSha256 &&
    $advReplay['payloadSha256'] === $advStored->payloadSha256 &&
    $advReplay['sourceVersion'] === $advStored->sourceVersion &&
    $advReplay['canPublish'] === false && $advReplay['canAcceptRealOrders'] === false,
    'authorized exact replay returns original hashes with no publication authority');
$advCheck(bapRows($bapAdmin) === $advBase &&
    bapCatalog($bapAdmin) === $advCatalog,
    'positive exact replay is idempotent and does not append');

$advCandidate = static function (
    array $contractData, string $siteId, int $expected, int $sourceVersion,
    string $operationId, string $key, string $source, ?string $snapshotId = null
) use ($advMain): BriefSnapshotInput {
    $contract = new SiteContractSnapshot(
        $contractData['tenantRef'], $contractData['orderRef'],
        $contractData['contractRef'], $contractData['contractRevision'],
        'onepage',
    );
    $export = OnepageBriefSnapshotCandidateEncoder::build($contract, [
        'siteRef' => $siteId,
        'snapshotRef' => $snapshotId ?? bapUuid(),
        'operationId' => $operationId,
        'idempotencyKey' => $key,
        'sourceVersion' => $sourceVersion,
        'expectedBriefRevision' => $expected,
        'payloadRef' => 'brief-body-synthetic-001',
    ], $source);
    return BriefSnapshotInput::fromJson($export['manifestBytes'], $export['sourceBytes']);
};
$changed = json_decode($bapSource, true, 8, JSON_THROW_ON_ERROR);
$changed['notes'] .= ' Alteração fictícia, sem cliente ou material real.';
$modifiedSource = bapJson($changed);

$fixtures = [
    'other tenant metadata at A site' => [
        array_replace($advMain, ['tenantRef' => $advCompanyB['tenantRef']]),
        $advMain['siteRef'], 1, 20, 'adv-tenant-a', 'adv-key-tenant-a', $bapSource, null, 'a',
    ],
    'B-owned site with A receiver' => [
        $advCompanyB, $advCompanyB['siteRef'], 1, 20,
        'adv-other-site', 'adv-key-other-site', $bapSource, null, 'a',
    ],
    'foreign contract in A tenant' => [
        array_replace($advMain, ['contractRef' => $bapProjects['atomic']['receipt']['contractRef']]),
        $advMain['siteRef'], 1, 20, 'adv-contract', 'adv-key-contract', $bapSource, null, 'a',
    ],
    'foreign order in A tenant' => [
        array_replace($advMain, ['orderRef' => $bapProjects['atomic']['receipt']['orderRef']]),
        $advMain['siteRef'], 1, 20, 'adv-order', 'adv-key-order', $bapSource, null, 'a',
    ],
    'source counter did not advance' => [
        $advMain, $advMain['siteRef'], 1, $advStored->sourceVersion,
        'adv-source', 'adv-key-source', $bapSource, null, 'a',
    ],
    'old snapshot CAS after committed head' => [
        $advMain, $advMain['siteRef'], 0, 20,
        'adv-cas', 'adv-key-cas', $bapSource, null, 'a',
    ],
    'original operation but modified source bytes' => [
        $advMain, $advMain['siteRef'], 0, $advStored->sourceVersion,
        $advStored->operationId, $advStored->idempotencyKey,
        $modifiedSource, $advStored->snapshotRef, 'a',
    ],
    'unknown project with otherwise valid A contract' => [
        $advMain, bapUuid(), 0, 12,
        'adv-unknown', 'adv-key-unknown', $bapSource, null, 'a',
    ],
];
foreach ($fixtures as $label => [
    $context, $site, $expected, $version, $operation, $key, $source, $snapshotId, $actor
]) {
    $raw = $advCandidate($context, $site, $expected, $version,
        $operation, $key, $source, $snapshotId);
    $advCheck($raw->snapshotJson !== '' && $raw->siteRef === $site &&
        $raw->payloadSha256 === hash('sha256', $source),
        $label.' remains parseable and byte-coherent before the SQL authorization test');
    $before = bapRows($bapAdmin);
    $denied = false;
    try {
        (new PortalBriefSnapshotRecorder(bapConnection(
            $bapDb($bapRoles['source_receiver_'.$actor]),
        )))->record($raw);
    } catch (DomainException $error) {
        $denied = in_array($error->getMessage(), [
            'BRIEF_SNAPSHOT_DENIED', 'BRIEF_SNAPSHOT_CONFLICT',
        ], true) && $error->getPrevious() === null;
    }
    $advCheck($denied && bapRows($bapAdmin) === $before,
        $label.' denied by native scoped/CAS/replay SQL without appended rows');
}
$advCheck(bapRows($bapAdmin) === $advBase &&
    bapCatalog($bapAdmin) === $advCatalog,
    'all adversarial Portal inputs preserve immutable snapshots and registry catalog');
$advCheck((new PortalBriefSnapshotRecorder(bapConnection(
    $bapDb($bapRoles['source_receiver_a']),
)))->record($advStored) === $advReplay &&
    bapRows($bapAdmin) === $advBase,
    'positive original input still replays after all negative controls');

echo 'PORTAL BRIEF ADVERSARIAL PG18 PASS: '.$advChecks.
    " checks; valid-looking foreign/stale Portal candidates cannot mutate stored Sites history.\n";
