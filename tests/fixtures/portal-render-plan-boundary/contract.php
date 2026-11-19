<?php

declare(strict_types=1);

use App\Domain\SitesIntegration\OnepageBriefSnapshotCandidateEncoder;
use App\Domain\SitesIntegration\OnepageRenderPlanCandidateEncoder;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use Imagine\Sites\Production\BriefRenderPlanInput;

// Included only from the established disposable PG18 provenance composition,
// after the real Portal producer, scoped receiver, and source reader are ready.
// This is NOT an official Portal approval, acceptance or publication path.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_PORTAL_BRIEF_EXPORT_CI') !== '1' ||
    getenv('SITES_PORTAL_BRIEF_ADVERSARIAL_CI') !== '1' ||
    getenv('SITES_PORTAL_RENDER_PLAN_BOUNDARY_CI') !== '1' ||
    !isset($bapAdmin, $bapPlans, $bapSnapshots, $bapProjects, $bapRoles,
        $bapSource, $bapEditorial, $bapHtml, $bapPortalPairs,
        $bapPortalChecks, $bapMakePlan, $bapRecord, $bapCaSha)) {
    throw new RuntimeException('Disposable Portal plan boundary prerequisites required');
}

$planBoundaryChecks = 0;
$planBoundaryCheck = static function (bool $ok, string $label) use (&$planBoundaryChecks): void {
    if (!$ok) throw new RuntimeException('Portal plan boundary: '.$label);
    ++$planBoundaryChecks;
};
$original = $bapSnapshots['main'];
$mainReceipt = $bapProjects['main']['receipt'];
$companyB = $bapProjects['company_b']['receipt'];
$originalPair = $bapPortalPairs[$original->snapshotRef] ?? null;
if (!is_array($originalPair) || $bapPortalChecks < 2) {
    throw new RuntimeException('Positive Portal exporter pair missing');
}
$basePlan = json_decode($bapPlans['main'], true, 16, JSON_THROW_ON_ERROR);
$selection = [
    'operationId' => $basePlan['operationId'],
    'idempotencyKey' => $basePlan['idempotencyKey'],
    'releaseRef' => $basePlan['releaseRef'],
    'renderInputRef' => $basePlan['renderInput']['payloadRef'],
];
$expected = ['sha256' => BAP_HTML_SHA, 'sizeBytes' => strlen($bapHtml)];
$baselineRows = bapRows($bapAdmin);
$baselineCatalog = bapCatalog($bapAdmin);
$originalPlan = OnepageRenderPlanCandidateEncoder::build(
    $originalPair, $bapEditorial, $selection, $expected,
);
$planBoundaryCheck($originalPlan['planBytes'] === $bapPlans['main'] &&
    $originalPlan['planSha256'] === hash('sha256', $bapPlans['main']) &&
    $originalPlan['editorialBytes'] === $bapEditorial,
    'genuine Portal plan uses the exact persisted snapshot and separate editorial bytes');
$valid = $bapMakePlan($bapRoles['writer_a'], $originalPlan['planBytes']);
$planBoundaryCheck($valid['status'] === 'planned' &&
    $valid['artifactDigest'] === BAP_HTML_SHA &&
    $valid['canPublish'] === false && $valid['clientAccepted'] === false &&
    bapRows($bapAdmin) === $baselineRows && bapCatalog($bapAdmin) === $baselineCatalog,
    'native positive plan reads stored source without appending or enabling authority');

$makePair = static function (
    array $receipt, string $siteRef, string $snapshotRef, int $expectedRevision,
    string $tag, string $source,
): array {
    return OnepageBriefSnapshotCandidateEncoder::build(
        new SiteContractSnapshot(
            $receipt['tenantRef'], $receipt['orderRef'],
            $receipt['contractRef'], $receipt['contractRevision'], 'onepage',
        ),
        [
            'siteRef' => $siteRef,
            'snapshotRef' => $snapshotRef,
            'operationId' => 'plan-boundary-operation-'.$tag,
            'idempotencyKey' => 'plan-boundary-key-'.$tag,
            'sourceVersion' => 24 + $expectedRevision,
            'expectedBriefRevision' => $expectedRevision,
            'payloadRef' => 'plan-boundary-source-'.$tag,
        ],
        $source,
    );
};

