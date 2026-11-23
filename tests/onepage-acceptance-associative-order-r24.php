<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Exactly the admitted synthetic order/source/HTML of R04/R23.
// No additional commercial authority or operational port is constructed.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR24 = 0;
$assertR24 = static function (bool $valid, string $label) use (&$checksR24): void {
    if (!$valid) throw new RuntimeException('ONEPAGE ACCEPTANCE ORDER R24 FAILED: '.$label);
    ++$checksR24;
};
$observeR24 = static function (string $shufflePort, bool $changeReceipt = false)
    use ($initial, $brief): array {
    $reads = ['release'=>0, 'acceptance'=>0, 'presentation'=>0];
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static fn(): array => $initial['record'],
        static fn(): array => $initial['head'],
        static fn(): array => $initial['confirmation'],
        static fn(): array => $initial['review'],
    );
    $gate = new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use ($initial, $shufflePort, &$reads): array {
            ++$reads['release'];
            return $shufflePort === 'release' && $reads['release'] === 2
                ? array_reverse($initial['release'], true) : $initial['release'];
        },
        static function () use ($initial, $shufflePort, $changeReceipt, &$reads): array {
            ++$reads['acceptance'];
            $receipt = $initial['acceptance'];
            if ($changeReceipt && $reads['acceptance'] === 2) {
                $receipt['acceptanceRef'] = 'final-accept-changed';
            }
            return $shufflePort === 'acceptance' && $reads['acceptance'] === 2
                ? array_reverse($receipt, true) : $receipt;
        },
        static function () use ($initial, $shufflePort, &$reads): array {
            ++$reads['presentation'];
            return $shufflePort === 'presentation' && $reads['presentation'] === 2
                ? array_reverse($initial['presentation'], true) : $initial['presentation'];
        },
    );
    $level = ob_get_level();
    ob_start();
    $result = null;
    $error = null;
    try {
        $result = $gate->observe(
            'buyer-one', $brief->tenantRef, $brief->orderRef,
            $brief->siteRef, $brief->sourceVersion,
        );
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $output = ob_get_clean();
        if (ob_get_level() !== $level) throw new RuntimeException('R24 output buffer drift');
    }
    return ['result'=>$result, 'error'=>$error, 'output'=>$output, 'reads'=>$reads];
};

// Release, buyer-click receipt and presentation are independently reloaded
// associative maps: their insertion order carries no semantic authority.
// Exact field names, types, values, HTML bytes and SHA still do.
foreach (['release', 'acceptance', 'presentation'] as $port) {
    $probe = $observeR24($port);
    $assertR24($probe['error'] === null && is_array($probe['result']) &&
        ($probe['result']['htmlSha256'] ?? null) === $originalArtifact->digest &&
        ($probe['result']['finalAcceptanceRef'] ?? null) === 'final-accept-a' &&
        ($probe['result']['clientFinalAccepted'] ?? null) === true &&
        ($probe['result']['publicationAuthorized'] ?? null) === false &&
        ($probe['result']['canPublish'] ?? null) === false,
        'same typed fields must pass when order changes in '.$port);
    $assertR24($probe['output'] === '' &&
        $probe['reads'] === ['release'=>2,'acceptance'=>2,'presentation'=>2],
        'two independent reads and no output for '.$port);
}

// Value changes remain a hard denial even if the second map has reordered
// fields; no extra private HTML read follows an invalid current click.
$drift = $observeR24('acceptance', true);
$assertR24($drift['result'] === null &&
    $drift['error'] instanceof RuntimeException &&
    $drift['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
    $drift['error']->getPrevious() === null && $drift['output'] === '',
    'changed acceptance ref still denied with exact uniform error');
$assertR24($drift['reads']['presentation'] === 1,
    'changed acceptance ref prevents second private HTML observation');

echo "ONEPAGE ACCEPTANCE ORDER R24 PASS: {$checksR24} synthetic checks; NO-GO.\n";
