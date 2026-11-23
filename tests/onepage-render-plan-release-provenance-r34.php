<?php
declare(strict_types=1);

use Imagine\Sites\Production\{OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

// Use one already-admitted synthetic order, exact existing renderer and plan.
// Align the original plan to the same release as the independently read head.
$planAligned = json_decode($initial['record']['plan'], true, 16, JSON_THROW_ON_ERROR);
$planAligned['releaseRef'] = $initial['release']['releaseRef'];
$initial['record']['plan'] = $json($planAligned);

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('R34 render provenance: '.$label);
    ++$checks;
};
$probe = static function (string $scenario) use ($initial, $brief, $json): array {
    $s = $initial;
    $counts = ['release'=>0,'receipt'=>0,'presentation'=>0];
    $editPlan = static function (string $field, string $value) use (&$s, $json): void {
        $p = json_decode($s['record']['plan'], true, 16, JSON_THROW_ON_ERROR);
        $p[$field] = $value;
        $s['record']['plan'] = $json($p);
    };
    if ($scenario === 'other-plan') $editPlan('releaseRef', 'other-render-release-r34');
    if ($scenario === 'other-head') {
        foreach (['release','acceptance','presentation'] as $port) {
            $s[$port]['releaseRef'] = 'other-read-release-r34';
        }
    }
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static function () use (&$s): array { return $s['record']; },
        static function () use (&$s): array { return $s['head']; },
        static function () use (&$s): array { return $s['confirmation']; },
        static function () use (&$s): array { return $s['review']; },
    );
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use (&$s, &$counts, $scenario, $editPlan): array {
            if (++$counts['release'] === 1 && $scenario === 'same-ref-new-plan') {
                $editPlan('operationId', 'op-render-r34-replaced');
            }
            if ($counts['release'] === 1 && $scenario === 'new-plan-release') {
                $editPlan('releaseRef', 'other-render-release-r34');
            }
            return $s['release'];
        },
        static function () use (&$s, &$counts): array { ++$counts['receipt']; return $s['acceptance']; },
        static function () use (&$s, &$counts): array { ++$counts['presentation']; return $s['presentation']; },
    );
    $error = null; $result = null;
    ob_start();
    try {
        $result = $gate->observe('buyer-one', $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion);
    } catch (Throwable $caught) { $error = $caught; }
    finally { $output = ob_get_clean(); }
    return compact('result','error','counts','output');
};
foreach (['other-plan','other-head','same-ref-new-plan','new-plan-release'] as $scenario) {
    $p = $probe($scenario);
    $check($p['result'] === null && $p['error'] instanceof RuntimeException &&
        $p['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $p['error']->getPrevious() === null, 'deny '.$scenario);
    $check($p['counts'] === ['release'=>1,'receipt'=>0,'presentation'=>0], 'stop confidential reads '.$scenario);
    $check($p['output'] === '', 'no output '.$scenario);
}
$p = $probe('');
$check($p['error'] === null && $p['result']['clientFinalAccepted'] === true &&
    $p['result']['releaseRef'] === 'release-a' && $p['result']['canPublish'] === false &&
    $p['result']['publicationAuthorized'] === false, 'exact release control');
$check($p['counts'] === ['release'=>4,'receipt'=>2,'presentation'=>2], 'no added source reads');
$check(!array_key_exists('renderHandoffSha256', $p['result']) &&
    !array_key_exists('renderReleaseRef', $p['result']), 'no internal metadata in acceptance');
$preview = new OnepageBuyerPrivatePreviewCandidate(
    static fn()=>$initial['record'], static fn()=>$initial['head'],
    static fn()=>$initial['confirmation'], static fn()=>$initial['review'],
);
$view = $preview->render('buyer-one',$brief->tenantRef,$brief->orderRef,$brief->siteRef,$brief->sourceVersion);
$check(count($view) === 8 && !array_key_exists('renderHandoffSha256',$view) &&
    !array_key_exists('renderReleaseRef',$view) && $view['clientFinalAccepted'] === false,
    'public preview shape unchanged');
echo "ONEPAGE R34 PASS: {$checks} synthetic checks; NO-GO.\n";
