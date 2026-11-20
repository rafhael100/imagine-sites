<?php

declare(strict_types=1);

/**
 * One synthetic, already-admitted Onepage order: immutable briefing -> private
 * HTML file -> signed buyer acceptance evidence -> rework invalidation.
 * NOT authentication, durable consent or a publisher. No HTTP/network.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_ONEPAGE_ACCEPTANCE_CHAIN_LAB') !== '1' ||
    getenv('SITES_F391_APPROVAL_LAB') !== '1') {
    fwrite(STDERR, "Onepage acceptance chain is limited to isolated CI.\n");
    exit(1);
}

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Production\{BriefSnapshotInput, LocalSiteRendering, OnepageBuyerPrivatePreviewCandidate};
use Imagine\Sites\Publication\{IndependentClientApprovalWitness, PortalClientApprovalReceiptSource, SignedPortalClientApprovalGate};

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Production', 'Publication', 'Domain'] as $area) {
        $prefix = 'Imagine\\Sites\\'.$area.'\\';
        if (!str_starts_with($class, $prefix)) continue;
        $short = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $short)) {
            throw new RuntimeException('Unsafe synthetic class name');
        }
        require_once $root.'/src/'.$area.'/'.$short.'.php';
        return;
    }
});

$fixturePath = $root.'/contracts/portal-sites/candidates/brief-production-v1/examples/';
$fixture = static function (string $name) use ($fixturePath): string {
    $content = file_get_contents($fixturePath.$name);
    if (!is_string($content)) throw new RuntimeException('Missing synthetic fixture');
    return $content;
};
$json = static fn(array $value): string => json_encode(
    $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Onepage acceptance chain failed: '.$label);
    ++$checks;
};

$sourceBytes = $fixture('onepage-brief.synthetic.json');
$editorial = $fixture('render-input.synthetic.json');
$manifest = json_decode($fixture('brief-snapshot.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$manifest['payload']['sizeBytes'] = strlen($sourceBytes);
$manifest['payload']['sha256'] = hash('sha256', $sourceBytes);
$manifestBytes = $json($manifest);
$brief = BriefSnapshotInput::fromJson($manifestBytes, $sourceBytes);
$plan = json_decode($fixture('brief-render-plan.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$plan['snapshot']['manifestSha256'] = hash('sha256', $manifestBytes);
$plan['renderInput'] = [
    'payloadRef' => $plan['renderInput']['payloadRef'], 'mediaType' => 'application/json',
    'sizeBytes' => strlen($editorial), 'sha256' => hash('sha256', $editorial),
];
$firstArtifact = LocalSiteRendering::render($editorial);
$plan['expectedArtifact'] = ['sha256' => $firstArtifact->digest, 'sizeBytes' => $firstArtifact->sizeBytes];

$selection = [
    'tenantRef' => $brief->tenantRef, 'orderRef' => $brief->orderRef,
    'siteRef' => $brief->siteRef, 'contractRef' => $brief->contractRef,
    'contractRevision' => $brief->contractRevision, 'productCode' => 'onepage',
    'snapshotRef' => $brief->snapshotRef, 'briefRevision' => $brief->briefRevision,
    'sourceVersion' => $brief->sourceVersion, 'manifestSha256' => $brief->manifestSha256,
    'payloadSha256' => $brief->payloadSha256,
];
$state = [
    'record' => [
        'export' => [
            'manifestBytes' => $manifestBytes, 'sourceBytes' => $sourceBytes,
            'manifestSha256' => hash('sha256', $manifestBytes),
            'payloadSha256' => hash('sha256', $sourceBytes),
            'authorityVerified' => false, 'clientAccepted' => false,
            'canPublish' => false, 'canAcceptRealOrders' => false,
        ],
        'plan' => $json($plan), 'editorial' => $editorial, 'buyerRef' => 'buyer-one',
    ],
    'head' => $selection,
    'confirmation' => $selection + [
        'buyerRef' => 'buyer-one', 'confirmationRef' => 'confirm-fictional-001',
        'rightsDeclared' => true, 'confirmed' => true,
    ],
    'approval' => $selection + [
        'reviewerRef' => 'independent-reviewer-fictional',
        'approvalRef' => 'review-fictional-001',
        'buyerConfirmationRef' => 'confirm-fictional-001', 'approved' => true,
    ],
];
$private = new OnepageBuyerPrivatePreviewCandidate(
    static function () use (&$state): array { return $state['record']; },
    static function () use (&$state): array { return $state['head']; },
    static function () use (&$state): array { return $state['confirmation']; },
    static function () use (&$state): array { return $state['approval']; },
);
$preview = static function (string $buyer = 'buyer-one') use ($private, $brief): array {
    // In CI this is an invented session identity; a real adapter must obtain it
    // from authenticated Portal server-side state, never a request parameter.
    return $private->render($buyer, $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion);
};
$first = $preview();
$check($first['sha256'] === $firstArtifact->digest &&
    hash_equals($first['sha256'], hash('sha256', $first['html'])) &&
    $first['sourceVersion'] === $brief->sourceVersion, 'exact private artifact and version');
$check($first['privatePreview'] === true && $first['clientFinalAccepted'] === false &&
    $first['publicationAuthorized'] === false && $first['canPublish'] === false,
    'preview has no acceptance or publication authority');
try {
    $preview('another-buyer-same-tenant');
    throw new RuntimeException('Other buyer unexpectedly viewed preview');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED',
        'other buyer of same tenant denied');
}

// The temporary HTML file is not an HTTP endpoint or persisted production
// artifact. It exists only in the ephemeral job and is erased in finally.
$folder = sys_get_temp_dir().'/imagine-onepage-'.bin2hex(random_bytes(12));
if (!mkdir($folder, 0700)) throw new RuntimeException('Unable to allocate private CI folder');
$file = $folder.'/private-onepage.html';
try {
    if (file_put_contents($file, $first['html'], LOCK_EX) !== strlen($first['html']) ||
        !chmod($file, 0600)) throw new RuntimeException('Cannot materialize private CI preview');
    $check(is_file($file) && (fileperms($file) & 0777) === 0600 &&
        hash_equals($first['sha256'], (string)hash_file('sha256', $file)),
        'private local file serves exact reviewed HTML bytes');

    $now = 1800000000; // Synthetic fixture clock only.
    $keys = sodium_crypto_sign_keypair();
    $privateKey = sodium_crypto_sign_secretkey($keys);
    $publicKey = bin2hex(sodium_crypto_sign_publickey($keys));
    $firstRelease = new ReleaseManifest($brief->tenantRef, $brief->siteRef,
        $plan['releaseRef'], $first['sha256']);
    $signedReceipt = static function (ReleaseManifest $release, int $revision, string $reference,
        int $epoch) use ($now, $privateKey): array {
        $receipt = [
            'schemaVersion' => 'sites.portal-client-acceptance.ed25519.v1',
            'issuer' => 'imagine-portal', 'audience' => 'imagine-sites',
            'issuedAt' => $now, 'expiresAt' => $now + 60, 'acceptedAt' => $now - 5,
            'tenantRef' => $release->tenantRef, 'siteRef' => $release->siteRef,
            'releaseRef' => $release->releaseRef, 'artifactDigest' => $release->digest,
            'editorialRevision' => $revision, 'acceptanceRef' => $reference,
            'acceptanceEpoch' => $epoch, 'actorContext' => 'portal_client_authenticated_mfa',
            'decision' => 'accepted_explicitly', 'signature' => '',
        ];
        $message = json_encode(array_slice($receipt, 0, -1, true),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $receipt['signature'] = bin2hex(sodium_crypto_sign_detached($message, $privateKey));
        return $receipt;
    };

    $source = new class implements PortalClientApprovalReceiptSource {
        public ?array $receipt = null;
        public function fetchCurrentReceipt(ReleaseManifest $manifest, int $editorialRevision): ?array {
            return $this->receipt;
        }
    };
    $witness = new class implements IndependentClientApprovalWitness {
        public string $currentDigest = '';
        public string $currentRelease = '';
        public string $currentAcceptance = '';
        public int $currentEpoch = 0;
        public int $calls = 0;
        public function confirmsCurrent(ReleaseManifest $manifest, int $editorialRevision,
            string $acceptanceRef, int $acceptanceEpoch): bool {
            ++$this->calls;
            return $manifest->digest === $this->currentDigest &&
                $manifest->releaseRef === $this->currentRelease &&
                $acceptanceRef === $this->currentAcceptance &&
                $acceptanceEpoch === $this->currentEpoch && $editorialRevision === 1;
        }
    };
    $source->receipt = $signedReceipt($firstRelease, 1, 'accept-fictional-onepage-001', 1);
    $witness->currentDigest = $firstRelease->digest;
    $witness->currentRelease = $firstRelease->releaseRef;
    $witness->currentAcceptance = 'accept-fictional-onepage-001';
    $witness->currentEpoch = 1;
    $gate = new SignedPortalClientApprovalGate($source, $witness, $publicKey, $now);
    $accepted = $gate->verify($firstRelease, 1);
    $check($accepted->allowed && $witness->calls === 1,
        'fictional exact release and digest receives signed acceptance with current witness');
    $check(!$gate->verify(new ReleaseManifest($brief->tenantRef, $brief->siteRef,
        $firstRelease->releaseRef, hash('sha256', 'counterfeit bytes')), 1)->allowed,
        'different SHA cannot reuse prior signed acceptance');

    // Rework only the editorial rendering, using the original renderer, without
    // changing the immutable briefing. This is a NEW release requiring new
    // independent editorial approval and buyer acceptance in a real system.
    $newEditorial = json_decode($editorial, true, 16, JSON_THROW_ON_ERROR);
    $newEditorial['headline'] = 'Cuidados para bicicletas — revisão editorial fictícia';
    $editedBytes = $json($newEditorial);
    $secondArtifact = LocalSiteRendering::render($editedBytes);
    $secondPlan = $plan;
    $secondPlan['releaseRef'] = 'release-synthetic-onepage-002';
    $secondPlan['renderInput']['sizeBytes'] = strlen($editedBytes);
    $secondPlan['renderInput']['sha256'] = hash('sha256', $editedBytes);
    $secondPlan['expectedArtifact'] = ['sha256' => $secondArtifact->digest,
        'sizeBytes' => $secondArtifact->sizeBytes];
    $state['record']['editorial'] = $editedBytes;
    $state['record']['plan'] = $json($secondPlan);
    $second = $preview();
    $check($second['sha256'] === $secondArtifact->digest &&
        $second['sha256'] !== $first['sha256'] &&
        $second['clientFinalAccepted'] === false,
        'same fictitious order has changed private HTML with new artifact digest');
    $check(!hash_equals($second['sha256'], (string)hash_file('sha256', $file)),
        'old private file cannot masquerade as new artifact');

    $secondRelease = new ReleaseManifest($brief->tenantRef, $brief->siteRef,
        $secondPlan['releaseRef'], $second['sha256']);
    $check(!$gate->verify($secondRelease, 2)->allowed,
        'signed old acceptance invalid for newly rendered release and editorial revision');

    // Independent evidence must also reject the old release once the current
    // artifact changes, even if the old signed receipt itself is still valid.
    $witness->currentDigest = $second['sha256'];
    $witness->currentRelease = $secondRelease->releaseRef;
    $witness->currentEpoch = 2;
    $check(!$gate->verify($firstRelease, 1)->allowed,
        'old exact consent revoked by independently observed current release');
    $check($witness->calls >= 2, 'independent witness queried, not implied by ACK or old receipt');

    $state['approval']['approved'] = false;
    try {
        $preview();
        throw new RuntimeException('Revoked review unexpectedly rendered');
    } catch (RuntimeException $e) {
        $check($e->getMessage() === 'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED',
            'review revocation also blocks the new private preview');
    }

    $check($first['canPublish'] === false && $second['canPublish'] === false,
        'no technical publication, checkout, final delivery or go-live grant');
} finally {
    if (is_file($file)) unlink($file);
    rmdir($folder);
}
$check(!file_exists($file) && !is_dir($folder), 'ephemeral private preview file cleaned');
echo 'ONEPAGE PREVIEW TO ACCEPTANCE CHAIN PASS: '.$checks." synthetic assertions; NO-GO.\n";
