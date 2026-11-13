<?php

declare(strict_types=1);

/**
 * F1-360: Persisted synthetic Portal purchase -> real Portal encoder/signature
 * -> Sites synthetic authority-gated, nonpublishable queue.
 *
 * This uses THREE separate exact candidate revisions and a disposable CI DB:
 * no customer identity, contract, payment confirmation, runtime S2S or deploy.
 * The fictional contract source below must NEVER be registered in an app.
 */
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use App\Homologation\RoleBoundTestPurchase;
use Imagine\Sites\Domain\SyntheticIdempotencyLedger;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\SyntheticSitesOrderIngress;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('SITES_F1_360_CROSS_REPO') !== '1') {
    fwrite(STDERR, "F1-360 denied outside explicitly isolated GitHub CI.\n");
    exit(1);
}
$signer = getenv('SITES_F1_360_PORTAL_SIGNER_PATH');
$purchase = getenv('SITES_F1_360_PORTAL_PURCHASE_PATH');
if (!is_string($signer) || !is_string($purchase)
    || !is_file($signer.'/apps/portal/app/Domain/SitesIntegration/SitesCreateProjectEncoder.php')
    || !is_file($purchase.'/apps/portal/app/Homologation/RoleBoundTestPurchase.php')) {
    throw new RuntimeException('Pinned Portal candidate code missing.');
}
spl_autoload_register(static function (string $class) use ($signer, $purchase): void {
    $roots = [
        'App\\Domain\\SitesIntegration\\' => $signer.'/apps/portal/app/Domain/SitesIntegration/',
        'App\\Homologation\\' => $purchase.'/apps/portal/app/Homologation/',
        'Imagine\\Sites\\Domain\\' => __DIR__.'/../src/Domain/',
        'Imagine\\Sites\\Integration\\' => __DIR__.'/../src/Integration/',
        'Imagine\\Sites\\Security\\' => __DIR__.'/../src/Security/',
        'Imagine\\Sites\\Production\\' => __DIR__.'/../src/Production/',
    ];
    foreach ($roots as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) continue;
        $short = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $short)) {
            throw new RuntimeException('Invalid candidate class name.');
        }
        require $dir.$short.'.php';
        return;
    }
});

/**
 * Synthetic source EXCLUSIVELY FOR THIS TEST: explicitly creates a fictional
 * contract after a fictional payment and persisted sample briefing. Does NOT
 * imply commercial eligibility, verified identity or real contract existence.
 */
final class F360SyntheticFulfilledOrderSource implements PortalSiteContractSource
{
    public function __construct(private readonly RoleBoundTestPurchase $store) {}

    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        try {
            $order = $this->store->read($orderRef);
        } catch (RuntimeException|InvalidArgumentException) {
            return null;
        }
        if (($order['synthetic'] ?? null) !== true
            || ($order['real_payment'] ?? null) !== false
            || ($order['real_contract'] ?? null) !== false
            || ($order['authorizes_http'] ?? null) !== false
            || ($order['payment'] ?? null) !== 'approved'
            || !is_array($order['briefing'] ?? null)
            || count($order['briefing']) !== 4) {
            return null;
        }
        $product = match ($order['product'] ?? null) {
            'onepage' => 'onepage',
            'landing-pages' => 'landing_page',
            default => null, // Performance cannot enter Sites.
        };
        if ($product === null) return null;
        return new SiteContractSnapshot(
            'test-org-a', $order['id'], 'fictional-contract-'.$order['id'],
            1, $product
        );
    }
}

final class F360ReplayStore implements ReplayStore
{
    private array $seen = [];
    public function rememberOnce(string $issuer, string $nonce, int $expiresAt): bool
    {
        $key=$issuer.':'.$nonce;
        if (isset($this->seen[$key])) return false;
        $this->seen[$key]=true;
        return true;
    }
}
$checks=0;
function f360Check(bool $valid,string $description):void
{
    global $checks;
    if (!$valid) throw new RuntimeException('F1-360 check failed: '.$description);
    ++$checks;
}
function f360Denied(callable $action):bool
{
    try { $action(); } catch (DomainException|InvalidArgumentException|LogicException|RuntimeException) {
        return true;
    }
    return false;
}

$db = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal',
    'purchase_rehearsal_a', 'ci-synthetic-a-only'
);
$store=new RoleBoundTestPurchase($db);
$encoder=new SitesCreateProjectEncoder(new F360SyntheticFulfilledOrderSource($store));
$externalDb = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal',
    'purchase_rehearsal_b', 'ci-synthetic-b-only'
);
$otherTenantStore=new RoleBoundTestPurchase($externalDb);
$ledger=new SyntheticIdempotencyLedger();
$replay=new F360ReplayStore();
$now=1790000000;
$commandKey='F360-PORTAL-SIGNED-SYNTHETIC-ENVELOPE-KEY-0001';
$portalAuthorityKey='F360-PORTAL-SYNTHETIC-AUTHORITY-KEY-0000002';
$externalAuthorityKey='F360-EXTERNAL-SYNTHETIC-REVOCATION-KEY-0003';

