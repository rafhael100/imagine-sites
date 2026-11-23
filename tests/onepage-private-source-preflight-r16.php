<?php
declare(strict_types=1);

use Imagine\Sites\Production\OnepageBuyerPrivatePreviewCandidate;

// Reuse the original private preview test's synthetic order and renderer.
// This is NOT a buyer login, Portal approval source, or operational preview.
require __DIR__.'/onepage-buyer-private-preview-offline.php';

$r16Checks = 0;
$checkR16 = static function (bool $result, string $label) use (&$r16Checks): void {
    if (!$result) throw new RuntimeException('ONEPAGE R16 preflight: '.$label);
    ++$r16Checks;
};
$expectDenied = static function (callable $action, string $label) use ($checkR16): void {
    ob_start();
    $denied = false;
    try { $action(); }
    catch (RuntimeException $exception) {
        $denied = $exception->getMessage() === 'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED'
            && $exception->getPrevious() === null;
    }
    $output = ob_get_clean();
    $checkR16($denied && $output === '', $label);
};
$makeObserved = static function (array $fixture, array &$reads): OnepageBuyerPrivatePreviewCandidate {
    return new OnepageBuyerPrivatePreviewCandidate(
        static function () use ($fixture, &$reads): array {
            ++$reads['private']; return $fixture['record'];
        },
        static function () use ($fixture, &$reads): array {
            ++$reads['head']; return $fixture['head'];
        },
        static function () use ($fixture, &$reads): array {
            ++$reads['buyer']; return $fixture['confirmation'];
        },
        static function () use ($fixture, &$reads): array {
            ++$reads['reviewer']; return $fixture['approval'];
        },
    );
};
$emptyReads = static fn(): array => ['private'=>0, 'head'=>0, 'buyer'=>0, 'reviewer'=>0];

// Wrong buyer is in the SAME tenant/order. A final 403/denied result alone
// cannot erase a private read that already happened before authorization.
$reads = $emptyReads();
$guard = $makeObserved($original, $reads);
$expectDenied(static fn()=>$call($guard, viewer:'other-buyer'), 'same-tenant wrong buyer denied');
$checkR16($reads === ['private'=>0, 'head'=>1, 'buyer'=>1, 'reviewer'=>0],
    'other buyer causes ZERO private reads and ZERO reviewer probes');

// Wrong claims must not query any private briefing or human witness.
foreach (['tenant', 'order', 'site', 'version'] as $field) {
    $reads = $emptyReads();
    $guard = $makeObserved($original, $reads);
    $action = match ($field) {
        'tenant' => static fn()=>$call($guard, tenant:'synthetic-foreign-org'),
        'order' => static fn()=>$call($guard, order:'synthetic-foreign-order'),
        'site' => static fn()=>$call($guard, site:'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
        'version' => static fn()=>$call($guard, version:$brief->sourceVersion + 1),
    };
    $expectDenied($action, $field.' mismatch denied');
    $checkR16($reads === ['private'=>0, 'head'=>1, 'buyer'=>0, 'reviewer'=>0],
        $field.' mismatch refused before reading private bytes or human witnesses');
}

// Missing rights declaration and revoked reviewer approval must also be
// observed BEFORE readExact, rather than only denying the eventual response.
$cases = [
    'rights-not-declared' => static function(array &$fixture): void {
        $fixture['confirmation']['rightsDeclared'] = false;
    },
    'buyer-receipt-revoked' => static function(array &$fixture): void {
        $fixture['confirmation']['confirmed'] = false;
    },
    'reviewer-revoked' => static function(array &$fixture): void {
        $fixture['approval']['approved'] = false;
    },
    'reviewer-wrong-confirmation' => static function(array &$fixture): void {
        $fixture['approval']['buyerConfirmationRef'] = 'older-confirmation';
    },
];
foreach ($cases as $label => $change) {
    $fixture = $original;
    $change($fixture);
    $reads = $emptyReads();
    $guard = $makeObserved($fixture, $reads);
    $expectDenied(static fn()=>$call($guard), $label.' denied');
    $checkR16($reads['private'] === 0 && $reads['head'] === 1
        && $reads['buyer'] === 1
        && $reads['reviewer'] === (str_starts_with($label,'reviewer-') ? 1 : 0),
        $label.' never reads private bytes');
}

// Positive fixture still uses exactly two independent reads of all four ports,
// preserves the original rendered digest, and does not create an acceptance.
$reads = $emptyReads();
$guard = $makeObserved($original, $reads);
$rendered = $call($guard);
$checkR16($reads === ['private'=>2,'head'=>2,'buyer'=>2,'reviewer'=>2],
    'positive path observes original and current sources twice, not three times');
$checkR16($rendered['html'] === $artifact->html &&
    hash_equals($rendered['sha256'], hash('sha256', $rendered['html'])) &&
    $rendered['privatePreview'] === true &&
    $rendered['clientFinalAccepted'] === false &&
    $rendered['publicationAuthorized'] === false &&
    $rendered['canPublish'] === false,
    'preflight does not alter renderer bytes, acceptance or publish gates');

echo "ONEPAGE PRIVATE SOURCE PREFLIGHT R16 PASS: {$r16Checks} checks; isolated mock ports; NO-GO.\n";
