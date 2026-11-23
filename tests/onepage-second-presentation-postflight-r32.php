<?php
declare(strict_types=1);

use Imagine\Sites\Production\{OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

// R32: same already-admitted synthetic order and exact reviewed HTML as R31.
// This is not a native login, authoritative buyer click, or publisher.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR32 = 0;
$assertR32 = static function (bool $ok, string $label) use (&$checksR32): void {
    if (!$ok) throw new RuntimeException('ONEPAGE SECOND PRESENTATION R32 FAILED: '.$label);
    ++$checksR32;
};
$probeR32 = static function (string $change, string $viewer = 'buyer-one') use ($initial, $brief): array {
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
            echo 'SYNTHETIC_PRIVATE_RELEASE_R32';
            return $state['release'];
        },
        static function () use (&$state, &$reads): array {
            ++$reads['receipt'];
            echo 'SYNTHETIC_PRIVATE_RECEIPT_R32';
            return $state['acceptance'];
        },
        static function () use (&$state, &$reads, $change): array {
            ++$reads['presentation'];
            // The SECOND presentation returns the exact old HTML, but its source
            // can concurrently withdraw rights, revoke approval or rotate head.
            if ($reads['presentation'] === 2) {
                if ($change === 'rights') $state['confirmation']['rightsDeclared'] = false;
                if ($change === 'review') $state['review']['approved'] = false;
                if ($change === 'source') $state['record']['export']['sourceBytes'] .= 'SYNTHETIC_EDIT_R32';
                if ($change === 'release') $state['release']['releaseRef'] = 'replaced-after-html-r32';
                if ($change === 'epoch') ++$state['release']['artifactEpoch'];
                if ($change === 'digest') $state['release']['htmlSha256'] = str_repeat('b', 64);
                if ($change === 'approval') $state['release']['approved'] = false;
            }
            echo 'SYNTHETIC_PRIVATE_HTML_R32';
            return $state['presentation'];
        },
    );
    $level = ob_get_level();
    ob_start();
    $accepted = null;
    $error = null;
    try {
        $accepted = $gate->observe(
            $viewer, $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion,
        );
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $output = ob_get_clean();
        if (ob_get_level() !== $level) throw new RuntimeException('R32 buffer level drift');
    }
    return compact('accepted','error','output','reads');
};
foreach (['rights','review','source','release','epoch','digest','approval'] as $change) {
    $result = $probeR32($change);
    $assertR32($result['accepted'] === null &&
        $result['error'] instanceof RuntimeException &&
        $result['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $result['error']->getPrevious() === null,
        'post-second-HTML uniform denial '.$change);
    $expectedReleaseReads = in_array($change, ['rights','review','source'], true) ? 3 : 4;
    $assertR32($result['reads'] === [
        'release'=>$expectedReleaseReads,'receipt'=>2,'presentation'=>2,
    ], 'postflight checks without third private receipt or HTML '.$change);
    $assertR32($result['output'] === '', 'no private output '.$change);
}
$valid = $probeR32('');
$assertR32($valid['error'] === null && is_array($valid['accepted']), 'unchanged exact buyer accepted');
$assertR32($valid['reads'] === ['release'=>4,'receipt'=>2,'presentation'=>2],
    'final source and release reread without extra confidential HTML');
$assertR32($valid['accepted']['clientFinalAccepted'] === true &&
    $valid['accepted']['privatePreview'] === true &&
    $valid['accepted']['publicationAuthorized'] === false &&
    $valid['accepted']['canPublish'] === false, 'acceptance never authorizes publication');
$assertR32($valid['output'] === '', 'valid path contains incidental output');
$other = $probeR32('', 'buyer-two');
$assertR32($other['accepted'] === null &&
    $other['error'] instanceof RuntimeException &&
    $other['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED',
    'other buyer same tenant denied');
$assertR32($other['reads'] === ['release'=>0,'receipt'=>0,'presentation'=>0],
    'no confidential reads for other buyer');
$assertR32($other['output'] === '', 'other buyer has no output');
echo "ONEPAGE SECOND PRESENTATION R32 PASS: {$checksR32} synthetic checks; NO-GO.\n";