foreach (['onepage','landing-pages'] as $index=>$product) {
    $row=$store->create('TEST-f360-'.$product.'-000'.$index,$product);
    $id=$row['id'];
    $operation='operation-f360-000'.$index;
    $idem='idem-f360-000'.$index;
    f360Check(f360Denied(static fn()=> $encoder->build($id,$operation,$idem)),
        'pending fake payment cannot authorize Sites');

    $row=$store->payment($id,0,'declined');
    f360Check(f360Denied(static fn()=> $encoder->build($id,$operation,$idem)),
        'declined fake payment cannot authorize Sites');

    $row=$store->payment($id,1,'approved');
    f360Check(f360Denied(static fn()=> $encoder->build($id,$operation,$idem)),
        'approved fake payment without persisted briefing cannot authorize Sites');

    $row=$store->briefing($id,2,[
        'business'=>'Empresa ficticia F360',
        'goal'=>'Teste de pagina',
        'audience'=>'Publico simulado',
        'materials'=>'Logo ficticia',
    ]);
    f360Check(f360Denied(static fn()=> $otherTenantStore->read($id)),
        'restricted PostgreSQL role B cannot read role A order');

    $payload=$encoder->build($id,$operation,$idem);
    $command=SiteProjectCommand::fromArray($payload);
    $expected=$product==='onepage'?'onepage':'landing_page';
    f360Check($command->productCode===$expected && $command->orderRef===$id
        && $command->tenantRef==='test-org-a', 'canonical Sites mapping from durable Portal order');

    $snapshot=new SyntheticAuthoritySnapshot(
        $command->tenantRef,$command->contractRef,$command->contractRevision,
        true,$now-1,$now+20
    );
    $portal=SyntheticAuthorityAttestation::sign(
        'imagine-portal-authority',$snapshot,$portalAuthorityKey
    );
    $external=SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation',$snapshot,$externalAuthorityKey
    );
    $wire=SyntheticSitesEnvelopeSigner::sign(
        $payload,$commandKey,'f360-delivery-nonce-00'.$index,$now,$now+60
    );
    $enqueue=SyntheticSitesOrderIngress::receive(
        $wire,$portal,$external,$commandKey,$portalAuthorityKey,$externalAuthorityKey,
        $now,$replay,$ledger,'site-f360-00'.$index,'workflow-f360-00'.$index
    );
    f360Check($enqueue['status']==='queued'
        && $enqueue['workflow'] instanceof SyntheticProductionWorkflow
        && $enqueue['publishable']===false,
        'persisted sample order reaches exactly one private Sites queue item');

    $retry=SyntheticSitesEnvelopeSigner::sign(
        $payload,$commandKey,'f360-retry-nonce-00'.$index,$now,$now+60
    );
    $duplicate=SyntheticSitesOrderIngress::receive(
        $retry,$portal,$external,$commandKey,$portalAuthorityKey,$externalAuthorityKey,
        $now,$replay,$ledger,'site-f360-00'.$index,'workflow-f360-00'.$index
    );
    f360Check($duplicate['status']==='duplicate_ignored'
        && $duplicate['workflow']===null,
        're-signed delivery with same idempotency key does not queue again');
    f360Check(f360Denied(static fn()=> SyntheticSitesOrderIngress::receive(
        $retry,$portal,$external,$commandKey,$portalAuthorityKey,$externalAuthorityKey,
        $now,$replay,$ledger,'site-f360-00'.$index,'workflow-f360-00'.$index
    )), 'same signed delivery replay denied');

    $revoked=new SyntheticAuthoritySnapshot(
        $command->tenantRef,$command->contractRef,$command->contractRevision,
        false,$now-1,$now+20
    );
    $revocation=SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation',$revoked,$externalAuthorityKey
    );
    $fresh=SyntheticSitesEnvelopeSigner::sign(
        $payload,$commandKey,'f360-revoked-nonce-00'.$index,$now,$now+60
    );
    f360Check(f360Denied(static fn()=> SyntheticSitesOrderIngress::receive(
        $fresh,$portal,$revocation,$commandKey,$portalAuthorityKey,$externalAuthorityKey,
        $now,$replay,$ledger,'site-f360-00'.$index,'workflow-f360-00'.$index
    )), 'independent revocation overrides even approved sample order');

    $tampered=$fresh;
    $tampered['payload']['tenantRef']='test-org-b';
    f360Check(f360Denied(static fn()=> SyntheticSitesOrderIngress::receive(
        $tampered,$portal,$external,$commandKey,$portalAuthorityKey,$externalAuthorityKey,
        $now,new F360ReplayStore(),$ledger,
        'site-f360-00'.$index,'workflow-f360-00'.$index
    )), 'tenant tampering rejected before queue');
}

$performance=$store->create('TEST-f360-performance-0001','performance');
$store->payment($performance['id'],0,'approved');
$store->briefing($performance['id'],1,[
    'business'=>'Empresa ficticia','goal'=>'Campanha','audience'=>'Teste','materials'=>'Amostras'
]);
f360Check(f360Denied(static fn()=> $encoder->build(
    $performance['id'],'operation-f360-performance','idem-f360-performance'
)), 'Performance synthetic order cannot enter Sites');
f360Check(f360Denied(static fn()=> (new SitesCreateProjectEncoder())->build(
    'TEST-00000000000000000000000000000000','operation-f360-nocontract','idem-f360-nocontract'
)), 'Portal encoder defaults to denial without explicit trusted source');

echo "PASS F1-360: $checks cross-repo checks on durable synthetic Portal orders -> signed, fenced Sites queue.\n";
echo "NO-GO: branch-composed candidates, fictional contract/payments/keys, disposable PostgreSQL and no real identity or publish.\n";
