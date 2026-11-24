<?php
declare(strict_types=1);

use Imagine\Sites\Production\{OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

// Same synthetic order, exact initial proof, and actual R35/R37 observer.
// No source of buyer identity or operational acceptance is introduced.
$makePreview = static function (array &$s): OnepageBuyerPrivatePreviewCandidate {
    return new OnepageBuyerPrivatePreviewCandidate(
        static function () use (&$s): array { return $s['record']; },
        static function () use (&$s): array { return $s['head']; },
        static function () use (&$s): array { return $s['confirmation']; },
        static function () use (&$s): array { return $s['review']; },
    );
};
$base = $initial;
$args = ['buyer-one', $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion];
$original = $makePreview($base)->observeWithWitness(...$args);
$pin = $base['acceptance'];
unset($pin['approved'], $pin['explicitClick'], $pin['revoked']);
$pin['proofDigest'] = $original['proofDigest'];
$pin['renderHandoffSha256'] = $original['renderHandoffSha256'];
$checks = 0;
$check = static function(bool $ok,string $case) use (&$checks): void {
    if (!$ok) throw new RuntimeException('R40 '.$case);
    ++$checks;
};

foreach (['revoked-click','withdrawn-click','new-click','new-presentation','absent-click',
          'final-read-rights','final-read-head','valid'] as $scenario) {
    $s = $base;
    $reads = ['receipt'=>0,'pin'=>0,'presentation'=>0];
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $makePreview($s),
        static function () use (&$s): array { return $s['release']; },
        static function () use (&$s,&$reads,$scenario): ?array {
            ++$reads['receipt'];
            $receipt = $s['acceptance'];
            if ($reads['receipt'] === 3) {
                if ($scenario === 'final-read-rights') {
                    $s['confirmation']['confirmationRef']='rights-rotated-r40';
                    $s['review']['buyerConfirmationRef']='rights-rotated-r40';
                    $s['review']['approvalRef']='approval-rotated-r40';
                }
                if ($scenario === 'final-read-head') $s['release']['releaseRef']='release-rotated-r40';
            }
            return $receipt;
        },
        static function () use (&$s,&$reads,$scenario): array {
            $presentation = $s['presentation'];
            if (++$reads['presentation'] === 2) {
                switch ($scenario) {
                    case 'revoked-click': $s['acceptance']['revoked']=true; break;
                    case 'withdrawn-click': $s['acceptance']['explicitClick']=false; break;
                    case 'new-click': $s['acceptance']['acceptanceRef']='replacement-click-r40'; break;
                    case 'new-presentation': $s['acceptance']['presentationRef']='replacement-presentation-r40'; break;
                    case 'absent-click': $s['acceptance']=null; break;
                }
            }
            return $presentation;
        },
        static function () use ($pin,&$reads): array { ++$reads['pin']; return $pin; },
    );
    $result=null; $error=null;
    ob_start();
    try { $result=$gate->observeHistoricallyBound(...$args); }
    catch (Throwable $e) { $error=$e; }
    finally { $output=ob_get_clean(); }
    if ($scenario==='valid') {
        $check($error===null && $result['clientFinalAccepted']===true &&
            $result['finalAcceptanceRef']===$base['acceptance']['acceptanceRef'] &&
            $result['canPublish']===false && $result['publicationAuthorized']===false,'exact control');
    } else {
        $check($result===null && $error instanceof RuntimeException &&
            $error->getMessage()==='ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
            $error->getPrevious()===null,'must deny '.$scenario);
    }
    $check($output==='','no private output '.$scenario);
    $check($reads===['receipt'=>3,'pin'=>2,'presentation'=>2],
        'fresh exact receipt; no additional pin/HTML disclosure '.$scenario);
}
echo "ONEPAGE R40 FINAL RECEIPT PASS: {$checks} synthetic checks; NO-GO.\n";
