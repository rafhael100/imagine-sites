<?php

declare(strict_types=1);

use App\Integration\CurrentPortalOnepageHeadSource;
use App\Integration\StoredOnepageReviewCurrentHeadGate;
use App\Integration\StoredOnepageReviewSupersession;

// Test-only adapter, never installed in a runtime service. The real Portal
// current-head authority and approvals are NOT implemented by this fixture.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_ONEPAGE_CURRENT_PORTAL_HEAD_CI') !== '1' ||
    !isset($suProof, $suService, $suPrevious, $suOld, $suOldPlan,
        $suNext, $suNextPlan, $suForeign, $suNewPacket, $suOldPacket,
        $suOk, $suNoWrite, $bapEditorial)) {
    throw new RuntimeException('Current Portal head proof needs the isolated PG18 laboratory');
}

$chStart = $suChecks;
// The parent fixture has already decoded the exact current review packet.
$chPacket = $suNewPacket;
$chHead = [
    'tenantRef' => $chPacket['tenantRef'],
    'orderRef' => $chPacket['orderRef'],
    'siteRef' => $chPacket['siteRef'],
    'contractRef' => $chPacket['contractRef'],
    'contractRevision' => $chPacket['contractRevision'],
    'productCode' => 'onepage',
    'snapshotRef' => $chPacket['snapshotRef'],
    'briefRevision' => $chPacket['briefRevision'],
    'sourceVersion' => $chPacket['sourceVersion'],
    'manifestSha256' => $chPacket['manifestSha256'],
    'payloadSha256' => $chPacket['sourceSha256'],
];
$chSource = static fn(array $sequence): CurrentPortalOnepageHeadSource =>
    new class($sequence) implements CurrentPortalOnepageHeadSource {
        public int $readCount = 0;
        public function __construct(public array $sequence) {}
        public function readCurrent(string $tenantRef, string $orderRef, string $siteRef): ?array
        {
            $entry = $this->sequence[min($this->readCount++, count($this->sequence) - 1)] ?? null;
            if ($entry === null || $entry['tenantRef'] !== $tenantRef ||
                $entry['orderRef'] !== $orderRef || $entry['siteRef'] !== $siteRef) {
                return null;
            }
            return $entry;
        }
    };
$chVerify = static function (
    CurrentPortalOnepageHeadSource $source,
    ?array $claim = null,
    ?array $nextExport = null,
    ?string $nextPlan = null,
    ?StoredOnepageReviewSupersession $supersession = null,
) use ($suService, $suProof, $suPrevious, $suOld, $suOldPlan,
    $suNext, $suNextPlan, $bapEditorial): void {
    (new StoredOnepageReviewCurrentHeadGate($supersession ?? $suService, $source))
        ->assertSelectedCurrent(
            $claim ?? $suProof, $suPrevious, $suOld, $suOldPlan, $bapEditorial,
            $nextExport ?? $suNext, $nextPlan ?? $suNextPlan, $bapEditorial,
        );
};
$chDeny = static function (callable $action, string $description) use ($suOk): void {
    $refused = false;
    ob_start();
    try {
        $action();
    } catch (RuntimeException $error) {
        $refused = $error->getMessage() === 'ONEPAGE_CURRENT_PORTAL_HEAD_DENIED' &&
            $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $suOk($refused && $output === '', $description.' refuses without disclosure');
};

// A supplied latest-head *fixture* can authorize only this read-only gate,
// never publication. Two independent reads must agree around PG verification.
$chStable = $chSource([$chHead]);
$chVerify($chStable);
$suOk($chStable->readCount === 2, 'exact current synthetic head double-read');
$chVerify($chStable);
$suOk($chStable->readCount === 4, 'read-only replay repeats head validation');
$suNoWrite('synthetic current-head readback');

$chDeny(static fn() => $chVerify($chSource([null])),
    'unavailable official head (default-deny equivalent)');
$chOld = $chHead;
$chOld['snapshotRef'] = $suOldPacket['snapshotRef'];
$chOld['briefRevision'] = $suOldPacket['briefRevision'];
$chOld['sourceVersion'] = $suOldPacket['sourceVersion'];
$chOld['manifestSha256'] = $suOldPacket['manifestSha256'];
$chOld['payloadSha256'] = $suOldPacket['sourceSha256'];
$chDeny(static fn() => $chVerify($chSource([$chOld])),
    'prior revision selected as latest');
$chNewer = $chHead;
$chNewer['briefRevision']++;
$chDeny(static fn() => $chVerify($chSource([$chNewer])),
    'newer current Portal revision invalidates selected review');
$chWrong = $chHead;
$chWrong['tenantRef'] = 'other-company';
$chDeny(static fn() => $chVerify($chSource([$chWrong])),
    'other tenant claims current head');
$chWrong = $chHead;
$chWrong['contractRevision']++;
$chDeny(static fn() => $chVerify($chSource([$chWrong])),
    'contract revision no longer current');
$chWrong = $chHead;
$chWrong['sourceVersion']++;
$chDeny(static fn() => $chVerify($chSource([$chWrong])),
    'changed source version');
$chWrong = $chHead;
$chWrong['payloadSha256'] = str_repeat('0', 64);
$chDeny(static fn() => $chVerify($chSource([$chWrong])),
    'source bytes digest drift');
$chWrong = $chHead;
$chWrong['canPublish'] = true;
$chDeny(static fn() => $chVerify($chSource([$chWrong])),
    'extra fabricated publication authority');
$chWrong = $chHead;
$chWrong['briefRevision'] = (string) $chWrong['briefRevision'];
$chDeny(static fn() => $chVerify($chSource([$chWrong])),
    'numeric revision string coercion');
$suNoWrite('prior, newer, foreign and tampered head');

$chDeny(static fn() => $chVerify($chSource([$chHead, null])),
    'revocation between the two readbacks');
$chDeny(static fn() => $chVerify($chSource([$chHead, $chNewer])),
    'concurrent change to a newer official head');
$chDeny(static fn() => $chVerify($chSource([$chHead]), supersession: $suForeign),
    'foreign tenant PostgreSQL readback despite matching Portal head');
$chSpoofed = $suProof;
$chSpoofed['supersessionSha256'] = str_repeat('a', 64);
$chDeny(static fn() => $chVerify($chSource([$chHead]), claim: $chSpoofed),
    'rehashed/untrusted supersession cannot bypass SQL readback');
$chDeny(static fn() => $chVerify($chSource([$chHead]), nextPlan: $suOldPlan),
    'stale rendering plan cannot bypass exact revision');
$suNoWrite('races, SQL tenant principal and supersession tampering');

$chVerify($chSource([$chHead]));
$suOk(true, 'valid synthetic current head still passes after all refusals');
$suNoWrite('final current-head replay');

echo 'ONEPAGE CURRENT PORTAL HEAD PG18 PASS: '.($suChecks-$chStart).
    " checks; source is a lab double, no authenticated Portal authority or publication.\n";