// All candidate plans below are produced by the actual pinned Portal encoder.
// They parse successfully on Sites. Their sole missing property is *authority
// to select the exact stored snapshot*, or agreement with the real renderer.
$alteredSource = json_decode($bapSource, true, 16, JSON_THROW_ON_ERROR);
$alteredSource['notes'] .= ' Conteúdo sintético modificado.';
$alteredEditorial = json_decode($bapEditorial, true, 16, JSON_THROW_ON_ERROR);
$alteredEditorial['headline'] .= ' com alteração não aprovada';
$alteredEditorialBytes = bapJson($alteredEditorial);
$cases = [
    'unpersisted A snapshot' => [
        $makePair($mainReceipt, $original->siteRef, bapUuid(), 0,
            'missing', $bapSource), $bapEditorial, $expected,
    ],
    'same UUID but different original manifest' => [
        $makePair($mainReceipt, $original->siteRef, $original->snapshotRef, 0,
            'same-ref-rewritten', $bapSource), $bapEditorial, $expected,
    ],
    'future revision not present at the selected UUID' => [
        $makePair($mainReceipt, $original->siteRef, $original->snapshotRef, 1,
            'future-revision', $bapSource), $bapEditorial, $expected,
    ],
    'foreign tenant claims A site and its snapshot UUID' => [
        $makePair($companyB, $original->siteRef, $original->snapshotRef, 0,
            'foreign-tenant', $bapSource), $bapEditorial, $expected,
    ],
    'foreign B site and snapshot under A reader' => [
        $makePair($companyB, $companyB['siteRef'], $bapSnapshots['company_b']->snapshotRef, 0,
            'foreign-site', $bapSource), $bapEditorial, $expected,
    ],
    'repackaged rich source with the original snapshot UUID' => [
        $makePair($mainReceipt, $original->siteRef, $original->snapshotRef, 0,
            'source-drift', bapJson($alteredSource)), $bapEditorial, $expected,
    ],
    'different editorial bytes with original expected HTML' => [
        $originalPair, $alteredEditorialBytes, $expected,
    ],
    'false expected artifact digest' => [
        $originalPair, $bapEditorial,
        ['sha256' => str_repeat('0', 64), 'sizeBytes' => strlen($bapHtml)],
    ],
    'false expected artifact byte length' => [
        $originalPair, $bapEditorial,
        ['sha256' => BAP_HTML_SHA, 'sizeBytes' => strlen($bapHtml) + 1],
    ],
];
$target = bapTarget($bapAdmin, $bapRoles['writer_a'], $bapCaSha);
foreach ($cases as $label => [$pair, $editorial, $artifact]) {
    $candidate = OnepageRenderPlanCandidateEncoder::build($pair, $editorial, $selection, $artifact);
    $plan = BriefRenderPlanInput::fromJson($candidate['planBytes']);
    $planBoundaryCheck(
        $plan->planSha256 === $candidate['planSha256'] &&
        $plan->renderInputSha256 === hash('sha256', $editorial) &&
        $candidate['authorityVerified'] === false &&
        $candidate['clientAccepted'] === false &&
        $candidate['canPublish'] === false &&
        $candidate['canAcceptRealOrders'] === false,
        $label.' remains a valid, unprivileged Portal plan on the Sites parser',
    );
    // A valid commitment binds the would-be record call, so denial must
    // come from the actual stored-source/renderer boundary, not a bad digest.
    $commitment = hash('sha256', "SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_V1\0".
        json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).
        "\0".$candidate['planBytes']."\0".$editorial."\0".$bapHtml);
    foreach (['plan', 'record'] as $method) {
        $denied = false;
        try {
            if ($method === 'plan') {
                $bapMakePlan($bapRoles['writer_a'], $candidate['planBytes'], $editorial);
            } else {
                $bapRecord($bapRoles['writer_a'], $candidate['planBytes'], $commitment, $editorial);
            }
        } catch (DomainException $error) {
            $denied = $error->getMessage() === 'BRIEF_ARTIFACT_PROVENANCE_DENIED' &&
                $error->getPrevious() === null;
        }
        $planBoundaryCheck($denied &&
            bapRows($bapAdmin) === $baselineRows &&
            bapCatalog($bapAdmin) === $baselineCatalog,
            $label.' denied at '.$method.' with unchanged rows/catalog');
    }
}
$planBoundaryCheck(
    $bapMakePlan($bapRoles['writer_a'], $originalPlan['planBytes']) === $valid &&
    bapRows($bapAdmin) === $baselineRows &&
    bapCatalog($bapAdmin) === $baselineCatalog,
    'genuine Portal plan still succeeds after every forged selection, without mutation',
);

echo 'PORTAL RENDER PLAN BOUNDARY PG18 PASS: '.$planBoundaryChecks.
    " checks; candidate plans cannot bypass exact stored source or renderer.\n";
