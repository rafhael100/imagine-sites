<?php

declare(strict_types=1);

/**
 * F1-23: execute the ACTUAL Portal PR#105 candidate encoder+signer
 * against ACTUAL Sites candidate verifier. Entirely synthetic; no network,
 * Laravel HTTP, credential, official contract, database or customer data.
 */
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\PortalSiteCommercialEpochSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use App\Domain\SitesIntegration\SyntheticSitesVersionedEnvelopeSigner;
use App\Domain\SitesIntegration\CandidatePortalSitesSigningMaterial;
use App\Domain\SitesIntegration\PortalSitesSigningMaterialSource;
use App\Domain\SitesIntegration\LabPortalSitesVersionedIssuer;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationSnapshot as ProjectSnapshot;
use Imagine\Sites\Integration\SnapshotBoundPortalProjectAuthorityGateway;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;
use Imagine\Sites\Security\SyntheticVersionedSignedCommand;

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
    public bool $eligible=true;
    public int $revision=3;
    public string $tenantRef='tenant-fictional-001';
    public int $reads=0;

    public function __construct(private readonly string $product) {}

    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        ++$this->reads;
        if (!$this->eligible || $orderRef !== 'order-fictional-001') return null;
        return new SiteContractSnapshot(
            $this->tenantRef, 'order-fictional-001',
            'contract-fictional-001', $this->revision, $this->product
        );
    }
}

/** F1-121 synthetic source: not a real Portal key authority. */
final class F121FictionalSigningSource implements PortalSitesSigningMaterialSource
{
    public int $reads=0;
    public ?Closure $onRead=null;
    public bool $offline=false;

    public function __construct(
        public ?CandidatePortalSitesSigningMaterial $value
    ) {}

    public function currentFor(string $issuer,string $audience):?CandidatePortalSitesSigningMaterial
    {
        ++$this->reads;
        if ($this->onRead!==null) ($this->onRead)();
        if ($this->offline) throw new RuntimeException('F121 synthetic key service unavailable');
        if ($issuer!=='imagine-portal' || $audience!=='imagine-sites') return null;
        return $this->value;
    }
}

/** F1-123 fictional independent order-authorization generation. */
final class F123FictionalCommercialEpochs implements PortalSiteCommercialEpochSource
{
    public ?int $epoch=5;
    public bool $offline=false;
    public int $reads=0;

    public function currentEpochFor(string $orderRef): ?int
    {
        ++$this->reads;
        if ($this->offline) throw new RuntimeException('F123 synthetic epoch source offline');
        return $orderRef==='order-fictional-001' ? $this->epoch : null;
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
    $orderSource=new F23ContractSource($product);
    $encoder = new SitesCreateProjectEncoder($orderSource);
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

    // F1-119: the REAL independently implemented Portal v2 signer,
    // pinned to a public PR SHA, must match the Sites F1-118 verifier.
    $nonceV2='f119-crossrepo-v2-opaque-00'.$index;
    $epochV2=7;
    $portalV2=SyntheticSitesVersionedEnvelopeSigner::sign(
        $wirePayload,$key,$nonceV2,$now,$now+60,$epochV2
    );
    $sitesV2=SyntheticVersionedSignedCommand::sign(
        $sitesCommand,$key,$nonceV2,$now,$now+60,$epochV2
    );
    f23Assert($portalV2===$sitesV2,
        'F1-119 real pinned Portal and Sites produce identical epoch-signed v2 envelopes '.$product);
    $fenceV2=new EphemeralF23ReplayFence();
    $verifiedV2=SyntheticVersionedSignedCommand::verify(
        $portalV2,$key,$epochV2,$now,$fenceV2
    );
    f23Assert($verifiedV2->semanticFingerprint()===$sitesCommand->semanticFingerprint(),
        'F1-119 real Portal v2 HMAC accepted by exact Sites contract and scope '.$product);

    // F1-121: make a REAL independent Portal candidate issuer fetch both
    // key and epoch from a default-deny source, rather than accepting
    // those parameters from the caller. Verify the result in Sites.
    $trustedFixture=new F121FictionalSigningSource(
        new CandidatePortalSitesSigningMaterial(
            'imagine-portal','imagine-sites',$key,$epochV2
        )
    );
    $commercialEpochs=new F123FictionalCommercialEpochs();
    $trustedIssuer=new LabPortalSitesVersionedIssuer(
        $encoder,$trustedFixture,$commercialEpochs
    );
    $issuedByBoundary=$trustedIssuer->issue(
        'order-fictional-001',
        'operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        $nonceV2,$now,$now+60
    );
    f23Assert($issuedByBoundary===$sitesV2 && $trustedFixture->reads===2,
        'F1-121 trust-boundary Portal emission is byte identical to Sites v2 '.$product);
    $boundaryVerified=SyntheticVersionedSignedCommand::verify(
        $issuedByBoundary,$key,$epochV2,$now,new EphemeralF23ReplayFence()
    );
    f23Assert($boundaryVerified->semanticFingerprint()===$sitesCommand->semanticFingerprint(),
        'F1-121 trust-boundary Portal v2 passes Sites semantic scope checks '.$product);

    // Failing source must not leak an envelope; a new current source
    // must produce one only after service-trust recovery.
    $trustedFixture->offline=true;
    f23Assert(f23Denied(fn()=> $trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f121-source-offline-000'.$index,$now,$now+60
    )), 'F1-121 signer refuses a missing service authority '.$product);
    $trustedFixture->offline=false;

