<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// R30 uses the existing admitted synthetic OnePage order and exact reviewed
// artifact. No operational identity, persistence, endpoint, API, or publication.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR30 = 0;
$checkR30 = static function (bool $ok, string $label) use (&$checksR30): void {
    if (!$ok) throw new RuntimeException('ONEPAGE RECEIPT-PRESENTATION R30 FAILED: '.$label);
    ++$checksR30;
};

$probeR30 = static function (string $change) use ($initial, $brief): array {
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
            return $state['release'];
        },
        static function () use (&$state, &$reads, $change): array {
            ++$reads['receipt'];
            // A once-valid exact receipt may be read concurrently with
            // a revocation/edit while remaining byte-identical itself.
            if ($reads['receipt'] === 1) {
                if ($change === 'rights') $state['confirmation']['rightsDeclared'] = false;
                if ($change === 'review') $state['review']['approved'] = false;
                if ($change === 'source') $state['record']['export']['sourceBytes'] .= 'SYNTHETIC_R30_EDIT';
            }
            echo 'SYNTHETIC_PRIVATE_FIRST_RECEIPT_R30';
            return $state['acceptance'];
        },
        static function () use (&$state, &$reads): array {
            ++$reads['presentation'];
            echo 'SYNTHETIC_PRIVATE_PRESENTATION_R30';
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
        if ($level !== ob_get_level()) throw new RuntimeException('R30 buffer level drift');
    }
    return compact('accepted','error','output','reads');
};

foreach (['rights','review','source'] as $change) {
    $probe = $probeR30($change);
    $checkR30($probe['accepted'] === null &&
        $probe['error'] instanceof RuntimeException &&
        $probe['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $probe['error']->getPrevious() === null, 'uniform denial after first receipt '.$change);
    $checkR30($probe['reads'] === [
        'release'=>1,'receipt'=>1,'presentation'=>0,
    ], 'no confidential HTML presentation read after receipt-side revocation '.$change);
    $checkR30($probe['output'] === '', 'no accidental private output '.$change);
}
$valid = $probeR30('');
$checkR30($valid['error'] === null && is_array($valid['accepted']), 'same buyer current version accepted');
$checkR30($valid['reads'] === ['release'=>3,'receipt'=>2,'presentation'=>2], 'existing independent rereads kept');
$checkR30($valid['accepted']['clientFinalAccepted'] === true &&
    $valid['accepted']['canPublish'] === false &&
    $valid['accepted']['publicationAuthorized'] === false, 'acceptance not publication');
$checkR30($valid['output'] === '', 'positive no private output');
echo "ONEPAGE RECEIPT BEFORE HTML R30 PASS: {$checksR30} synthetic checks; NO-GO.\n";
