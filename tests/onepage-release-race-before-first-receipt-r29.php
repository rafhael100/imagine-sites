<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Existing, previously admitted synthetic OnePage order and versioned brief.
// No operational identity, writer, API, database, publication or new authority.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR29 = 0;
$checkR29 = static function (bool $condition, string $label) use (&$checksR29): void {
    if (!$condition) throw new RuntimeException('ONEPAGE RELEASE-RECEIPT R29 FAILED: '.$label);
    ++$checksR29;
};

$probeR29 = static function (string $change) use ($initial, $brief): array {
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
        static function () use (&$state, &$reads, $change): array {
            ++$reads['release'];
            // The first private release lookup may observe a concurrent revoke.
            // The release value itself remains unchanged, intentionally.
            if ($reads['release'] === 1) {
                if ($change === 'rights') $state['confirmation']['rightsDeclared'] = false;
                if ($change === 'review') $state['review']['approved'] = false;
                if ($change === 'source') $state['record']['export']['sourceBytes'] .= 'SYNTHETIC_R29_EDIT';
            }
            echo 'SYNTHETIC_RELEASE_PORT_R29';
            return $state['release'];
        },
        static function () use (&$state, &$reads): array {
            ++$reads['receipt'];
            echo 'SYNTHETIC_CONFIDENTIAL_RECEIPT_R29';
            return $state['acceptance'];
        },
        static function () use (&$state, &$reads): array {
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
            'buyer-one', $brief->tenantRef, $brief->orderRef,
            $brief->siteRef, $brief->sourceVersion,
        );
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $output = ob_get_clean();
        if (ob_get_level() !== $level) throw new RuntimeException('R29 output buffer drift');
    }
    return compact('accepted','error','output','reads');
};

foreach (['rights','review','source'] as $change) {
    $result = $probeR29($change);
    $checkR29($result['accepted'] === null &&
        $result['error'] instanceof RuntimeException &&
        $result['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $result['error']->getPrevious() === null, 'uniform denial after first release: '.$change);
    $checkR29($result['reads'] === [
        'release'=>1,'receipt'=>0,'presentation'=>0,
    ], 'no confidential receipt or HTML read after release-side revocation: '.$change);
    $checkR29($result['output'] === '', 'no output leaked: '.$change);
}
$positive = $probeR29('');
$checkR29($positive['error'] === null && is_array($positive['accepted']), 'unchanged synthetic approval accepted');
$checkR29($positive['reads'] === ['release'=>2,'receipt'=>2,'presentation'=>2], 'independent existing reads kept');
$checkR29($positive['accepted']['clientFinalAccepted'] === true &&
    $positive['accepted']['canPublish'] === false &&
    $positive['accepted']['publicationAuthorized'] === false, 'final acceptance is not publishing');
$checkR29($positive['output'] === '', 'positive no accidental output');
echo "ONEPAGE RELEASE BEFORE RECEIPT R29 PASS: {$checksR29} synthetic checks; NO-GO.\n";
