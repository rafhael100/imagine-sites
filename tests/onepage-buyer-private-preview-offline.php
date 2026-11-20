<?php
declare(strict_types=1);

use Imagine\Sites\Production\{BriefSnapshotInput,LocalSiteRendering,OnepageBuyerPrivatePreviewCandidate};

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Imagine\\Sites\\Production\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root.'/src/Production/'.substr($class, strlen($prefix)).'.php';
        if (is_file($file)) require $file;
    }
});
$examples = $root.'/contracts/portal-sites/candidates/brief-production-v1/examples/';
$read = static function (string $file) use ($examples): string {
    $bytes = file_get_contents($examples.$file);
    if (!is_string($bytes)) throw new RuntimeException('Missing fixture');
    return $bytes;
};
$json = static fn(array $value): string => json_encode(
    $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";

$source = $read('onepage-brief.synthetic.json');
$editorial = $read('render-input.synthetic.json');
$manifest = json_decode($read('brief-snapshot.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$manifest['payload']['sizeBytes'] = strlen($source);
$manifest['payload']['sha256'] = hash('sha256', $source);
$manifestBytes = $json($manifest);
$plan = json_decode($read('brief-render-plan.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$plan['snapshot']['manifestSha256'] = hash('sha256', $manifestBytes);
$plan['renderInput']['sizeBytes'] = strlen($editorial);
$plan['renderInput']['sha256'] = hash('sha256', $editorial);
$artifact = LocalSiteRendering::render($editorial);
$plan['expectedArtifact'] = ['sha256' => $artifact->digest, 'sizeBytes' => $artifact->sizeBytes];
$export = [
    'manifestBytes' => $manifestBytes, 'sourceBytes' => $source,
    'manifestSha256' => hash('sha256', $manifestBytes),
    'payloadSha256' => hash('sha256', $source),
    'authorityVerified' => false, 'clientAccepted' => false,
    'canPublish' => false, 'canAcceptRealOrders' => false,
];
$record = ['export' => $export, 'plan' => $json($plan), 'editorial' => $editorial, 'buyerRef' => 'buyer-one'];
$brief = BriefSnapshotInput::fromJson($manifestBytes, $source);
$head = [
    'tenantRef' => $brief->tenantRef, 'orderRef' => $brief->orderRef,
    'siteRef' => $brief->siteRef, 'contractRef' => $brief->contractRef,
    'contractRevision' => $brief->contractRevision, 'productCode' => 'onepage',
    'snapshotRef' => $brief->snapshotRef, 'briefRevision' => $brief->briefRevision,
    'sourceVersion' => $brief->sourceVersion, 'manifestSha256' => $brief->manifestSha256,
    'payloadSha256' => $brief->payloadSha256,
];
$bound = $head; // full immutable head: product + contract and revision also bound
$confirmation = $bound + [
    'buyerRef' => 'buyer-one', 'confirmationRef' => 'confirm-001',
    'rightsDeclared' => true, 'confirmed' => true,
];
$approval = $bound + [
    'reviewerRef' => 'separate-authorized-reviewer', 'approvalRef' => 'approval-001',
    'buyerConfirmationRef' => $confirmation['confirmationRef'], 'approved' => true,
];
$original = compact('record', 'head', 'confirmation', 'approval');
$create = static function (array &$fixture): OnepageBuyerPrivatePreviewCandidate {
    return new OnepageBuyerPrivatePreviewCandidate(
        static function () use (&$fixture): ?array { return $fixture['record']; },
        static function () use (&$fixture): ?array { return $fixture['head']; },
        static function () use (&$fixture): ?array { return $fixture['confirmation']; },
        static function () use (&$fixture): ?array { return $fixture['approval']; },
    );
};
$call = static fn(OnepageBuyerPrivatePreviewCandidate $gate, ?string $viewer = null, ?string $tenant = null,
    ?string $order = null, ?string $site = null, ?int $version = null): array =>
    $gate->render($viewer ?? 'buyer-one', $tenant ?? $brief->tenantRef,
        $order ?? $brief->orderRef, $site ?? $brief->siteRef, $version ?? $brief->sourceVersion);
$checks = 0;
$ok = static function (bool $passed, string $case) use (&$checks): void {
    if (!$passed) throw new RuntimeException('Onepage private preview failed: '.$case);
    ++$checks;
};
$deny = static function (callable $fn, string $case) use ($ok): void {
    $denied = false;
    ob_start();
    try { $fn(); } catch (RuntimeException $exception) {
        $denied = $exception->getMessage() === 'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED' &&
            $exception->getPrevious() === null;
    }
    $output = ob_get_clean();
    $ok($denied && $output === '', $case);
};
$deny(static fn() => $call(new OnepageBuyerPrivatePreviewCandidate()), 'default denies without official ports');
$state = $original;
$gate = $create($state);
$preview = $call($gate);
$ok($preview['html'] === $artifact->html &&
    hash_equals($preview['sha256'], hash('sha256', $preview['html'])), 'exact renderer and hash');
$ok($preview['privatePreview'] === true && $preview['clientFinalAccepted'] === false &&
    $preview['publicationAuthorized'] === false && $preview['canPublish'] === false,
    'preview never grants client final acceptance or publication');
$ok($preview['sourceVersion'] === $brief->sourceVersion &&
    $preview['briefRevision'] === $brief->briefRevision, 'exact version proof');
$ok($preview === $call($gate), 'idempotent replay of unchanged synthetic evidence');
$deny(static fn() => $call($gate, viewer: 'other-buyer'), 'other buyer of same company');
$deny(static fn() => $call($gate, tenant: 'other-tenant'), 'cross tenant');
$deny(static fn() => $call($gate, order: 'other-order'), 'other order');
$deny(static fn() => $call($gate, site: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), 'other site');
$deny(static fn() => $call($gate, version: $brief->sourceVersion + 1), 'obsolete selected version');
$mutate = static function (callable $change, string $case) use ($original, $create, $call, $deny): void {
    $test = $original;
    $change($test);
    $deny(static fn() => $call($create($test)), $case);
};
$mutate(static function (array &$s): void { $s['confirmation']['buyerRef'] = 'other-buyer'; }, 'wrong buyer confirmation');
$mutate(static function (array &$s): void { $s['confirmation']['rightsDeclared'] = false; }, 'missing rights declaration');
$mutate(static function (array &$s): void { $s['confirmation']['confirmed'] = false; }, 'unconfirmed source');
$mutate(static function (array &$s): void { $s['confirmation']['sourceVersion']++; }, 'old buyer confirmation');
$mutate(static function (array &$s): void { $s['confirmation']['contractRef'] = 'other-contract'; }, 'buyer confirmation from another contract');
$mutate(static function (array &$s): void { $s['confirmation']['contractRevision']++; }, 'buyer confirmation on obsolete contract revision');
$mutate(static function (array &$s): void { $s['confirmation']['productCode'] = 'landing-page'; }, 'buyer confirmation from other product');
$mutate(static function (array &$s): void { unset($s['confirmation']['contractRef']); }, 'buyer confirmation missing contract provenance');
$mutate(static function (array &$s): void { $s['confirmation']['payloadSha256'] = str_repeat('f', 64); }, 'buyer hash mismatch');
$mutate(static function (array &$s): void { $s['approval']['approved'] = false; }, 'revoked approval');
$mutate(static function (array &$s): void { $s['approval']['reviewerRef'] = 'buyer-one'; }, 'self approval not independent');
$mutate(static function (array &$s): void { $s['approval']['sourceVersion']++; }, 'obsolete reviewer approval');
$mutate(static function (array &$s): void { unset($s['approval']['buyerConfirmationRef']); }, 'review approval missing buyer confirmation link');
$mutate(static function (array &$s): void { $s['approval']['buyerConfirmationRef'] = 'confirm-older'; }, 'review approval for earlier buyer confirmation');
$mutate(static function (array &$s): void { $s['approval']['buyerConfirmationRef'] = 'buyer-two'; }, 'review approval linked to unrelated confirmation');
$mutate(static function (array &$s): void { $s['confirmation']['confirmationRef'] = 'confirm-002'; }, 'buyer reconfirmed without independent reapproval');
$mutate(static function (array &$s): void { $s['approval']['contractRef'] = 'other-contract'; }, 'review approval from another contract');
$mutate(static function (array &$s): void { $s['approval']['contractRevision']++; }, 'review approval for obsolete contract revision');
$mutate(static function (array &$s): void { $s['approval']['productCode'] = 'landing-page'; }, 'review approval for Landing Page instead of Onepage');
$mutate(static function (array &$s): void { unset($s['approval']['productCode']); }, 'review approval missing product provenance');
$mutate(static function (array &$s): void { $s['approval']['manifestSha256'] = str_repeat('a', 64); }, 'reviewer hash mismatch');
$mutate(static function (array &$s): void { $s['head']['briefRevision']++; }, 'current head superseded');
$mutate(static function (array &$s): void { $s['head']['payloadSha256'] = str_repeat('0', 64); }, 'current head hash mismatch');
$mutate(static function (array &$s): void { $s['record']['export']['payloadSha256'] = str_repeat('b', 64); }, 'corrupted source hash');
$mutate(static function (array &$s): void { $s['record']['export']['canPublish'] = true; }, 'counterfeit authority');
$mutate(static function (array &$s): void { $s['record']['buyerRef'] = 'other-buyer'; }, 'buyer ownership replaced');
$mutate(static function (array &$s): void { $s['head']['canPublish'] = true; }, 'extra head authority');
$mutate(static function (array &$s): void { $s['confirmation']['canPublish'] = true; }, 'extra buyer authority');
$mutate(static function (array &$s): void { $s['approval']['canPublish'] = true; }, 'extra reviewer authority');
// New confirmation reference requires a *new* reviewer witness linking to it.
$reconfirmed = $original;
$reconfirmed['confirmation']['confirmationRef'] = 'confirm-002';
$reconfirmed['approval']['buyerConfirmationRef'] = 'confirm-002';
$reconfirmed['approval']['approvalRef'] = 'approval-002';
$ok($call($create($reconfirmed))['privatePreview'] === true, 'new synthetic confirmation and linked reviewer receipt');
$mutate(static function (array &$s) use ($json): void {
    $changed = json_decode($s['record']['export']['manifestBytes'], true, 16, JSON_THROW_ON_ERROR);
    $changed['productCode'] = 'landing-page';
    $s['record']['export']['manifestBytes'] = $json($changed);
    $s['record']['export']['manifestSha256'] = hash('sha256', $s['record']['export']['manifestBytes']);
}, 'Landing Page cannot borrow Onepage proof');
$reads = 0;
$swapping = new OnepageBuyerPrivatePreviewCandidate(
    static function () use (&$reads, $record): array {
        ++$reads;
        $result = $record;
        if ($reads > 1) $result['buyerRef'] = 'other-buyer';
        return $result;
    },
    static fn() => $head,
    static fn() => $confirmation,
    static fn() => $approval,
);
$deny(static fn() => $call($swapping), 'content/owner swap between independent reads');
$ok($reads === 2, 'exact source reread after rendering');
$approvals = 0;
$revoking = new OnepageBuyerPrivatePreviewCandidate(
    static fn() => $record,
    static fn() => $head,
    static fn() => $confirmation,
    static function () use (&$approvals, $approval): array {
        return ++$approvals === 1 ? $approval : array_replace($approval, ['approved' => false]);
    },
);
$deny(static fn() => $call($revoking), 'approval revoked between checks');
$ok($approvals === 2, 'approval revalidated after rendering');
// Both human witnesses can change together and still match the same HEAD.
// Such an observed mid-render rotation must not be silently accepted.
$confirmReads = 0;
$reviewReads = 0;
$pairedRotation = new OnepageBuyerPrivatePreviewCandidate(
    static fn() => $record,
    static fn() => $head,
    static function () use (&$confirmReads, $confirmation): array {
        return ++$confirmReads === 1 ? $confirmation :
            array_replace($confirmation, ['confirmationRef' => 'confirm-002']);
    },
    static function () use (&$reviewReads, $approval): array {
        return ++$reviewReads === 1 ? $approval :
            array_replace($approval, ['approvalRef' => 'approval-002', 'buyerConfirmationRef' => 'confirm-002']);
    },
);
$deny(static fn() => $call($pairedRotation), 'paired buyer and reviewer receipts changed during render');
$ok($confirmReads === 2 && $reviewReads === 2, 'both human proofs independently reread after render');
$heads = 0;
$superseding = new OnepageBuyerPrivatePreviewCandidate(
    static fn() => $record,
    static function () use (&$heads, $head): array {
        return ++$heads === 1 ? $head : array_replace($head, ['briefRevision' => $head['briefRevision'] + 1]);
    },
    static fn() => $confirmation,
    static fn() => $approval,
);
$deny(static fn() => $call($superseding), 'current revision superseded during render');
$ok($heads === 2, 'current head revalidated after rendering');
// No domain is required for PRIVATE preview. Domain belongs to publication.
$withoutDomain = $original;
$body = json_decode($source, true, 16, JSON_THROW_ON_ERROR);
$body['domain'] = '';
$withoutDomain['record']['export']['sourceBytes'] = $json($body);
$withoutDomain['record']['export']['payloadSha256'] = hash('sha256', $withoutDomain['record']['export']['sourceBytes']);
$m = $manifest;
$m['payload']['sha256'] = $withoutDomain['record']['export']['payloadSha256'];
$m['payload']['sizeBytes'] = strlen($withoutDomain['record']['export']['sourceBytes']);
$withoutDomain['record']['export']['manifestBytes'] = $json($m);
$withoutDomain['record']['export']['manifestSha256'] = hash('sha256', $withoutDomain['record']['export']['manifestBytes']);
$p = $plan;
$p['snapshot']['manifestSha256'] = $withoutDomain['record']['export']['manifestSha256'];
$withoutDomain['record']['plan'] = $json($p);
foreach (['head', 'confirmation', 'approval'] as $key) {
    $withoutDomain[$key]['manifestSha256'] = $withoutDomain['record']['export']['manifestSha256'];
    $withoutDomain[$key]['payloadSha256'] = $withoutDomain['record']['export']['payloadSha256'];
}
$ok($call($create($withoutDomain))['privatePreview'] === true, 'private preview without domain');
$ok($call($gate) === $preview, 'negative trials preserve original proof');
echo 'ONEPAGE BUYER PRIVATE PREVIEW OFFLINE PASS: '.$checks." checks; synthetic witnesses only; NO-GO.\n";
