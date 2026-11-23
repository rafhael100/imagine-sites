<?php
declare(strict_types=1);

use Imagine\Sites\Production\{OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

// Existing admitted synthetic OnePage order and exact artifact; no real buyer or writer.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('ONEPAGE SECOND RECEIPT R31 FAILED: '.$label);
    ++$checks;
};
$probe = static function (string $drift) use ($initial, $brief): array {
    $state = $initial;
    $reads = ['release'=>0,'receipt'=>0,'presentation'=>0];
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static function () use (&$state): array { return $state['record']; },
        static function () use (&$state): array { return $state['head']; },
        static function () use (&$state): array { return $state['confirmation']; },
        static function () use (&$state): array { return $state['review']; },
    );
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use (&$state, &$reads): array {
            ++$reads['release'];
            echo 'SYNTHETIC_PRIVATE_RELEASE_R31';
            return $state['release'];
        },
        static function () use (&$state, &$reads, $drift): array {
            ++$reads['receipt'];
            // A change after the SECOND receipt is invisible to the old two-head check.
            if ($reads['receipt'] === 2) {
                if ($drift === 'release') $state['release']['releaseRef'] = 'synthetic-release-replaced-r31';
                if ($drift === 'epoch') ++$state['release']['artifactEpoch'];
                if ($drift === 'digest') $state['release']['htmlSha256'] = str_repeat('a', 64);
                if ($drift === 'approval') $state['release']['approved'] = false;
            }
            echo 'SYNTHETIC_PRIVATE_RECEIPT_R31';
            return $state['acceptance'];
        },
        static function () use (&$state, &$reads): array {
            ++$reads['presentation'];
            echo 'SYNTHETIC_PRIVATE_HTML_R31';
            return $state['presentation'];
        },
    );
    $level = ob_get_level();
    ob_start();
    $accepted = null;
    $error = null;
    try {
        $accepted = $gate->observe('buyer-one', $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion);
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $output = ob_get_clean();
        if (ob_get_level() !== $level) throw new RuntimeException('R31 output level drift');
    }
    return compact('accepted','error','output','reads');
};
foreach (['release','epoch','digest','approval'] as $drift) {
    $p = $probe($drift);
    $assert($p['accepted'] === null && $p['error'] instanceof RuntimeException &&
        $p['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $p['error']->getPrevious() === null, 'uniform denied '.$drift);
    $assert($p['reads'] === ['release'=>3,'receipt'=>2,'presentation'=>1], 'no second private HTML '.$drift);
    $assert($p['output'] === '', 'no incidental private output '.$drift);
}
$p = $probe('');
$assert($p['error'] === null && is_array($p['accepted']), 'valid exact buyer');
$assert($p['reads'] === ['release'=>4,'receipt'=>2,'presentation'=>2], 'four current release observations');
$assert($p['accepted']['clientFinalAccepted'] === true &&
    $p['accepted']['privatePreview'] === true &&
    $p['accepted']['publicationAuthorized'] === false &&
    $p['accepted']['canPublish'] === false, 'final acceptance not publication');
$assert($p['output'] === '', 'positive no leaked output');
echo "ONEPAGE SECOND RECEIPT RELEASE R31 PASS: {$checks} synthetic checks; NO-GO.\n";
