<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Reuse the single already-admitted fictitious order, approved version and
// renderer bytes. No writer, live buyer, new authority or publication.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR26 = 0;
$assertR26 = static function (bool $condition, string $label) use (&$checksR26): void {
    if (!$condition) throw new RuntimeException('ONEPAGE RECEIPT PREFLIGHT R26 FAILED: '.$label);
    ++$checksR26;
};

$probeR26 = static function (array $state, string $viewer = 'buyer-one') use ($brief): array {
    $reads = ['release' => 0, 'acceptance' => 0, 'presentation' => 0];
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static fn(): array => $state['record'],
        static fn(): array => $state['head'],
        static fn(): array => $state['confirmation'],
        static fn(): array => $state['review'],
    );
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use ($state, &$reads): array {
            ++$reads['release'];
            return $state['release'];
        },
        static function () use ($state, &$reads): array {
            ++$reads['acceptance'];
            // A denied buyer/briefing must never reach this private receipt.
            echo 'SYNTHETIC_RECEIPT_R26_PRIVATE_MARKER';
            return $state['acceptance'];
        },
        static function () use ($state, &$reads): array {
            ++$reads['presentation'];
            return $state['presentation'];
        },
    );
    $level = ob_get_level();
    ob_start();
    $accepted = null;
    $error = null;
    try {
        $accepted = $gate->observe(
            $viewer, $brief->tenantRef, $brief->orderRef,
            $brief->siteRef, $brief->sourceVersion,
        );
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $output = ob_get_clean();
        if (ob_get_level() !== $level) {
            throw new RuntimeException('R26 output buffer level drift');
        }
    }
    return compact('accepted', 'error', 'output', 'reads');
};

// The release metadata is confidential too. Validate current buyer rights
// and independent editorial approval via the existing private preview FIRST.
$negative = [
    'withdrawn-rights' => static function (array &$state): void {
        $state['confirmation']['rightsDeclared'] = false;
    },
    'revoked-review' => static function (array &$state): void {
        $state['review']['approved'] = false;
    },
    'edited-brief-bytes' => static function (array &$state): void {
        $state['record']['export']['sourceBytes'] .= 'SYNTHETIC_R26_EDIT';
    },
];
foreach ($negative as $label => $mutate) {
    $state = $initial;
    $mutate($state);
    $probe = $probeR26($state);
    $assertR26($probe['accepted'] === null &&
        $probe['error'] instanceof RuntimeException &&
        $probe['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $probe['error']->getPrevious() === null, 'uniform negative: '.$label);
    $assertR26($probe['reads'] === [
        'release' => 0, 'acceptance' => 0, 'presentation' => 0,
    ], 'no private receipt/presentation read: '.$label);
    $assertR26($probe['output'] === '', 'no leaked bytes: '.$label);
}

// Other buyer in the SAME tenant is denied without receipt disclosure.
$other = $probeR26($initial, 'buyer-two');
$assertR26($other['accepted'] === null &&
    $other['error'] instanceof RuntimeException &&
    $other['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED',
    'other buyer same tenant denied');
$assertR26($other['reads'] === [
    'release' => 0, 'acceptance' => 0, 'presentation' => 0,
], 'other buyer cannot read confidential receipt');
$assertR26($other['output'] === '', 'other buyer no output');

// Positive control: current buyer, exact bytes and approved revision may still
// read the independently observed receipt twice. Acceptance is NEVER publish.
$valid = $probeR26($initial);
$assertR26($valid['error'] === null && is_array($valid['accepted']),
    'valid fictitious acceptance observed');
$assertR26($valid['reads'] === [
    'release' => 2, 'acceptance' => 2, 'presentation' => 2,
], 'current exact buyer receipt and presentation independently reread');
$assertR26($valid['accepted']['htmlSha256'] === $originalArtifact->digest &&
    $valid['accepted']['clientFinalAccepted'] === true &&
    $valid['accepted']['publicationAuthorized'] === false &&
    $valid['accepted']['canPublish'] === false,
    'SHA exact, acceptance separate from publication');
$assertR26($valid['output'] === '', 'trusted port echo contained on success');

echo "ONEPAGE RECEIPT PREFLIGHT R26 PASS: {$checksR26} synthetic checks; NO-GO.\n";
