<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Reuse the existing approved synthetic OnePage order, exact reviewed bytes,
// buyer and delivery witness. No operational buyer/session/DB is introduced.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR28 = 0;
$assertR28 = static function (bool $yes, string $description) use (&$checksR28): void {
    if (!$yes) throw new RuntimeException('ONEPAGE RELEASE PREFLIGHT R28 FAILED: '.$description);
    ++$checksR28;
};

$probeR28 = static function (array $state, string $viewer = 'buyer-one') use ($brief): array {
    $counts = ['release'=>0, 'receipt'=>0, 'presentation'=>0];
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static fn(): array => $state['record'],
        static fn(): array => $state['head'],
        static fn(): array => $state['confirmation'],
        static fn(): array => $state['review'],
    );
    $observer = new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use ($state, &$counts): array {
            ++$counts['release'];
            echo 'SYNTHETIC_PRIVATE_RELEASE_R28';
            return $state['release'];
        },
        static function () use ($state, &$counts): array {
            ++$counts['receipt'];
            return $state['acceptance'];
        },
        static function () use ($state, &$counts): array {
            ++$counts['presentation'];
            return $state['presentation'];
        },
    );
    $before = ob_get_level();
    ob_start();
    $result = null;
    $error = null;
    try {
        $result = $observer->observe(
            $viewer, $brief->tenantRef, $brief->orderRef,
            $brief->siteRef, $brief->sourceVersion,
        );
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $output = ob_get_clean();
        if ($before !== ob_get_level()) throw new RuntimeException('R28 output level changed');
    }
    return compact('result','error','output','counts');
};

$denied = [
    'other-buyer-same-company' => ['viewer'=>'buyer-two', 'change'=>null],
    'withdrawn-rights' => ['viewer'=>'buyer-one', 'change'=>static function (array &$s): void {
        $s['confirmation']['rightsDeclared'] = false;
    }],
    'revoked-editorial-approval' => ['viewer'=>'buyer-one', 'change'=>static function (array &$s): void {
        $s['review']['approved'] = false;
    }],
    'changed-approved-brief-bytes' => ['viewer'=>'buyer-one', 'change'=>static function (array &$s): void {
        $s['record']['export']['sourceBytes'] .= 'SYNTHETIC_R28_EDIT';
    }],
];
foreach ($denied as $name => $spec) {
    $state = $initial;
    if ($spec['change'] !== null) ($spec['change'])($state);
    $result = $probeR28($state, $spec['viewer']);
    $assertR28($result['result'] === null &&
        $result['error'] instanceof RuntimeException &&
        $result['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $result['error']->getPrevious() === null, 'uniform denial '.$name);
    // A private release read is not needed to discover that a buyer lacks
    // current briefing rights or that source/editorial proof is revoked.
    $assertR28($result['counts'] === ['release'=>0, 'receipt'=>0, 'presentation'=>0],
        'no confidential release/receipt/presentation reads '.$name);
    $assertR28($result['output'] === '', 'no incidental private HTTP output '.$name);
}
$positive = $probeR28($initial);
$assertR28($positive['error'] === null && is_array($positive['result']), 'current buyer accepted in synthetic test');
$assertR28($positive['counts'] === ['release'=>3,'receipt'=>2,'presentation'=>2],
    'valid buyer keeps independent release/receipt/presentation rereads');
$assertR28($positive['result']['privatePreview'] === true &&
    $positive['result']['clientFinalAccepted'] === true &&
    $positive['result']['canPublish'] === false &&
    $positive['result']['publicationAuthorized'] === false,
    'private acceptance cannot publish');
$assertR28($positive['output'] === '', 'valid buyer also receives no incidental output');

echo "ONEPAGE RELEASE PREFLIGHT R28 PASS: {$checksR28} synthetic checks; NO-GO.\n";
