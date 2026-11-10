<?php

declare(strict_types=1);

/**
 * F1-23: execute the ACTUAL Portal PR#105 candidate encoder+signer
 * against ACTUAL Sites candidate verifier. Entirely synthetic; no network,
 * Laravel HTTP, credential, official contract, database or customer data.
 */
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationSnapshot as ProjectSnapshot;
use Imagine\Sites\Integration\SnapshotBoundPortalProjectAuthorityGateway;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;

if (PHP_SAPI !== 'cli' || getenv('SITES_F1_23_CROSS_REPO') !== '1') {
    fwrite(STDERR, "Cross-repo contract test is disabled outside explicit CLI lab.\n");
    exit(1);
}

$portalDirectory = getenv('SITES_F1_23_PORTAL_PATH');
if (!is_string($portalDirectory) || $portalDirectory === '' ||
    !is_dir($portalDirectory.'/apps/portal/app/Domain/SitesIntegration')) {
    throw new RuntimeException('Pinned Portal candidate source has not been checked out.');
}

spl_autoload_register(static function (string $class) use ($portalDirectory): void {
    $sources = [
        'App\\Domain\\SitesIntegration\\' =>
            $portalDirectory.'/apps/portal/app/Domain/SitesIntegration/',
        'Imagine\\Sites\\Domain\\' => __DIR__.'/../src/Domain/',
        'Imagine\\Sites\\Integration\\' => __DIR__.'/../src/Integration/',
        'Imagine\\Sites\\Security\\' => __DIR__.'/../src/Security/',
    ];
    foreach ($sources as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $short = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $short)) {
            throw new RuntimeException('Rejected unsafe class path.');
        }
        $file = $directory.$short.'.php';
        if (!is_file($file)) throw new RuntimeException('Missing candidate class: '.$short);
        require $file;
        return;
    }
});

final class EphemeralF23ReplayFence implements ReplayStore
{
    /** @var array<string,bool> */
    private array $seen = [];

    public function rememberOnce(string $issuer, string $nonce, int $expiresAt): bool
    {
        $id = strlen($issuer).':'.$issuer.':'.$nonce;
        if (isset($this->seen[$id])) return false;
        $this->seen[$id] = true;
        return true;
    }
}

final class F23ContractSource implements PortalSiteContractSource
{
    public function __construct(private readonly string $product) {}

    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        if ($orderRef !== 'order-fictional-001') return null;
        return new SiteContractSnapshot(
            'tenant-fictional-001', 'order-fictional-001',
            'contract-fictional-001', 3, $this->product
        );
    }
}

$count = 0;
function f23Assert(bool $valid, string $description): void
{
    global $count;
    if (!$valid) throw new RuntimeException('FAIL '.$description);
    ++$count;
    echo "PASS ".$description."\n";
}
function f23Denied(callable $fn): bool
{
    try { $fn(); }
    catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}

