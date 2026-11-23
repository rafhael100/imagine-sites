<?php
declare(strict_types=1);

use Imagine\Sites\Production\OnepageBuyerPrivatePreviewCandidate;

// R19: one admitted synthetic OnePage order; never operational Portal authority.
require __DIR__.'/onepage-buyer-private-preview-offline.php';

$r19Checks = 0;
$assertR19 = static function (bool $condition, string $label) use (&$r19Checks): void {
    if (!$condition) throw new RuntimeException('ONEPAGE R19: '.$label);
    ++$r19Checks;
};
$deniedR19 = static function (callable $action) use ($assertR19): void {
    ob_start();
    $denied = false;
    try { $action(); }
    catch (RuntimeException $exception) {
        $denied = $exception->getMessage() === 'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED'
            && $exception->getPrevious() === null;
    }
    $bytes = ob_get_clean();
    $assertR19($denied && $bytes === '', 'fail closed without content or exception detail');
};

// Once the first private source read observes a revocation, the second
// sensitive read MUST NOT occur. A final denial does not undo that read.
foreach (['review-revoked', 'buyer-revoked', 'head-superseded'] as $scenario) {
    $current = $original;
    $reads = ['private' => 0, 'head' => 0, 'buyer' => 0, 'reviewer' => 0];
    $candidate = new OnepageBuyerPrivatePreviewCandidate(
        static function () use (&$reads, &$current, $scenario): array {
            ++$reads['private'];
            if ($reads['private'] === 1) {
                if ($scenario === 'review-revoked') $current['approval']['approved'] = false;
                if ($scenario === 'buyer-revoked') $current['confirmation']['confirmed'] = false;
                if ($scenario === 'head-superseded') ++$current['head']['briefRevision'];
            }
            return $current['record'];
        },
        static function () use (&$reads, &$current): array {
            ++$reads['head'];
            return $current['head'];
        },
        static function () use (&$reads, &$current): array {
            ++$reads['buyer'];
            return $current['confirmation'];
        },
        static function () use (&$reads, &$current): array {
            ++$reads['reviewer'];
            return $current['approval'];
        },
    );
    $deniedR19(static fn() => $call($candidate));
    $assertR19($reads['private'] === 1 && $reads['head'] === 2,
        $scenario.': no second private read after current head/proofs changed');
}

// If revocation occurs DURING the second read, it must still be observed
// after that read, before any private HTML is released to the caller.
$current = $original;
$reads = ['private' => 0, 'head' => 0, 'buyer' => 0, 'reviewer' => 0];
$secondReadRevocation = new OnepageBuyerPrivatePreviewCandidate(
    static function () use (&$reads, &$current): array {
        ++$reads['private'];
        if ($reads['private'] === 2) $current['approval']['approved'] = false;
        return $current['record'];
    },
    static function () use (&$reads, &$current): array {
        ++$reads['head']; return $current['head'];
    },
    static function () use (&$reads, &$current): array {
        ++$reads['buyer']; return $current['confirmation'];
    },
    static function () use (&$reads, &$current): array {
        ++$reads['reviewer']; return $current['approval'];
    },
);
$deniedR19(static fn() => $call($secondReadRevocation));
$assertR19($reads['private'] === 2 && $reads['reviewer'] === 3,
    'revocation during second private read denied by final current approval check');

// All unchanged synthetic evidence keeps the same exact HTML digest; the
// extra pre-read proof pass does not promote acceptance/publication.
$current = $original;
$reads = ['private' => 0, 'head' => 0, 'buyer' => 0, 'reviewer' => 0];
$stable = new OnepageBuyerPrivatePreviewCandidate(
    static function () use (&$reads, &$current): array {
        ++$reads['private']; return $current['record'];
    },
    static function () use (&$reads, &$current): array {
        ++$reads['head']; return $current['head'];
    },
    static function () use (&$reads, &$current): array {
        ++$reads['buyer']; return $current['confirmation'];
    },
    static function () use (&$reads, &$current): array {
        ++$reads['reviewer']; return $current['approval'];
    },
);
$html = $call($stable);
$assertR19($reads === ['private' => 2, 'head' => 3, 'buyer' => 3, 'reviewer' => 3],
    'positive path uses two private reads with fresh proofs before and after second read');
$assertR19($html['html'] === $artifact->html &&
    hash_equals($artifact->digest, $html['sha256']) &&
    $html['privatePreview'] === true &&
    $html['clientFinalAccepted'] === false &&
    $html['publicationAuthorized'] === false && $html['canPublish'] === false,
    'unchanged HTML, source SHA and publication gates');

echo "ONEPAGE PRIVATE RECHECK R19 PASS: {$r19Checks} checks; synthetic ports only; NO-GO.\n";
