<?php

declare(strict_types=1);

// A disposable PostgreSQL composition fixture, NOT a Portal approval source.
// Exercise the real Portal #976 default-deny gate and Sites recorder/renderer
// together. Every permissive source/witness below is a test-only double.
use App\Domain\SitesIntegration\ApprovedOnepageBriefSnapshot;
use App\Domain\SitesIntegration\ApprovedOnepageBriefSnapshotSource;
use App\Domain\SitesIntegration\CurrentOnepageBriefApprovalWitness;
use App\Domain\SitesIntegration\OnepageApprovedBriefExportGate;
use App\Domain\SitesIntegration\OnepageRenderPlanCandidateEncoder;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Integration\PortalBriefSnapshotRecorder;
use Imagine\Sites\Production\BriefSnapshotInput;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_PORTAL_APPROVED_GATE_CI') !== '1' ||
    getenv('SITES_PORTAL_RENDER_PLAN_BOUNDARY_CI') !== '1' ||
    getenv('SITES_PORTAL_BRIEF_ADVERSARIAL_CI') !== '1' ||
    !isset($bapAdmin, $bapDb, $bapRoles, $bapSnapshots, $bapProjects,
        $bapPortalPairs, $bapPlans, $bapEditorial, $bapHtml, $bapMakePlan)) {
    throw new RuntimeException('Exact disposable Sites/Portal approved-brief gate prerequisites required');
}
$gateChecks = 0;
$checkGate = static function (bool $ok, string $label) use (&$gateChecks): void {
    if (!$ok) throw new RuntimeException('Portal approved gate / Sites PG18: '.$label);
    ++$gateChecks;
};
$denyGate = static function (callable $call, string $label) use ($checkGate): void {
    $denied = false;
    try {
        $call();
    } catch (RuntimeException $error) {
        $denied = $error->getMessage() === 'ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED' &&
            $error->getPrevious() === null;
    }
    $checkGate($denied, $label.' is denied without exposing evidence');
};
$stored = $bapSnapshots['main'];
$pair = $bapPortalPairs[$stored->snapshotRef] ?? null;
$receipt = $bapProjects['main']['receipt'];
$checkGate(is_array($pair) && $stored->sourceJson === $pair['sourceBytes'] &&
    $stored->manifestSha256 === $pair['manifestSha256'] &&
    $receipt['siteRef'] === $stored->siteRef,
    'the selected Portal pair is the actual stored, scoped Sites snapshot');

$manifest = json_decode($pair['manifestBytes'], true, 16, JSON_THROW_ON_ERROR);
$selection = [
    'siteRef' => $manifest['siteRef'],
    'snapshotRef' => $manifest['snapshotRef'],
    'operationId' => $manifest['operationId'],
    'idempotencyKey' => $manifest['idempotencyKey'],
    'sourceVersion' => $manifest['sourceVersion'],
    'expectedBriefRevision' => $manifest['expectedBriefRevision'],
    'payloadRef' => $manifest['payload']['payloadRef'],
];
$contract = new SiteContractSnapshot(
    $receipt['tenantRef'], $receipt['orderRef'], $receipt['contractRef'],
    $receipt['contractRevision'], 'onepage',
);
$approved = new ApprovedOnepageBriefSnapshot(
    $contract, $selection, $pair['sourceBytes'], $pair['payloadSha256'],
    'fixture-approval-ref-not-official', 1,
);
$contracts = new class($contract) implements PortalSiteContractSource {
    public int $calls = 0;
    public bool $deny = false;
    public ?SiteContractSnapshot $next = null;
    public function __construct(public SiteContractSnapshot $current) {}
    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot {
        ++$this->calls;
        if ($this->deny || $orderRef !== $this->current->orderRef) return null;
        return $this->calls > 1 && $this->next !== null ? $this->next : $this->current;
    }
};
$briefs = new class($approved) implements ApprovedOnepageBriefSnapshotSource {
    public int $calls = 0;
    public bool $deny = false;
    public ?ApprovedOnepageBriefSnapshot $next = null;
    public function __construct(public ApprovedOnepageBriefSnapshot $current) {}
    public function readExact(string $orderRef, string $siteRef, int $sourceVersion): ?ApprovedOnepageBriefSnapshot {
        ++$this->calls;
        if ($this->deny || $orderRef !== $this->current->contract->orderRef ||
            $siteRef !== $this->current->selection['siteRef'] ||
            $sourceVersion !== $this->current->selection['sourceVersion']) return null;
        return $this->calls > 1 && $this->next !== null ? $this->next : $this->current;
    }
};
$witness = new class implements CurrentOnepageBriefApprovalWitness {
    public int $calls = 0;
    public bool $approved = true;
    public bool $revokeAfterFirst = false;
    public function isCurrent(ApprovedOnepageBriefSnapshot $brief): bool {
        ++$this->calls;
        if ($this->revokeAfterFirst && $this->calls === 1) {
            $this->approved = false; // First read succeeds; second denies.
            return true;
        }
        return $this->approved;
    }
};
$gate = new OnepageApprovedBriefExportGate($contracts, $briefs, $witness);
$reset = static function () use ($contracts, $briefs, $witness, $contract, $approved): void {
    $contracts->calls = 0;
    $contracts->deny = false;
    $contracts->next = null;
    $contracts->current = $contract;
    $briefs->calls = 0;
    $briefs->deny = false;
    $briefs->next = null;
    $briefs->current = $approved;
    $witness->calls = 0;
    $witness->approved = true;
    $witness->revokeAfterFirst = false;
};
$rows = bapRows($bapAdmin);
$catalog = bapCatalog($bapAdmin);
$checkUnchanged = static function (string $label) use ($checkGate, $bapAdmin, $rows, $catalog): void {
    $checkGate(bapRows($bapAdmin) === $rows && bapCatalog($bapAdmin) === $catalog,
        $label.' leaves the complete PostgreSQL rows/catalog unchanged');
};

