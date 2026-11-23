<?php
declare(strict_types=1);

use Imagine\Sites\Production\OnepageBuyerPrivatePreviewCandidate;

// One admitted fictional order and its existing renderer; no official Portal
// authority, real buyer identity or production access is asserted here.
require __DIR__.'/onepage-buyer-private-preview-offline.php';

$marker = 'SYNTHETIC_PRIVATE_OUTPUT_R20_DO_NOT_EXPOSE';
$r20 = 0;
$assertR20 = static function (bool $condition, string $label) use (&$r20): void {
    if (!$condition) throw new RuntimeException('ONEPAGE R20: '.$label);
    ++$r20;
};
$observeR20 = static function (callable $action): array {
    ob_start();
    $response = null;
    $error = null;
    try { $response = $action(); }
    catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    return [$response, $error, $output];
};
$deniedR20 = static function (array $result, string $label) use ($assertR20): void {
    [$response, $error, $output] = $result;
    $assertR20($response === null && $error instanceof RuntimeException
        && $error->getMessage() === 'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED'
        && $error->getPrevious() === null && $output === '',
        $label.': deny without output or internal details');
};

// Positive: a trusted source must be read only for data, never allowed to
// print even a synthetic private-content marker to the HTTP response.
$reads = 0;
$noisySuccess = new OnepageBuyerPrivatePreviewCandidate(
    static function () use ($record, $marker, &$reads): array {
        ++$reads;
        echo $marker;
        return $record;
    },
    static fn(): array => $head,
    static fn(): array => $confirmation,
    static fn(): array => $approval,
);
[$preview, $error, $output] = $observeR20(static fn() => $call($noisySuccess));
$assertR20($error === null && $output === '' && $reads === 2
    && is_array($preview) && $preview['html'] === $artifact->html
    && hash_equals($preview['sha256'], hash('sha256', $preview['html']))
    && $preview['clientFinalAccepted'] === false
    && $preview['publicationAuthorized'] === false,
    'positive preview returns exact HTML only, without echo from sources');

// Negative: a malformed source must not leak bytes it printed immediately
// before the renderer rejects its stale/invalid content.
$invalid = $record;
$invalid['buyerRef'] = 'other-buyer';
$noisyPrivateDeny = new OnepageBuyerPrivatePreviewCandidate(
    static function () use ($invalid, $marker): array {
        echo $marker;
        return $invalid;
    },
    static fn(): array => $head,
    static fn(): array => $confirmation,
    static fn(): array => $approval,
);
$deniedR20($observeR20(static fn() => $call($noisyPrivateDeny)),
    'private source cannot leak before refusal');

// Negative: buyer witness is consulted before private bytes, but its own
// accidental debug output must also be suppressed on an unauthorized request.
$privateReads = 0;
$noisyWrongBuyer = new OnepageBuyerPrivatePreviewCandidate(
    static function () use ($record, &$privateReads): array {
        ++$privateReads;
        return $record;
    },
    static fn(): array => $head,
    static function () use ($confirmation, $marker): array {
        echo $marker;
        return array_replace($confirmation, ['buyerRef' => 'other-buyer']);
    },
    static fn(): array => $approval,
);
$deniedR20($observeR20(static fn() => $call($noisyWrongBuyer)),
    'wrong buyer witness cannot echo private material');
$assertR20($privateReads === 0,
    'buyer refusal still occurs before any private-byte read');

// Negative: a revocation between reads must be denied, even if the live
// reviewer port accidentally prints a synthetic marker on every invocation.
$reviews = 0;
$noisyRevocation = new OnepageBuyerPrivatePreviewCandidate(
    static fn(): array => $record,
    static fn(): array => $head,
    static fn(): array => $confirmation,
    static function () use (&$reviews, $approval, $marker): array {
        echo $marker;
        return ++$reviews === 1 ? $approval : array_replace($approval, ['approved' => false]);
    },
);
$deniedR20($observeR20(static fn() => $call($noisyRevocation)),
    'revoked reviewer must not echo before final denial');
$assertR20($reviews === 2,
    'pre-second-read revalidation keeps existing revocation semantics');

echo "ONEPAGE PRIVATE OUTPUT R20 PASS: {$r20} checks; synthetic ports only; NO-GO.\n";
