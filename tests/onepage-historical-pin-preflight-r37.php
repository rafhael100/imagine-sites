<?php
declare(strict_types=1);

use Imagine\Sites\Production\{OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

// Historical pin is a synthetic record captured from the initial exact
// preview, not a writer, a signature, an authenticated session or Portal truth.
$makePreview = static function (array &$s): OnepageBuyerPrivatePreviewCandidate {
    return new OnepageBuyerPrivatePreviewCandidate(
        static function () use (&$s): array { return $s['record']; },
        static function () use (&$s): array { return $s['head']; },
        static function () use (&$s): array { return $s['confirmation']; },
        static function () use (&$s): array { return $s['review']; },
    );
};
$base = $initial;
$original = $makePreview($base)->observeWithWitness('buyer-one', $brief->tenantRef,
    $brief->orderRef, $brief->siteRef, $brief->sourceVersion);
$pin = $base['acceptance'];
unset($pin['approved'], $pin['explicitClick'], $pin['revoked']);
$pin['proofDigest'] = $original['proofDigest'];
$pin['renderHandoffSha256'] = $original['renderHandoffSha256'];

$checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('R37 '.$label);
    ++$checks;
};
foreach (['receipt-rights-1','receipt-rights-2','receipt-head-1','receipt-head-2',
          'pin-rights-1','pin-rights-2','pin-head-1','pin-head-2','valid'] as $scenario) {
    $s = $base;
    $counts = ['receipt'=>0,'pin'=>0,'presentation'=>0];
    $change = static function(string $port, int $n) use (&$s,$scenario): void {
        if ($scenario === $port.'-rights-'.$n) {
            $s['confirmation']['confirmationRef']='rights-replaced-r37';
            $s['review']['buyerConfirmationRef']='rights-replaced-r37';
            $s['review']['approvalRef']='review-replaced-r37';
        }
        if ($scenario === $port.'-head-'.$n) $s['release']['releaseRef']='release-replaced-r37';
    };
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $makePreview($s),
        static function() use (&$s):array{return $s['release'];},
        static function() use (&$s,&$counts,$change):array {
            $receipt=$s['acceptance'];
            $change('receipt',++$counts['receipt']);
            return $receipt;
        },
        static function() use (&$s,&$counts):array{++$counts['presentation'];return $s['presentation'];},
        static function() use ($pin,&$counts,$change):array {
            $change('pin',++$counts['pin']);
            return $pin;
        },
    );
    $result=null;$error=null;
    ob_start();
    try {$result=$gate->observeHistoricallyBound('buyer-one',$brief->tenantRef,$brief->orderRef,$brief->siteRef,$brief->sourceVersion);}
    catch(Throwable $e){$error=$e;}
    finally {$output=ob_get_clean();}
    $check($output==='','no private output '.$scenario);
    if ($scenario==='valid') {
        $check($error===null && $result['clientFinalAccepted']===true && $result['canPublish']===false &&
            $result['publicationAuthorized']===false,'valid synthetic control');
        $check($counts===['receipt'=>2,'pin'=>2,'presentation'=>2],'two exact independent readings');
        continue;
    }
    $n=(int)substr($scenario,-1);
    $check($result===null && $error instanceof RuntimeException &&
        $error->getMessage()==='ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' && $error->getPrevious()===null,
        'uniform denial '.$scenario);
    $check($counts['pin']===(str_starts_with($scenario,'receipt-')?$n-1:$n),
        'no new confidential pin after receipt changed current authority '.$scenario);
    $check($counts['presentation']===$n-1,'no HTML after observed authority change '.$scenario);
}
echo "ONEPAGE R37 PASS: {$checks} synthetic checks; NO-GO.\n";
