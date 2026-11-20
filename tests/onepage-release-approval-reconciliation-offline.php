<?php

declare(strict_types=1);

/**
 * OnePage synthetic bridge not covered by #573 acceptance or #574 HTTP swap:
 * technical release history cannot refresh stale buyer consent or authorize
 * rollback, and current client consent cannot replace publisher authority.
 * No HTTP, providers, private customers, credentials, checkout or deploy.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_ONEPAGE_RELEASE_RECONCILIATION_LAB') !== '1' ||
    getenv('SITES_F391_APPROVAL_LAB') !== '1') {
    fwrite(STDERR, "OnePage approval reconciliation is limited to isolated CI.\n");
    exit(1);
}

use Imagine\Sites\Domain\{PolicyDecision, ReleaseManifest};
use Imagine\Sites\Publication\{
    IndependentClientApprovalWitness, OnepageReleaseConsentAndPublisherFenceCandidate,
    PortalClientApprovalReceiptSource, SignedPortalClientApprovalGate,
    OnepagePublisherGrantSnapshotCandidate
};

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Domain', 'Publication'] as $area) {
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

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('OnePage reconciliation failed: '.$label);
    ++$checks;
};
$now = 1800000000; // Fictional stable CI clock only.
$a = new ReleaseManifest('tenant-fictional-onepage', 'site-fictional-onepage',
    'release-A', hash('sha256', 'synthetic A html bytes'));
$b = new ReleaseManifest($a->tenantRef, $a->siteRef,
    'release-B', hash('sha256', 'synthetic B html bytes'));
$keypair = sodium_crypto_sign_keypair();
$key = sodium_crypto_sign_secretkey($keypair);
$public = bin2hex(sodium_crypto_sign_publickey($keypair));

$issueReceipt = static function (ReleaseManifest $release, int $revision,
    string $ref, int $epoch) use ($now, $key): array {
    $receipt = [
        'schemaVersion' => 'sites.portal-client-acceptance.ed25519.v1',
        'issuer' => 'imagine-portal', 'audience' => 'imagine-sites',
        'issuedAt' => $now, 'expiresAt' => $now + 60, 'acceptedAt' => $now - 5,
        'tenantRef' => $release->tenantRef, 'siteRef' => $release->siteRef,
        'releaseRef' => $release->releaseRef, 'artifactDigest' => $release->digest,
        'editorialRevision' => $revision, 'acceptanceRef' => $ref,
        'acceptanceEpoch' => $epoch, 'actorContext' => 'portal_client_authenticated_mfa',
        'decision' => 'accepted_explicitly', 'signature' => '',
    ];
    $bytes = json_encode(array_slice($receipt, 0, -1, true),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $receipt['signature'] = bin2hex(sodium_crypto_sign_detached($bytes, $key));
    return $receipt;
};
$source = new class implements PortalClientApprovalReceiptSource {
    public ?array $receipt = null;
    public int $calls = 0;
    public function fetchCurrentReceipt(ReleaseManifest $manifest, int $editorialRevision): ?array {
        ++$this->calls;
        return $this->receipt;
    }
};
$witness = new class implements IndependentClientApprovalWitness {
    public string $release = '';
    public string $digest = '';
    public string $ref = '';
    public int $epoch = 0;
    public int $revision = 0;
    public int $calls = 0;
    public function confirmsCurrent(ReleaseManifest $manifest, int $editorialRevision,
        string $acceptanceRef, int $acceptanceEpoch): bool {
        ++$this->calls;
        return $manifest->releaseRef === $this->release &&
            $manifest->digest === $this->digest &&
            $acceptanceRef === $this->ref &&
            $acceptanceEpoch === $this->epoch &&
            $editorialRevision === $this->revision;
    }
};
$setCurrent = static function (ReleaseManifest $release, int $revision,
    string $ref, int $epoch) use ($source, $witness, $issueReceipt): void {
    $source->receipt = $issueReceipt($release, $revision, $ref, $epoch);
    $witness->release = $release->releaseRef;
    $witness->digest = $release->digest;
    $witness->ref = $ref;
    $witness->epoch = $epoch;
    $witness->revision = $revision;
};
$setCurrent($a, 1, 'accept-onepage-A-initial', 1);
$consent = new SignedPortalClientApprovalGate($source, $witness, $public, $now);

$unbound = new OnepageReleaseConsentAndPublisherFenceCandidate($consent);
$check(!$unbound->inspect($a, 1, 'promote')->allowed && $source->calls === 0,
    'unbound publisher source denies without even reading buyer consent');

$publisherAllowed = false;
$publisherCalls = 0;
$revokeDuringGrant = false;
$revokePublisherAfterFirstRead = false;
$publisherEpoch = 1;
$publisherScopeOverride = null;
$publisherTenantOverride = null;
$publisherDigestOverride = null;
$publisherRevisionOverride = null;
$rotateEpochBetweenReads = false;
$publisher = static function (ReleaseManifest $candidate, int $revision, string $operation)
    use (&$publisherAllowed, &$publisherCalls, &$revokeDuringGrant,
        &$revokePublisherAfterFirstRead, &$publisherEpoch, &$publisherScopeOverride,
        &$publisherTenantOverride, &$publisherDigestOverride, &$publisherRevisionOverride,
        &$rotateEpochBetweenReads, $witness): OnepagePublisherGrantSnapshotCandidate {
    ++$publisherCalls;
    if ($revokeDuringGrant) {
        $revokeDuringGrant = false;
        ++$witness->epoch;
    }
    $approvedForThisRead = $publisherAllowed;
    if ($revokePublisherAfterFirstRead && $publisherCalls === 1) {
        $publisherAllowed = false;
        $approvedForThisRead = true; // First read still authorized.
    }
    $snapshot = new OnepagePublisherGrantSnapshotCandidate(
        $publisherTenantOverride ?? $candidate->tenantRef,
        $candidate->siteRef, $candidate->releaseRef,
        $publisherDigestOverride ?? $candidate->digest,
        $publisherRevisionOverride ?? $revision,
        $publisherScopeOverride ?? $operation,
        'publisher-grant-fictional', $publisherEpoch,
        'publisher-operator-fictional', $approvedForThisRead,
    );
    if ($rotateEpochBetweenReads && $publisherCalls === 1) {
        ++$publisherEpoch; // Both snapshots ALLOW but attest different grants.
    }
    return $snapshot;
};
$fence = new OnepageReleaseConsentAndPublisherFenceCandidate($consent, $publisher);
$check(!$fence->inspect($a, 1, 'promote')->allowed,
    'fresh signed buyer acceptance alone cannot authorize technical release');
$publisherAllowed = true;
$check($fence->inspect($a, 1, 'promote')->allowed && $publisherCalls >= 3,
    'exact A consent and separate fictitious publisher grant are both required');

$setCurrent($b, 2, 'accept-onepage-B-current', 2);
$check(!$fence->inspect($a, 1, 'promote')->allowed,
    'after B becomes current, historical A consent cannot authorize rollback');
$check(!$fence->inspect($b, 1, 'promote')->allowed,
    'editorial revision mismatch cannot borrow B current consent');
$check($fence->inspect($b, 2, 'promote')->allowed,
    'B requires its own release, digest, revision, ref and epoch');

$revokeDuringGrant = true;
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'buyer consent revoked between independent reads denies release transition');
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'revocation remains terminal even when publisher grant remains true');

$setCurrent($b, 2, 'accept-onepage-B-renewed', 4);
$publisherCalls = 0;
$revokePublisherAfterFirstRead = true;
$publisherAllowed = true;
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'publisher grant removed during inspection denies despite valid buyer consent');
$revokePublisherAfterFirstRead = false;
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'subsequent inspection stays denied without separate publisher authority');

$setCurrent($a, 1, 'accept-onepage-A-reconfirmed', 5);
$publisherAllowed = false;
$check(!$fence->inspect($a, 1, 'promote')->allowed,
    'renewed rollback consent still cannot replace publisher grant');
$publisherAllowed = true;
$check($fence->inspect($a, 1, 'promote')->allowed,
    'a distinct current A consent plus independent synthetic grant is required for a new proposal');
$check(!$fence->inspect(new ReleaseManifest('other-tenant', $a->siteRef,
    $a->releaseRef, $a->digest), 1, 'promote')->allowed,
    'cross-tenant replay of valid signed consent is denied');


$setCurrent($b, 2, 'accept-onepage-B-scoped', 6);
$publisherAllowed = true;
$publisherScopeOverride = 'rollback';
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'rollback-specific publisher grant cannot authorize promotion');
$publisherScopeOverride = null;
$publisherTenantOverride = 'other-tenant';
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'publisher grant from another tenant cannot authorize this tenant');
$publisherTenantOverride = null;
$publisherDigestOverride = str_repeat('a', 64);
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'publisher grant for altered artifact SHA cannot be reused');
$publisherDigestOverride = null;
$publisherRevisionOverride = 99;
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'publisher grant for a different editorial revision cannot be reused');
$publisherRevisionOverride = null;
$publisherCalls = 0;
$rotateEpochBetweenReads = true;
$check(!$fence->inspect($b, 2, 'promote')->allowed,
    'rotating publisher epoch rejects two otherwise allowed grant snapshots');
$rotateEpochBetweenReads = false;
$check($fence->inspect($b, 2, 'promote')->allowed,
    'a stable exact scoped publisher grant still permits an offline inspection');
$check(!$fence->inspect($b, 2, 'publication')->allowed,
    'unknown technical action is rejected before consulting publisher');
$check(!$fence->inspect($a, 1, 'rollback')->allowed,
    'historical release cannot borrow the current B consent for rollback');
$check(!(new OnepageReleaseConsentAndPublisherFenceCandidate(
    $consent, static fn() => new PolicyDecision(true, []),
))->inspect($b, 2, 'promote')->allowed,
    'legacy unscoped boolean publisher assertion no longer authorizes release');

$check($witness->calls >= 7 && $source->calls >= 7,
    'independent witness and signed receipt source re-read on candidates');
echo 'ONEPAGE RELEASE CONSENT RECONCILIATION PASS: '.$checks.
    " synthetic assertions; NO-GO.\n";
