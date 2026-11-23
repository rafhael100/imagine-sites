<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Reuse the previously admitted synthetic OnePage order, version, bytes and
// renderer. This file does not create a contract, reviewer or runtime port.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR23 = 0;
$okR23 = static function (bool $condition, string $label) use (&$checksR23): void {
    if (!$condition) throw new RuntimeException('ONEPAGE ACCEPTANCE PORT OUTPUT R23 FAILED: '.$label);
    ++$checksR23;
};
$markerR23 = 'SYNTHETIC_R23_PORT_OUTPUT_MUST_NOT_LEAK';
$makeR23 = static function (array $state, string $emittingPort, bool $refuse = false) use ($markerR23):
    OnepageExactFinalAcceptanceReadCandidate {
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static fn(): array => $state['record'],
        static fn(): array => $state['head'],
        static fn(): array => $state['confirmation'],
        static fn(): array => $state['review'],
    );
    return new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use ($state, $emittingPort, $markerR23): array {
            if ($emittingPort === 'release') echo $markerR23;
            return $state['release'];
        },
        static function () use ($state, $emittingPort, $markerR23): array {
            if ($emittingPort === 'acceptance') echo $markerR23;
            return $state['acceptance'];
        },
        static function () use ($state, $emittingPort, $markerR23, $refuse): array {
            if ($emittingPort === 'presentation') echo $markerR23;
            $record = $state['presentation'];
            if ($refuse) $record['observed'] = false;
            return $record;
        },
    );
};
$observeR23 = static function (OnepageExactFinalAcceptanceReadCandidate $candidate) use ($brief): array {
    $outputLevel = ob_get_level();
    ob_start();
    $result = null;
    $error = null;
    try {
        $result = $candidate->observe(
            'buyer-one', $brief->tenantRef, $brief->orderRef,
            $brief->siteRef, $brief->sourceVersion,
        );
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $output = ob_get_clean();
        if (ob_get_level() !== $outputLevel) throw new RuntimeException('R23 probe buffer drift');
    }
    return ['result'=>$result, 'error'=>$error, 'output'=>$output];
};

// A trusted but mistakenly echoing read port must not contaminate an HTTP
// response, including the successful branch. Actual private bytes are never
// printed in the test: marker and all input records are synthetic.
foreach (['release', 'acceptance', 'presentation'] as $port) {
    $probe = $observeR23($makeR23($initial, $port));
    $okR23($probe['error'] === null && is_array($probe['result']) &&
        ($probe['result']['htmlSha256'] ?? null) === $originalArtifact->digest &&
        ($probe['result']['clientFinalAccepted'] ?? null) === true &&
        ($probe['result']['publicationAuthorized'] ?? null) === false,
        'valid exact receipt unaffected by accidental output in '.$port);
    $okR23($probe['output'] === '', 'no leaked output on success in '.$port);
}

// The same trusted adapter may echo before returning an invalid witnessed
// presentation. A final denial is insufficient if output already escaped.
$denied = $observeR23($makeR23($initial, 'presentation', true));
$okR23($denied['result'] === null &&
    $denied['error'] instanceof RuntimeException &&
    $denied['error']->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
    $denied['error']->getPrevious() === null,
    'invalid observed presentation uniformly denied');
$okR23($denied['output'] === '', 'no private port echo on denied acceptance');

echo "ONEPAGE ACCEPTANCE PORT OUTPUT R23 PASS: {$checksR23} synthetic checks; NO-GO.\n";
