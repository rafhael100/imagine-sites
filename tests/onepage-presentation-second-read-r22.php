<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Keep the admitted single synthetic order and release; never construct a new
// operational writer or rerun the already-green fixture suite here.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR22 = 0;
$assertR22 = static function (bool $valid, string $name) use (&$checksR22): void {
    if (!$valid) throw new RuntimeException('ONEPAGE PRESENTATION R22 FAILED: '.$name);
    ++$checksR22;
};

$gateR22 = static function (
    array &$state, int &$presentationReads, ?Closure $afterFirstPresentation = null
): OnepageExactFinalAcceptanceReadCandidate {
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static function () use (&$state): array { return $state['record']; },
        static function () use (&$state): array { return $state['head']; },
        static function () use (&$state): array { return $state['confirmation']; },
        static function () use (&$state): array { return $state['review']; },
    );
    return new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static function () use (&$state): array { return $state['release']; },
        static function () use (&$state): array { return $state['acceptance']; },
        static function () use (&$state, &$presentationReads, $afterFirstPresentation): array {
            ++$presentationReads;
            $record = $state['presentation'];
            if ($presentationReads === 1 && $afterFirstPresentation !== null) {
                $afterFirstPresentation($state);
            }
            return $record;
        },
    );
};
$observeR22 = static fn(OnepageExactFinalAcceptanceReadCandidate $candidate): array =>
    $candidate->observe('buyer-one', $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion);
$denyR22 = static function (OnepageExactFinalAcceptanceReadCandidate $candidate) use ($observeR22, $assertR22): void {
    $error = null;
    ob_start();
    try { $observeR22($candidate); } catch (RuntimeException $caught) { $error = $caught; }
    $output = ob_get_clean();
    $assertR22($output === '' && $error instanceof RuntimeException &&
        $error->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $error->getPrevious() === null, 'uniform denial with no private output or exception');
};

// A reviewer revokes exactly the approved brief while the first independent
// served-HTML observation is being read. The second sensitive observation must
// not be consulted merely because the release and buyer click remain current.
$revokedR22 = $initial;
$revokedReadsR22 = 0;
$denyR22($gateR22(
    $revokedR22, $revokedReadsR22,
    static function (array &$s): void { $s['review']['approved'] = false; },
));
$assertR22($revokedReadsR22 === 1,
    'revoked brief after first presentation must prevent SECOND private presentation read');

// A replacement buyer rights declaration also invalidates the same reviewed
// version, even though the release, final click and HTML SHA remain unchanged.
$rightsR22 = $initial;
$rightsReadsR22 = 0;
$denyR22($gateR22(
    $rightsR22, $rightsReadsR22,
    static function (array &$s): void { $s['confirmation']['rightsDeclared'] = false; },
));
$assertR22($rightsReadsR22 === 1,
    'withdrawn rights after first presentation must prevent SECOND private presentation read');

// Positive control: unchanged authenticated-by-fixture inputs preserve two
// independent HTML reads and never grant publication.
$validR22 = $initial;
$validReadsR22 = 0;
$acceptedR22 = $observeR22($gateR22($validR22, $validReadsR22));
$assertR22($validReadsR22 === 2, 'valid candidate still observes exact HTML twice');
$assertR22($acceptedR22['htmlSha256'] === $originalArtifact->digest &&
    $acceptedR22['clientFinalAccepted'] === true &&
    $acceptedR22['publicationAuthorized'] === false &&
    $acceptedR22['canPublish'] === false, 'acceptance remains distinct from publication');

echo "ONEPAGE PRESENTATION R22 PASS: {$checksR22} synthetic checks; NO-GO.\n";
