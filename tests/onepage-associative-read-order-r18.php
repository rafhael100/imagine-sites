<?php
declare(strict_types=1);

use Imagine\Sites\Production\OnepageBuyerPrivatePreviewCandidate;

// Reuse the admitted synthetic order and original renderer, without making
// these fixture witnesses an official Portal approval or buyer session.
require __DIR__.'/onepage-buyer-private-preview-offline.php';

$r18 = 0;
$assertR18 = static function (bool $ok, string $case) use (&$r18): void {
    if (!$ok) throw new RuntimeException('ONEPAGE R18 field-order: '.$case);
    ++$r18;
};
$expectedPreview = static function (array $value) use ($artifact): bool {
    return $value['html'] === $artifact->html
        && hash_equals($artifact->digest, $value['sha256'])
        && $value['privatePreview'] === true
        && $value['clientFinalAccepted'] === false
        && $value['publicationAuthorized'] === false
        && $value['canPublish'] === false;
};
$denyR18 = static function (callable $callback) use ($assertR18): void {
    $denied = false;
    ob_start();
    try { $callback(); }
    catch (RuntimeException $error) {
        $denied = $error->getMessage() === 'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED'
            && $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $assertR18($denied && $output === '', 'uniform denial without output');
};

// Different PHP associative insertion order must not change immutable
// field/byte identity. Canonical JSON strings and SHA-256 are NOT normalized.
$reordered = $original;
$reordered['head'] = array_reverse($reordered['head'], true);
$assertR18($expectedPreview($call($create($reordered))),
    'head fields reordered without changing values');

// A fresh current-head read may come from a separate adapter connection and
// return identical fields in a different order.
$headReads = 0;
$rotatingHead = new OnepageBuyerPrivatePreviewCandidate(
    static fn(): array => $record,
    static function () use (&$headReads, $head): array {
        ++$headReads;
        return $headReads === 1 ? $head : array_reverse($head, true);
    },
    static fn(): array => $confirmation,
    static fn(): array => $approval,
);
$assertR18($expectedPreview($call($rotatingHead)) && $headReads === 3,
    'three current-head checks with identical typed fields');

// Separately serialized buyer and reviewer receipts can also have a different
// map insertion order; actual receipt references and typed values stay exact.
$buyerReads = 0;
$reviewReads = 0;
$rotatingWitnesses = new OnepageBuyerPrivatePreviewCandidate(
    static fn(): array => $record,
    static fn(): array => $head,
    static function () use (&$buyerReads, $confirmation): array {
        ++$buyerReads;
        return $buyerReads === 1 ? $confirmation : array_reverse($confirmation, true);
    },
    static function () use (&$reviewReads, $approval): array {
        ++$reviewReads;
        return $reviewReads === 1 ? $approval : array_reverse($approval, true);
    },
);
$assertR18($expectedPreview($call($rotatingWitnesses))
    && $buyerReads === 3 && $reviewReads === 3,
    'three typed witness checks with identical rights and approvals');

// Reconstructed record wrappers may change PHP key order between independent
// reads, while the manifest and source byte strings themselves stay identical.
$privateReads = 0;
$rotatingRecord = new OnepageBuyerPrivatePreviewCandidate(
    static function () use (&$privateReads, $record): array {
        ++$privateReads;
        if ($privateReads === 1) return $record;
        $fresh = array_reverse($record, true);
        $fresh['export'] = array_reverse($fresh['export'], true);
        return $fresh;
    },
    static fn(): array => $head,
    static fn(): array => $confirmation,
    static fn(): array => $approval,
);
$assertR18($expectedPreview($call($rotatingRecord)) && $privateReads === 2,
    'exact source bytes survive wrapper insertion-order change');

// Field ORDER is irrelevant, but changed typed FIELD VALUES are never equal.
// A changed revision is rejected despite the second head being reordered.
$changedHeadReads = 0;
$changedHead = new OnepageBuyerPrivatePreviewCandidate(
    static fn(): array => $record,
    static function () use (&$changedHeadReads, $head): array {
        ++$changedHeadReads;
        if ($changedHeadReads === 1) return $head;
        $changed = array_reverse($head, true);
        ++$changed['briefRevision'];
        return $changed;
    },
    static fn(): array => $confirmation,
    static fn(): array => $approval,
);
$denyR18(static fn() => $call($changedHead));
$assertR18($changedHeadReads === 2, 'a changed revision is detected on fresh head');

echo "ONEPAGE ASSOCIATIVE FIELD ORDER R18 PASS: {$r18} checks; same synthetic order; NO-GO.\n";
