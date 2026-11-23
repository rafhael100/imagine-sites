<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Same already-admitted synthetic order, approved briefing, private HTML and
// final-click witness as R03/R26. No native login, writer, release or publication.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR27 = 0;
$checkR27 = static function (bool $valid, string $name) use (&$checksR27): void {
    if (!$valid) throw new RuntimeException('ONEPAGE SECOND RECEIPT R27 FAILED: '.$name);
    ++$checksR27;
};

$runR27 = static function (string $when = '', string $change = '') use ($initial, $brief): array {
    $state = $initial;
    $reads = ['release' => 0, 'receipt' => 0, 'presentation' => 0];
    $changeState = static function () use (&$state, $change): void {
        if ($change === 'rights') $state['confirmation']['rightsDeclared'] = false;
        if ($change === 'review') $state['review']['approved'] = false;
        if ($change === 'brief-bytes') $state['record']['export']['sourceBytes'] .= 'SYNTHETIC_R27_EDIT';
    };
    // Unlike arrow functions capturing arrays by VALUE, these ports read the
    // latest mutable synthetic state, modeling observed revocation between reads.
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
        static function () use (&$state, &$reads, $when, $changeState): array {
            ++$reads['receipt'];
            if ($when === 'during-second-receipt' && $reads['receipt'] === 2) $changeState();
            // The marker is fictitious and must never reach HTTP output.
            echo 'SYNTHETIC_PRIVATE_RECEIPT_R27';
            return $state['acceptance'];
        },
        static function () use (&$state, &$reads, $when, $changeState): array {
            ++$reads['presentation'];
            if ($when === 'during-first-presentation' && $reads['presentation'] === 1) $changeState();
            return $state['presentation'];
        },
    );
    $baseOutputLevel = ob_get_level();
    ob_start();
    $accepted = null;
    $error = null;
    try {
        $accepted = $gate->observe(
            'buyer-one', $brief->tenantRef, $brief->orderRef,
            $brief->siteRef, $brief->sourceVersion,
        );
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        $output = ob_get_clean();
        if (ob_get_level() !== $baseOutputLevel) {
            throw new RuntimeException('R27 output buffer drift');
        }
    }
    return compact('accepted','error','output','reads');
};

$valid = $runR27();
$checkR27($valid['error'] === null && is_array($valid['accepted']), 'unchanged exact receipt accepted');
$checkR27($valid['reads'] === [
    'release' => 2, 'receipt' => 2, 'presentation' => 2,
], 'positive independently rereads release, receipt and HTML');
$checkR27($valid['accepted']['clientFinalAccepted'] === true &&
    $valid['accepted']['privatePreview'] === true &&
    $valid['accepted']['canPublish'] === false &&
    $valid['accepted']['publicationAuthorized'] === false, 'acceptance does not grant publication');
$checkR27($valid['output'] === '', 'positive no private output');

foreach (['rights', 'review', 'brief-bytes'] as $change) {
    $result = $runR27('during-first-presentation', $change);
    $checkR27($result['accepted'] === null &&
        $result['error'] instanceof RuntimeException &&
        $result['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $result['error']->getPrevious() === null, 'uniform rejection after first presentation: '.$change);
    // Key regression: release can stay current, but the confidential SECOND
    // receipt must NOT be fetched after buyer rights or editorial proof drift.
    $checkR27($result['reads'] === [
        'release' => 2, 'receipt' => 1, 'presentation' => 1,
    ], 'no second private receipt after changed proof: '.$change);
    $checkR27($result['output'] === '', 'no private output after changed proof: '.$change);
}

// Already-accepted second receipt may itself cause revocation; preserve the
// existing R22 guarantee that no second private HTML read occurs afterward.
$late = $runR27('during-second-receipt', 'review');
$checkR27($late['accepted'] === null &&
    $late['error'] instanceof RuntimeException &&
    $late['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED',
    'revocation during second receipt still denied');
$checkR27($late['reads'] === [
    'release' => 2, 'receipt' => 2, 'presentation' => 1,
], 'no second HTML after second-receipt revocation');
$checkR27($late['output'] === '', 'late revocation no private output');

echo "ONEPAGE SECOND RECEIPT RIGHTS R27 PASS: {$checksR27} synthetic checks; NO-GO.\n";