    // Rotate the trusted epoch BETWEEN the two key-source reads while
    // keeping the same bytes. The old signed result must not escape.
    $trustedFixture->reads=0;
    $trustedFixture->onRead=static function() use($trustedFixture,$key,$epochV2):void {
        if ($trustedFixture->reads===2) {
            $trustedFixture->value=new CandidatePortalSitesSigningMaterial(
                'imagine-portal','imagine-sites',$key,$epochV2+1
            );
        }
    };
    f23Assert(f23Denied(fn()=> $trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f121-rotating-epoch-000'.$index,$now,$now+60
    )), 'F1-121 rotation during issuance denies the v2 envelope '.$product);
    $trustedFixture->onRead=null;
    $newEpoch=$epochV2+1;
    $newIssuerEnvelope=$trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f121-rotating-epoch-000'.$index,$now,$now+60
    );
    f23Assert($newIssuerEnvelope['keyEpoch']===$newEpoch &&
        SyntheticVersionedSignedCommand::verify(
            $newIssuerEnvelope,$key,$newEpoch,$now,new EphemeralF23ReplayFence()
        )->semanticFingerprint()===$sitesCommand->semanticFingerprint(),
        'F1-121 new trusted epoch is accepted after candidate rotation '.$product);

    // F1-122: the REAL Portal F1-14 issuer re-reads the commercial
    // contract AFTER signing. Revocation, revision changes or tenant
    // reassignment during issuance must not return any envelope.
    $trustedFixture->reads=0;
    $trustedFixture->onRead=static function() use($trustedFixture,$orderSource):void {
        if ($trustedFixture->reads===2) $orderSource->eligible=false;
    };
    f23Assert(f23Denied(fn()=> $trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f122-revoked-order-000'.$index,$now,$now+60
    )) && $orderSource->eligible===false,
        'F1-122 Portal contract revoked mid-emission cannot sign a project '.$product);
    $trustedFixture->onRead=null;
    $orderSource->eligible=true;

    $trustedFixture->reads=0;
    $trustedFixture->onRead=static function() use($trustedFixture,$orderSource):void {
        if ($trustedFixture->reads===2) ++$orderSource->revision;
    };
    f23Assert(f23Denied(fn()=> $trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f122-contract-revised-000'.$index,$now,$now+60
    )), 'F1-122 Portal contract revision change denies a stale envelope '.$product);
    $trustedFixture->onRead=null;
    $orderSource->revision=3;

    $trustedFixture->reads=0;
    $trustedFixture->onRead=static function() use($trustedFixture,$orderSource):void {
        if ($trustedFixture->reads===2) $orderSource->tenantRef='tenant-f122-other';
    };
    f23Assert(f23Denied(fn()=> $trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f122-tenant-reassigned-000'.$index,$now,$now+60
    )), 'F1-122 tenant reassignment denies cross-company project envelope '.$product);
    $trustedFixture->onRead=null;
    $orderSource->tenantRef='tenant-fictional-001';

    $recovered=$trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f122-recovered-order-000'.$index,$now,$now+60
    );
    f23Assert(SyntheticVersionedSignedCommand::verify(
        $recovered,$key,$newEpoch,$now,new EphemeralF23ReplayFence()
    )->semanticFingerprint()===$sitesCommand->semanticFingerprint(),
        'F1-122 restored latest commercial scope emits a valid Portal v2 envelope '.$product);

    // F1-123: a commercial permission revoked and restored to the
    // identical snapshot BEFORE the final read cannot be noticed via
    // command bytes alone. The independent epoch must still deny.
    $trustedFixture->reads=0;
    $preEpoch=$commercialEpochs->epoch;
    $trustedFixture->onRead=static function() use(
        $trustedFixture,$orderSource,$commercialEpochs
    ):void {
        if ($trustedFixture->reads===2) {
            $orderSource->eligible=false;
            $orderSource->eligible=true;
            ++$commercialEpochs->epoch;
        }
    };
    f23Assert(f23Denied(fn()=> $trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f123-commercial-aba-00'.$index,$now,$now+60
    )) && $orderSource->eligible===true &&
        $commercialEpochs->epoch===$preEpoch+1,
        'F1-123 identical commercial snapshot with changed auth epoch denies '.$product);
    $trustedFixture->onRead=null;

    // Missing or unreachable authority is default DENY. Recovery with
    // latest revision can issue a new HMAC v2 the Sites verifier accepts.
    $commercialEpochs->offline=true;
    f23Assert(f23Denied(fn()=> $trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f123-commercial-epoch-offline-00'.$index,$now,$now+60
    )), 'F1-123 unavailable independent commercial epoch fails closed '.$product);
    $commercialEpochs->offline=false;
    $recoveredEpoch=$trustedIssuer->issue(
        'order-fictional-001','operation-fictional-00'.$index,
        'idempotency-fictional-00'.$index,
        'f123-recovered-commercial-epoch-00'.$index,$now,$now+60
    );
    f23Assert(SyntheticVersionedSignedCommand::verify(
        $recoveredEpoch,$key,$newEpoch,$now,new EphemeralF23ReplayFence()
    )->semanticFingerprint()===$sitesCommand->semanticFingerprint(),
        'F1-123 restored current commercial epoch yields valid Portal->Sites envelope '.$product);
    f23Assert(f23Denied(fn()=>
        SyntheticVersionedSignedCommand::verify(
            $portalV2,$key,$epochV2,$now,$fenceV2
        )),
        'F1-119 v2 replay is denied on a second use '.$product);

    // A newer independently consulted generation must reject a message
    // signed under the old key epoch BEFORE consuming its nonce.
    $oldNonce='f119-epoch-fenced-opaque-00'.$index;
    $oldV2=SyntheticSitesVersionedEnvelopeSigner::sign(
        $wirePayload,$key,$oldNonce,$now,$now+60,$epochV2
    );
    $freshEpoch=$epochV2+1;
    $epochFence=new EphemeralF23ReplayFence();
    f23Assert(f23Denied(fn()=>
        SyntheticVersionedSignedCommand::verify(
            $oldV2,$key,$freshEpoch,$now,$epochFence
        )),
        'F1-119 stale Portal signing epoch denied before nonce consumption '.$product);
    $newV2=SyntheticSitesVersionedEnvelopeSigner::sign(
        $wirePayload,$key,$oldNonce,$now,$now+60,$freshEpoch
    );
    f23Assert(SyntheticVersionedSignedCommand::verify(
        $newV2,$key,$freshEpoch,$now,$epochFence
    )->semanticFingerprint()===$sitesCommand->semanticFingerprint(),
        'F1-119 new Portal epoch reuses unconsumed nonce under fresh signature '.$product);
    $tamperedV2=$oldV2;
    $tamperedV2['keyEpoch']=$freshEpoch;
    f23Assert(f23Denied(fn()=>
        SyntheticVersionedSignedCommand::verify(
            $tamperedV2,$key,$freshEpoch,$now,new EphemeralF23ReplayFence()
        )),
        'F1-119 unsigned change of Portal keyEpoch invalidates the HMAC '.$product);
    f23Assert(f23Denied(fn()=>
        SyntheticVersionedSignedCommand::verify(
            $portalSigned,$key,$epochV2,$now,new EphemeralF23ReplayFence()
        )),
        'F1-119 legacy v1 cannot be accepted as a v2 command '.$product);

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
// Without a trusted signing authority and without an official
// commercial source, a freshly built Portal issuer is always DENY.
f23Assert(f23Denied(fn()=> (new LabPortalSitesVersionedIssuer())->issue(
    'order-fictional-001','operation-fictional-900','idempotency-fictional-900',
    'f121-default-deny-0001',$now,$now+60
)), 'F1-121 default Portal issuer does not sign unauthenticated orders');

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