// No application binding exists. The default gate MUST refuse the same
// selection even though the native Sites fixture has a matching snapshot.
$denyGate(static fn() => (new OnepageApprovedBriefExportGate())->prepare(
    $contract->orderRef, $stored->siteRef, $stored->sourceVersion,
), 'default-deny independent Portal sources');
$checkUnchanged('default-deny');

$gated = $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion);
$checkGate($gated === $pair &&
    $contracts->calls === 2 && $briefs->calls === 2 && $witness->calls === 2,
    'two reads of synthetic contract, immutable content and independent witness produce exact original bytes');
foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) {
    $checkGate($gated[$flag] === false, 'Portal gate does not promote '.$flag);
}
$native = BriefSnapshotInput::fromJson($gated['manifestBytes'], $gated['sourceBytes']);
$checkGate($native->snapshotRef === $stored->snapshotRef &&
    $native->manifestSha256 === $stored->manifestSha256 &&
    $native->payloadSha256 === $stored->payloadSha256,
    'real Sites parser accepts byte-for-byte gated candidate selected by immutable UUID');
$replay = (new PortalBriefSnapshotRecorder(bapConnection(
    $bapDb($bapRoles['source_receiver_a']),
)))->record($native);
$checkGate($replay['manifestSha256'] === $stored->manifestSha256 &&
    $replay['canPublish'] === false && $replay['canAcceptRealOrders'] === false,
    'real scoped Sites recorder idempotently replays the gate candidate with zero publication authority');
$checkUnchanged('idempotent replay');

$planManifest = json_decode($bapPlans['main'], true, 16, JSON_THROW_ON_ERROR);
$renderSelection = [
    'operationId' => $planManifest['operationId'],
    'idempotencyKey' => $planManifest['idempotencyKey'],
    'releaseRef' => $planManifest['releaseRef'],
    'renderInputRef' => $planManifest['renderInput']['payloadRef'],
];
$gatedPlan = OnepageRenderPlanCandidateEncoder::build(
    $gated, $bapEditorial, $renderSelection,
    ['sha256' => BAP_HTML_SHA, 'sizeBytes' => strlen($bapHtml)],
);
$planned = $bapMakePlan($bapRoles['writer_a'], $gatedPlan['planBytes']);
$checkGate($gatedPlan['planBytes'] === $bapPlans['main'] &&
    $planned['status'] === 'planned' &&
    $planned['artifactDigest'] === BAP_HTML_SHA &&
    $planned['canPublish'] === false && $planned['clientAccepted'] === false,
    'the gated pair composes the same exact plan and real Sites renderer verifies HTML without publication');
$checkUnchanged('gated render plan');

// Denials are tested with *valid* original bytes and selectors; none should
// reach the Sites recorder, even as a harmless replay, once a witness fails.
$reset(); $contracts->deny = true;
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion),
    'missing authorized contract');
$checkUnchanged('missing contract');

$reset(); $briefs->deny = true;
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion),
    'missing exact approved body');
$checkUnchanged('missing body');

$reset(); $witness->approved = false;
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion),
    'missing current approval');
$checkUnchanged('missing approval');

$reset(); $witness->revokeAfterFirst = true;
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion),
    'revocation detected between independent witness reads');
$checkGate($witness->calls === 2, 'revocation reached the second witness read');
$checkUnchanged('revocation');

$reset(); $contracts->next = new SiteContractSnapshot(
    $contract->tenantRef, $contract->orderRef, $contract->contractRef,
    $contract->contractRevision + 1, 'onepage',
);
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion),
    'contract revision changed between source reads');
$checkUnchanged('contract CAS');

$reset(); $contracts->next = new SiteContractSnapshot(
    $bapProjects['company_b']['receipt']['tenantRef'], $contract->orderRef,
    $contract->contractRef, $contract->contractRevision, 'onepage',
);
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion),
    'tenant ownership changed between source reads');
$checkUnchanged('tenant drift');

$reset();
$changed = json_decode($pair['sourceBytes'], true, 16, JSON_THROW_ON_ERROR);
$changed['notes'] .= ' Outra versão de laboratório não aprovada.';
$changedBytes = bapJson($changed);
$briefs->next = new ApprovedOnepageBriefSnapshot(
    $contract, $selection, $changedBytes, hash('sha256', $changedBytes),
    $approved->approvalRef, $approved->approvalEpoch,
);
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion),
    'original source bytes drift between reads');
$checkUnchanged('source drift');

$reset();
$briefs->next = new ApprovedOnepageBriefSnapshot(
    $contract, $selection, $pair['sourceBytes'], $pair['payloadSha256'],
    'fixture-different-approval-ref', $approved->approvalEpoch + 1,
);
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion),
    'approval epoch/ref changed between reads');
$checkUnchanged('approval drift');

$reset();
$denyGate(static fn() => $gate->prepare($contract->orderRef, $stored->siteRef,
    $stored->sourceVersion + 1), 'no fallback to latest version');
$checkUnchanged('unknown selected version');

$reset();
$checkGate($gate->prepare($contract->orderRef, $stored->siteRef, $stored->sourceVersion) === $pair,
    'valid original contract/source/approval can be rechecked after all denials');
$checkUnchanged('final positive gate');

echo 'PORTAL APPROVED BRIEF GATE PG18 PASS: '.$gateChecks.
    " checks; synthetic witness only, no official Portal approval or transport.\n";
