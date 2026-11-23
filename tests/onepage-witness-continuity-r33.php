<?php
declare(strict_types=1);

use Imagine\Sites\Production\{OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('R33 witness continuity: '.$label);
    ++$checks;
};
$probe = static function (string $at, string $kind) use ($initial, $brief): array {
    $state = $initial;
    $counts = ['release'=>0,'receipt'=>0,'presentation'=>0];
    $rotate = static function (string $port) use (&$state, &$counts, $at, $kind): void {
        ++$counts[$port];
        if ($at !== $port.$counts[$port]) return;
        if ($kind === 'rights') {
            $state['confirmation']['confirmationRef'] = 'buyer-rights-r33-new';
            $state['review']['buyerConfirmationRef'] = 'buyer-rights-r33-new';
        }
        $state['review']['approvalRef'] = 'brief-review-r33-new';
    };
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static function () use (&$state): array { return $state['record']; },
        static function () use (&$state): array { return $state['head']; },
        static function () use (&$state): array { return $state['confirmation']; },
        static function () use (&$state): array { return $state['review']; },
    );
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use (&$state, $rotate): array { $rotate('release'); return $state['release']; },
        static function () use (&$state, $rotate): array { $rotate('receipt'); return $state['acceptance']; },
        static function () use (&$state, $rotate): array { $rotate('presentation'); return $state['presentation']; },
    );
    $error = null; $result = null;
    ob_start();
    try {
        $result = $gate->observe('buyer-one', $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion);
    } catch (Throwable $caught) { $error = $caught; }
    finally { $output = ob_get_clean(); }
    return compact('result','error','counts','output');
};
foreach (['release1'=>[0,0], 'receipt1'=>[1,0], 'presentation1'=>[1,1],
          'receipt2'=>[2,1], 'presentation2'=>[2,2]] as $at=>$max) {
    foreach (['rights','review'] as $kind) {
        $p = $probe($at,$kind);
        $check($p['result'] === null && $p['error'] instanceof RuntimeException &&
            $p['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
            $p['error']->getPrevious() === null, 'coherent witness rotation '.$at.' '.$kind);
        $check([$p['counts']['receipt'],$p['counts']['presentation']] === $max, 'stop private reads '.$at.' '.$kind);
        $check($p['output'] === '', 'no output '.$at.' '.$kind);
    }
}
$p = $probe('', '');
$check($p['error'] === null && $p['result']['clientFinalAccepted'] === true &&
    $p['result']['canPublish'] === false && $p['result']['publicationAuthorized'] === false,
    'unchanged witness positive; no publication');
$check(!array_key_exists('proofDigest', $p['result']), 'internal fingerprint not returned');
$preview = new OnepageBuyerPrivatePreviewCandidate(
    static fn()=>$initial['record'], static fn()=>$initial['head'],
    static fn()=>$initial['confirmation'], static fn()=>$initial['review'],
);
$view = $preview->render('buyer-one',$brief->tenantRef,$brief->orderRef,$brief->siteRef,$brief->sourceVersion);
$check(count($view) === 8 && !array_key_exists('proofDigest',$view) &&
    $view['clientFinalAccepted'] === false && $view['canPublish'] === false,
    'public preview response unchanged');
echo "ONEPAGE R33 PASS: {$checks} synthetic checks; NO-GO.\n";