$key = 'DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';
$now = 1790000000;
foreach (['onepage','landing_page'] as $index => $product) {
    $encoder = new SitesCreateProjectEncoder(new F23ContractSource($product));
    $wirePayload = $encoder->build(
        'order-fictional-001',
        'operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index
    );
    $sitesCommand = SiteProjectCommand::fromArray($wirePayload);
    f23Assert($sitesCommand->productCode === $product,
        'Portal and Sites agree on product '.$product);

    $nonce = 'f23-crossrepo-opaque-00'.$index;
    $portalSigned = SyntheticSitesEnvelopeSigner::sign(
        $wirePayload,$key,$nonce,$now,$now+60
    );
    $sitesSigned = SyntheticSignedCommand::sign(
        $sitesCommand,$key,$nonce,$now,$now+60
    );
    f23Assert($portalSigned === $sitesSigned,
        'actual Portal and Sites signer produce byte-equivalent candidate envelope '.$product);

    $fence = new EphemeralF23ReplayFence();
    $verified = SyntheticSignedCommand::verify($portalSigned,$key,$now,$fence);
    f23Assert($verified->semanticFingerprint() === $sitesCommand->semanticFingerprint(),
        'Sites accepts candidate Portal signature and keeps same domain fingerprint');

    // F1-108: use the ACTUAL Portal encoder wire command inside the NEW
    // strict Sites candidate snapshot decoder. The signed command and
    // candidate authorization remain separate artifacts: neither proves
    // current Portal IAM approval.
    $snapshotWire=[
        'schemaVersion'=>'sites.portal-project-authorization.v1',
        'boundCommand'=>$wirePayload,
        'authorizationRevision'=>2,
        'status'=>'eligible',
        'checkedAt'=>$now-1,
        'validUntil'=>$now+20,
    ];
    $snapshotJson=json_encode(
        $snapshotWire,
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
    );
    $snapshot=ProjectSnapshot::fromCanonicalJson($snapshotJson);
    f23Assert($snapshot->isCurrentFor($verified,$now) &&
        $snapshot->boundCommand->semanticFingerprint()===$verified->semanticFingerprint(),
        'F1-108 real Portal command fits strict Sites snapshot shape for '.$product);
    $snapshotChanged=$snapshotWire;
    $snapshotChanged['boundCommand']['contractRevision']++;
    $changedJson=json_encode(
        $snapshotChanged,
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
    );
    f23Assert(!ProjectSnapshot::fromCanonicalJson($changedJson)
        ->isCurrentFor($verified,$now),
        'F1-108 changed Portal contract revision cannot inherit exact snapshot for '.$product);
    f23Assert(!(new SnapshotBoundPortalProjectAuthorityGateway())
        ->verify($sitesCommand)->allowed,
        'F1-108 even a valid candidate snapshot never enables default Portal IAM');

    f23Assert(f23Denied(fn() =>
        SyntheticSignedCommand::verify($portalSigned,$key,$now,$fence)),
        'candidate replay denied for '.$product);

    $mutated = $portalSigned;
    $mutated['payload']['tenantRef'] = 'tenant-attacker-002';
    f23Assert(f23Denied(fn() =>
        SyntheticSignedCommand::verify($mutated,$key,$now,new EphemeralF23ReplayFence())),
        'changing tenant invalidates authentic Portal MAC');
    f23Assert(f23Denied(fn() =>
        SyntheticSignedCommand::verify($portalSigned,$key,$now+60,new EphemeralF23ReplayFence())),
        'exact expiry denied for '.$product);
}
$defaultEncoder = new SitesCreateProjectEncoder();
f23Assert(f23Denied(fn() =>
    $defaultEncoder->build('order-fictional-001','operation-fictional-999','idem-fictional-999')),
    'Portal source defaults to DENY without trusted commercial adapter');

$wirePayload = (new SitesCreateProjectEncoder(new F23ContractSource('onepage')))
    ->build('order-fictional-001','operation-fictional-100','idempotency-fictional-100');
$example = SyntheticSitesEnvelopeSigner::sign(
    $wirePayload,$key,'f23-crossrepo-deny-default',$now,$now+60
);
f23Assert(f23Denied(fn() =>
    SyntheticSignedCommand::verify($example,$key,$now)),
    'Sites default verifier denies in absence of durable replay adapter');

$badIssuer = $example;
$badIssuer['issuer'] = 'imagine-agents';
f23Assert(f23Denied(fn() =>
    SyntheticSignedCommand::verify($badIssuer,$key,$now,new EphemeralF23ReplayFence())),
    'unknown issuer cannot inject a contract command');

f23Assert(f23Denied(fn() =>
    (new SitesCreateProjectEncoder(new F23ContractSource('onepage')))
        ->build('order-other-tenant','operation-fictional-200','idempotency-fictional-200')),
    'Portal source rejects unknown order');

$wrongProductOrder = $wirePayload;
unset($wrongProductOrder['contractRevision']);
$wrongProductOrder['contractRevision'] = 3;
f23Assert(f23Denied(fn() =>
    SyntheticSitesEnvelopeSigner::sign(
        $wrongProductOrder,$key,'f23-wire-field-reorder',$now,$now+60)),
    'Portal rejects reordered signed command payload');

echo "\n$count F1-23 REAL cross-repository class interoperability checks passed.\n";
echo "NO production identity, public HTTP, tenant entitlement, CRM or provider deployment.\n";
