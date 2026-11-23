<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

// Reuse the ONE admitted synthetic order and existing renderer/receipt fixtures.
// Do not execute the already-green final-acceptance suite (#604).
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checksR21 = 0;
$assertR21 = static function (bool $valid, string $label) use (&$checksR21): void {
    if (!$valid) throw new RuntimeException('ONEPAGE ACCEPTANCE R21 FAILED: '.$label);
    ++$checksR21;
};
$buildR21 = static function (array $state, int &$presentationReads): OnepageExactFinalAcceptanceReadCandidate {
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static fn(): array => $state['record'],
        static fn(): array => $state['head'],
        static fn(): array => $state['confirmation'],
        static fn(): array => $state['review'],
    );
    return new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static fn(): array => $state['release'],
        static fn(): array => $state['acceptance'],
        static function () use ($state, &$presentationReads): array {
            ++$presentationReads;
            return $state['presentation'];
        },
    );
};
$observeR21 = static fn(OnepageExactFinalAcceptanceReadCandidate $gate): array =>
    $gate->observe('buyer-one', $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion);
$denyR21 = static function (OnepageExactFinalAcceptanceReadCandidate $gate) use ($observeR21, $assertR21): void {
    $error = null;
    try { $observeR21($gate); } catch (RuntimeException $caught) { $error = $caught; }
    $assertR21($error instanceof RuntimeException &&
        $error->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' &&
        $error->getPrevious() === null, 'uniform denial without private details');
};

// Revocation of the BRIEF reviewer (not the release record) must deny BEFORE
// consulting independently stored servedHtmlBytes. The old implementation
// already refused the result, but unnecessarily read private presentation bytes.
$revoked = $initial;
$revoked['review']['approved'] = false;
$readsRevoked = 0;
$denyR21($buildR21($revoked, $readsRevoked));
$assertR21($readsRevoked === 0,
    'revoked briefing must produce ZERO reads of private presentation bytes');

// Even a current release/acceptance cannot authorize presentation read if
// the original immutable manifest/source is no longer exact.
$corrupt = $initial;
$corrupt['record']['export']['sourceBytes'] .= 'SYNTHETIC_TAMPER_R21';
$readsCorrupt = 0;
$denyR21($buildR21($corrupt, $readsCorrupt));
$assertR21($readsCorrupt === 0,
    'briefing digest divergence must deny before presentation source read');

// Positive control: same product, order and exact synthetic bytes still
// exercise two independent presentation reads, without publication grant.
$readsValid = 0;
$accepted = $observeR21($buildR21($initial, $readsValid));
$assertR21($readsValid === 2, 'valid exact acceptance still rereads presentation twice');
$assertR21($accepted['htmlSha256'] === $originalArtifact->digest &&
    $accepted['clientFinalAccepted'] === true &&
    $accepted['publicationAuthorized'] === false &&
    $accepted['canPublish'] === false, 'accepted only, never publication');

echo "ONEPAGE ACCEPTANCE PRESENTATION R21 PASS: {$checksR21} synthetic checks; NO-GO.\n";
