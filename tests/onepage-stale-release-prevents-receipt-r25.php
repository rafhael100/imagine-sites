<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Reuse the admitted, versioned synthetic order and its private served HTML.
// No new buyer, contract writer, official source or publication authority.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR25 = 0;
$assertR25 = static function (bool $condition, string $label) use (&$checksR25): void {
    if (!$condition) throw new RuntimeException('ONEPAGE STALE HEAD R25 FAILED: '.$label);
    ++$checksR25;
};

$runR25 = static function (string $driftField) use ($initial, $brief): array {
    $reads = ['release'=>0, 'acceptance'=>0, 'presentation'=>0];
    $presentationObserved = false;
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static fn(): array => $initial['record'],
        static fn(): array => $initial['head'],
        static fn(): array => $initial['confirmation'],
        static fn(): array => $initial['review'],
    );
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use ($initial, $driftField, &$reads, &$presentationObserved): array {
            ++$reads['release'];
            $head = $initial['release'];
            if ($presentationObserved && $reads['release'] === 2) {
                if ($driftField === 'artifactEpoch') {
                    $head['artifactEpoch'] += 1;
                } elseif ($driftField === 'htmlSha256') {
                    $head['htmlSha256'] = str_repeat('a', 64);
                } else {
                    $head['releaseRef'] = 'synthetic-superseding-release';
                }
            }
            return $head;
        },
        static function () use ($initial, &$reads): array {
            ++$reads['acceptance'];
            // A second private receipt read is a privacy regression, even if
            // the gate ultimately denies it and contains any echoed bytes.
            if ($reads['acceptance'] > 1) {
                echo 'SYNTHETIC_RECEIPT_R25_SHOULD_NOT_BE_READ';
                throw new RuntimeException('R25_FORBIDDEN_SECOND_RECEIPT');
            }
            return $initial['acceptance'];
        },
        static function () use ($initial, &$reads, &$presentationObserved): array {
            ++$reads['presentation'];
            $presentationObserved = true;
            return $initial['presentation'];
        },
    );

    $initialLevel = ob_get_level();
    ob_start();
    $result = null;
    $error = null;
    try {
        $result = $gate->observe(
            'buyer-one', $brief->tenantRef, $brief->orderRef,
            $brief->siteRef, $brief->sourceVersion,
        );
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        $output = ob_get_clean();
        if (ob_get_level() !== $initialLevel) {
            throw new RuntimeException('R25 output buffer level drift');
        }
    }
    return compact('result', 'error', 'output', 'reads', 'presentationObserved');
};

// When the first served HTML has been observed and the release changes,
// do not consult private buyer receipts for the superseding release.
foreach (['releaseRef', 'artifactEpoch', 'htmlSha256'] as $field) {
    $probe = $runR25($field);
    $assertR25($probe['presentationObserved'] === true, 'initial synthetic HTML served: '.$field);
    $assertR25($probe['result'] === null &&
        $probe['error'] instanceof RuntimeException &&
        $probe['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $probe['error']->getPrevious() === null,
        'uniform fail-closed denial: '.$field);
    $assertR25($probe['reads'] === [
        'release'=>2, 'acceptance'=>1, 'presentation'=>1,
    ], 'superseded release must not trigger another private receipt read: '.$field);
    $assertR25($probe['output'] === '', 'no confidential bytes escaped: '.$field);
}

echo "ONEPAGE STALE HEAD R25 PASS: {$checksR25} synthetic checks; NO-GO.\n";
