<?php

declare(strict_types=1);

/**
 * F1-359: ACTUAL pinned Portal candidate encoder+signer against the new
 * Sites signed-authority/idempotent-ingress flow. The Portal commercial
 * adapter, authorities, keys and project ledger are all fictional.
 * No HTTP, customer, payment, database or deployment.
 */
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use Imagine\Sites\Domain\SyntheticIdempotencyLedger;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\SyntheticSitesOrderIngress;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;

if (PHP_SAPI !== 'cli' ||
    getenv('SITES_F1_23_CROSS_REPO') !== '1' ||
    getenv('SITES_F1_359_CROSS_REPO') !== '1') {
    fwrite(STDERR,"F1-359 cross-repository synthetic-only fixture denied.\n");
    exit(1);
}
$portalDir = getenv('SITES_F1_23_PORTAL_PATH');
if (!is_string($portalDir) || !is_file(
    $portalDir.'/apps/portal/app/Domain/SitesIntegration/SyntheticSitesEnvelopeSigner.php'
)) {
    throw new RuntimeException('Pinned Portal candidate classes missing.');
}
spl_autoload_register(static function (string $class) use ($portalDir): void {
    $sources=[
        'App\\Domain\\SitesIntegration\\' =>
            $portalDir.'/apps/portal/app/Domain/SitesIntegration/',
        'Imagine\\Sites\\Integration\\'=>__DIR__.'/../src/Integration/',
        'Imagine\\Sites\\Security\\'=>__DIR__.'/../src/Security/',
        'Imagine\\Sites\\Domain\\'=>__DIR__.'/../src/Domain/',
        'Imagine\\Sites\\Production\\'=>__DIR__.'/../src/Production/',
    ];
    foreach ($sources as $prefix=>$base) {
        if(!str_starts_with($class,$prefix))continue;
        $short=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)){
            throw new RuntimeException('Invalid class path.');
        }
        require $base.$short.'.php';
        return;
    }
});

final class F359CommercialStub implements PortalSiteContractSource
{
    public function __construct(private readonly string $product) {}
    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        if ($orderRef !== 'order-f359-001')return null;
        return new SiteContractSnapshot(
            'tenant-f359-001','order-f359-001','contract-f359-001',3,
            $this->product
        );
    }
}
final class F359ReplayStore implements ReplayStore
{
    private array $seen=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $key=$issuer.':'.$nonce;
        if(isset($this->seen[$key]))return false;
        $this->seen[$key]=true;
        return true;
    }
}
$checks=0;
function f359Check(bool $valid,string $label):void
{
    global $checks;
    if(!$valid)throw new RuntimeException('F1-359: '.$label);
    ++$checks;
}
function f359Denied(callable $run):bool
{
    try{$run();}catch(DomainException|InvalidArgumentException|LogicException){return true;}
    return false;
}
$now=1790000000;
$commandKey='F359-PORTAL-CANDIDATE-FICTIONAL-SIGNER-KEY-0001';
$portalKey='F359-PORTAL-COMMERCIAL-FICTIONAL-PROOF-KEY-02';
$externalKey='F359-INDEPENDENT-REVOCATION-FICTIONAL-KEY-03';
$ledger=new SyntheticIdempotencyLedger();
$replay=new F359ReplayStore();

foreach(['onepage','landing_page'] as $i=>$product) {
    $encoder=new SitesCreateProjectEncoder(new F359CommercialStub($product));
    $payload=$encoder->build(
        'order-f359-001','operation-f359-00'.$i,
        'idem-f359-00'.$i
    );
    $cmd=SiteProjectCommand::fromArray($payload);
    f359Check($cmd->productCode===$product,
        'real Portal encoder creates canonical '.$product.' contract');
    $snapshot=new SyntheticAuthoritySnapshot(
        $cmd->tenantRef,$cmd->contractRef,$cmd->contractRevision,
        true,$now-1,$now+20
    );
    $portal=SyntheticAuthorityAttestation::sign(
        'imagine-portal-authority',$snapshot,$portalKey
    );
    $external=SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation',$snapshot,$externalKey
    );
    $wire=SyntheticSitesEnvelopeSigner::sign(
        $payload,$commandKey,'f359-portal-nonce-00'.$i,$now,$now+60
    );
    $result=SyntheticSitesOrderIngress::receive(
        $wire,$portal,$external,$commandKey,$portalKey,$externalKey,
        $now,$replay,$ledger,
        'site-f359-00'.$i,'workflow-f359-00'.$i
    );
    f359Check($result['status']==='queued' &&
        $result['workflow'] instanceof SyntheticProductionWorkflow &&
        $result['publishable']===false,
        'actual Portal signer admitted to a private, nonpublishable Sites queue');
    f359Check($result['workflow']->tenantRef===$cmd->tenantRef,
        'queued project never adopts a different tenant');

    $retry=SyntheticSitesEnvelopeSigner::sign(
        $payload,$commandKey,'f359-retry-nonce-00'.$i,$now,$now+60
    );
    $duplicate=SyntheticSitesOrderIngress::receive(
        $retry,$portal,$external,$commandKey,$portalKey,$externalKey,
        $now,$replay,$ledger,'site-f359-00'.$i,'workflow-f359-00'.$i
    );
    f359Check($duplicate['status']==='duplicate_ignored' &&
        $duplicate['workflow']===null,
        're-signed Portal delivery with same key does not enqueue twice');
    f359Check(f359Denied(static fn()=>SyntheticSitesOrderIngress::receive(
        $retry,$portal,$external,$commandKey,$portalKey,$externalKey,
        $now,$replay,$ledger,'site-f359-00'.$i,'workflow-f359-00'.$i)),
        'replayed Portal delivery denied even after idempotency duplicate');

    $revoked=new SyntheticAuthoritySnapshot(
        $cmd->tenantRef,$cmd->contractRef,$cmd->contractRevision,
        false,$now-1,$now+20
    );
    $revokedProof=SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation',$revoked,$externalKey
    );
    $fresh=SyntheticSitesEnvelopeSigner::sign(
        $payload,$commandKey,'f359-revoked-nonce-00'.$i,$now,$now+60
    );
    f359Check(f359Denied(static fn()=>SyntheticSitesOrderIngress::receive(
        $fresh,$portal,$revokedProof,$commandKey,$portalKey,$externalKey,
        $now,$replay,$ledger,'site-f359-00'.$i,'workflow-f359-00'.$i)),
        'authentic Portal order denied when independent authority revoked');
    $tampered=$fresh;
    $tampered['payload']['tenantRef']='tenant-f359-attacker';
    f359Check(f359Denied(static fn()=>SyntheticSitesOrderIngress::receive(
        $tampered,$portal,$external,$commandKey,$portalKey,$externalKey,
        $now,new F359ReplayStore(),$ledger,
        'site-f359-00'.$i,'workflow-f359-00'.$i)),
        'client tamper of Portal tenant cannot reach Sites project queue');
}
f359Check(f359Denied(static fn()=> (new SitesCreateProjectEncoder())
    ->build('order-f359-001','operation-f359-99','idem-f359-99')),
    'real Portal encoder defaults to DENY without trusted contract source');

echo "$checks F1-359 pinned Portal -> signed Sites admission and private queue checks passed.\n";
echo "NO-GO: public pinned Portal source, fictional commerce and keys, in-memory state only; no network, IAM, real payment or deployment.\n";
