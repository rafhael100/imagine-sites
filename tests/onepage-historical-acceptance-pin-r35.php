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
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('R35 historical pin: '.$label);
    ++$checks;
};
$probe = static function (string $scenario, bool $strict = true, bool $configurePin = false) use ($base, $pin, $brief, $makePreview, $json): array {
    $s = $base; $p = $pin;
    $counts = ['release'=>0,'receipt'=>0,'presentation'=>0,'pin'=>0];
    if ($scenario === 'rotated-rights') {
        $s['confirmation']['confirmationRef'] = 'rights-new-r35';
        $s['review']['buyerConfirmationRef'] = 'rights-new-r35';
        $s['review']['approvalRef'] = 'brief-review-new-r35';
    }
    if ($scenario === 'replaced-plan') {
        $plan = json_decode($s['record']['plan'], true, 16, JSON_THROW_ON_ERROR);
        $plan['operationId'] = 'op-render-new-r35';
        $s['record']['plan'] = $json($plan);
    }
    if ($scenario === 'other-tenant') $p['tenantRef'] = 'other-tenant-r35';
    if ($scenario === 'other-order') $p['orderRef'] = 'other-order-r35';
    if ($scenario === 'missing-digest') unset($p['renderHandoffSha256']);
    if ($scenario === 'other-buyer') $p['buyerRef'] = 'buyer-two';
    if ($scenario === 'other-presentation') $p['presentationRef'] = 'presentation-new';
    if ($scenario === 'other-epoch') $p['artifactEpoch']++;
    if ($scenario === 'extra-authority') $p['canPublish'] = true;
    if ($scenario === 'bad-hash') $p['proofDigest'] = str_repeat('0',64);
    if ($scenario === 'reordered') $p = array_reverse($p, true);
    $port = static function (string $tenant, string $order, string $site, string $buyer, string $acceptance)
        use (&$counts, $p, $scenario, $base): ?array {
        ++$counts['pin'];
        if ([$tenant,$order,$site,$buyer,$acceptance] !== [
            $base['release']['tenantRef'],$base['release']['orderRef'],$base['release']['siteRef'],
            $base['acceptance']['buyerRef'],$base['acceptance']['acceptanceRef']
        ]) throw new RuntimeException('unexpected selector');
        if ($scenario === 'missing-pin' || ($scenario === 'pin-revoked' && $counts['pin'] === 2)) return null;
        if ($scenario === 'pin-replaced' && $counts['pin'] === 2) return array_replace($p,['renderHandoffSha256'=>str_repeat('f',64)]);
        if ($scenario === 'exception') { echo 'synthetic-private-marker'; throw new RuntimeException('private'); }
        return $p;
    };
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $makePreview($s),
        static function () use (&$s,&$counts): array { ++$counts['release']; return $s['release']; },
        static function () use (&$s,&$counts): array { ++$counts['receipt']; return $s['acceptance']; },
        static function () use (&$s,&$counts): array { ++$counts['presentation']; return $s['presentation']; },
        $scenario === 'no-port' || (!$strict && !$configurePin) ? null : $port,
    );
    $result = null; $error = null;
    ob_start();
    try {
        $args = ['buyer-one',$brief->tenantRef,$brief->orderRef,$brief->siteRef,$brief->sourceVersion];
        $result = $strict ? $gate->observeHistoricallyBound(...$args) : $gate->observe(...$args);
    } catch (Throwable $caught) { $error = $caught; }
    finally { $output = ob_get_clean(); }
    return compact('result','error','output','counts');
};
// Reproduce the pre-existing gap without pretending that the old observer
// is a historical proof: stable current witnesses can already be different
// from the time of the original click.
foreach (['rotated-rights','replaced-plan'] as $scenario) {
    $legacy = $probe($scenario,false);
    $check($legacy['error'] === null && $legacy['result']['clientFinalAccepted'] === true,
        'legacy observation lacks historical pin '.$scenario);
}
foreach (['no-port','missing-pin','rotated-rights','replaced-plan','other-buyer',
    'other-presentation','other-epoch','other-tenant','other-order','missing-digest','extra-authority','bad-hash','exception','pin-revoked','pin-replaced'] as $scenario) {
    $r = $probe($scenario);
    $check($r['result'] === null && $r['error'] instanceof RuntimeException &&
        $r['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $r['error']->getPrevious() === null, 'uniform deny '.$scenario);
    $expected = in_array($scenario,['pin-revoked','pin-replaced'],true) ? 1 : 0;
    $check($r['counts']['presentation'] === $expected, 'stop private presentation '.$scenario);
    $check($r['output'] === '', 'no output '.$scenario);
    if ($scenario === 'no-port') $check(array_sum($r['counts']) === 0,'no port before private reads');
}
foreach (['valid','reordered'] as $scenario) {
    $r = $probe($scenario);
    $check($r['error'] === null && $r['result']['clientFinalAccepted'] === true &&
        $r['result']['canPublish'] === false && $r['result']['publicationAuthorized'] === false &&
        $r['result']['finalAcceptanceRef'] === 'final-accept-a', 'pinned control '.$scenario);
    $check($r['counts']['pin'] === 2 && $r['counts']['presentation'] === 2,'independent pin reread '.$scenario);
    $check(count($r['result']) === 10 && !array_key_exists('proofDigest',$r['result']) &&
        !array_key_exists('renderHandoffSha256',$r['result']), 'no public shape change '.$scenario);
}
$configured = $probe('rotated-rights',false,true);
$check($configured['error'] instanceof RuntimeException &&
    $configured['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
    $configured['counts']['presentation'] === 0, 'configured pin cannot be bypassed by old method');
echo "ONEPAGE R35 PASS: {$checks} synthetic checks; NO-GO.\n";
