<?php

declare(strict_types=1);

/**
 * F1-364: execution of reusable CI-only adapter with pinned public Portal
 * and Sites source, then real disposable PG18 nonce/admission/outbox.
 */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F364_BRIDGE_LAB')!=='1' ||
    getenv('DB_DATABASE')!=='sites_f360_lab') {
    fwrite(STDERR,"F1-364: CI-only fixture disabled.\n");
    exit(1);
}
$sitesDir=(string)getenv('SITES_F362_SITES_PATH');
$portalDir=(string)getenv('SITES_F362_PORTAL_PATH');
if(!is_file($sitesDir.'/src/Security/SyntheticSignedAuthorityAdmission.php') ||
   !is_file($portalDir.'/apps/portal/app/Domain/SitesIntegration/SyntheticSitesEnvelopeSigner.php')) {
    throw new RuntimeException('Pinned candidate files unavailable.');
}
spl_autoload_register(static function (string $class) use ($sitesDir,$portalDir):void {
    foreach([
        'Imagine\\Sites\\Persistence\\'=>__DIR__.'/../src/Persistence/',
        'Imagine\\Sites\\Integration\\'=>$sitesDir.'/src/Integration/',
        'Imagine\\Sites\\Security\\'=>$sitesDir.'/src/Security/',
        'App\\Domain\\SitesIntegration\\'=>
            $portalDir.'/apps/portal/app/Domain/SitesIntegration/',
    ] as $prefix=>$base) {
        if(!str_starts_with($class,$prefix))continue;
        $name=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
            throw new RuntimeException('Unsafe class resolution.');
        }
        require $base.$name.'.php';
        return;
    }
});

use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Persistence\LabTransactionalSignedIngressV1;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;

final class F364ContractStub implements PortalSiteContractSource
{
    public function __construct(private readonly string $product,
        private readonly string $tenant='tenant-f360-b') {}
    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot
    {
        if($orderRef!=='order-f364')return null;
        return new SiteContractSnapshot($this->tenant,$orderRef,
            'contract-f364',1,$this->product);
    }
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$admin=new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',$opts);
$app=new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$opts);
if((int)$admin->query('SELECT count(*) FROM sites_f360.admissions')
    ->fetchColumn()!==8 ||
    (int)$admin->query('SELECT count(*) FROM sites_f360.replay_nonces')
    ->fetchColumn()!==2) {
    throw new RuntimeException('F1-364: F1-360..363 preconditions missing.');
}
$adapter=new LabTransactionalSignedIngressV1($app);
$now=time();
$cmdKey='F364-FICTIONAL-PORTAL-COMMAND-KEY-000000001';
$portalKey='F364-FICTIONAL-PORTAL-PROOF-KEY-000000002';
$externalKey='F364-FICTIONAL-EXTERNAL-PROOF-KEY-00000003';
$checks=0;
function f364Check(bool $ok,string $name):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('F1-364 FAIL: '.$name);
    ++$checks;
}
function f364Denied(callable $call):bool
{
    try{$call();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
function f364Proofs(SiteProjectCommand $cmd):array
{
    global $now,$portalKey,$externalKey;
    $snap=new SyntheticAuthoritySnapshot($cmd->tenantRef,$cmd->contractRef,
        $cmd->contractRevision,true,$now-1,$now+20);
    return [
      SyntheticAuthorityAttestation::sign(
        'imagine-portal-authority',$snap,$portalKey),
      SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation',$snap,$externalKey),
    ];
}
function f364Payload(SiteProjectCommand $cmd):array
{
    return [
        'schemaVersion'=>$cmd->schemaVersion,
        'operationId'=>$cmd->operationId,
        'tenantRef'=>$cmd->tenantRef,
        'orderRef'=>$cmd->orderRef,
        'contractRef'=>$cmd->contractRef,
        'contractRevision'=>$cmd->contractRevision,
        'productCode'=>$cmd->productCode,
        'idempotencyKey'=>$cmd->idempotencyKey,
    ];
}
function f364Sign(array $payload,string $nonce):array
{
    global $now,$cmdKey;
    return SyntheticSitesEnvelopeSigner::sign(
        $payload,$cmdKey,$nonce,$now,$now+60
    );
}
function f364Enqueue(LabTransactionalSignedIngressV1 $adapter,
    array $signed,?array $portal,?array $external,
    string $site,string $cmdKeyOverride=''):array
{
    global $now,$cmdKey,$portalKey,$externalKey;
    return $adapter->admit($signed,$portal,$external,
        $cmdKeyOverride!==''?$cmdKeyOverride:$cmdKey,
        $portalKey,$externalKey,$site,$now);
}

foreach(['onepage','landing_page'] as $i=>$product) {
    $payload=(new SitesCreateProjectEncoder(
        new F364ContractStub($product)
    ))->build('order-f364','operation-f364-'.$i,'idem-f364-'.$i);
    $cmd=SiteProjectCommand::fromArray($payload);
    [$portal,$external]=f364Proofs($cmd);
    $wire=f364Sign($payload,'nonce-f364-'.$i.'-first');
    $status=f364Enqueue($adapter,$wire,$portal,$external,'site-f364-'.$i);
    f364Check($status['status']==='queued' &&
        $status['tenant_ref']==='tenant-f360-b' &&
        $status['operation_ref']==='idem-f364-'.$i &&
        $status['publishable']===false,
        'reusable atomic adapter admits signed Portal '.$product);
    f364Check(!$app->inTransaction(),'adapter committed successful admission');
    f364Check(f364Denied(static fn()=>f364Enqueue(
        $adapter,$wire,$portal,$external,'site-f364-'.$i
    )), 'durable nonce rejects replay after adapter commit');
    $duplicate=f364Enqueue(
        $adapter,f364Sign($payload,'nonce-f364-'.$i.'-retry'),
        $portal,$external,'site-f364-'.$i
    );
    f364Check($duplicate['status']==='duplicate_ignored',
        'new signed retry uses persistent nonce but deduplicates project');
}

$bad=new SiteProjectCommand('1','operation-f364-fail','tenant-f360-b',
    'order-f364','contract-f364',1,'onepage','failoutbox-f360');
[$portalBad,$externalBad]=f364Proofs($bad);
$badSigned=f364Sign(f364Payload($bad),'nonce-f364-failure');
for($attempt=1;$attempt<=2;$attempt++) {
    $outboxDenied=false;
    try {
        f364Enqueue($adapter,$badSigned,$portalBad,$externalBad,
            'site-f364-fail');
    } catch(PDOException $e) {
        $outboxDenied=$e->getCode()==='23514';
    }
    f364Check($outboxDenied && !$app->inTransaction(),
        'outbox failure rolls back nonce and admission in adapter attempt '.$attempt);
}
f364Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.replay_nonces WHERE nonce='nonce-f364-failure'"
)->fetchColumn()===0,
    'failed adapter attempts did not poison durable nonce store');

$foreign=(new SitesCreateProjectEncoder(
    new F364ContractStub('onepage','tenant-f360-a')
))->build('order-f364','operation-f364-foreign','idem-f364-foreign');
$foreignCmd=SiteProjectCommand::fromArray($foreign);
[$foreignPortal,$foreignExternal]=f364Proofs($foreignCmd);
$crossDenied=false;
try {
    f364Enqueue($adapter,
        f364Sign($foreign,'nonce-f364-foreign'),
        $foreignPortal,$foreignExternal,'site-f364-foreign');
} catch(PDOException $e) { $crossDenied=$e->getCode()==='42501'; }
f364Check($crossDenied && !$app->inTransaction(),
    'valid foreign tenant signature rejected by DB login and rolled back');

$invalid=$foreign;
$invalid['tenantRef']='tenant-evil';
f364Check(f364Denied(static fn()=>f364Enqueue(
    $adapter,f364Sign($invalid,'nonce-f364-tampered'),
    $foreignPortal,$foreignExternal,'site-f364-tampered'
)), 'tampered Portal tenant fails before SQL insertion');

$validPayload=(new SitesCreateProjectEncoder(
    new F364ContractStub('onepage')
))->build('order-f364','operation-f364-gate','idem-f364-gate');
$validCmd=SiteProjectCommand::fromArray($validPayload);
[$validPortal,$validExternal]=f364Proofs($validCmd);
$gated=f364Sign($validPayload,'nonce-f364-gate');
putenv('SITES_F364_BRIDGE_LAB=0');
try {
    f364Check(f364Denied(static fn()=>f364Enqueue(
        $adapter,$gated,$validPortal,$validExternal,'site-f364-gate'
    )), 'adapter refuses execution without explicit CI lab flag');
} finally {
    putenv('SITES_F364_BRIDGE_LAB=1');
}
$app->beginTransaction();
try {
    f364Check(f364Denied(static fn()=>f364Enqueue(
        $adapter,$gated,$validPortal,$validExternal,'site-f364-gate'
    )), 'adapter refuses nested caller transaction');
} finally {
    if($app->inTransaction())$app->rollBack();
}

f364Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.admissions WHERE operation_ref LIKE 'idem-f364-%'"
)->fetchColumn()===2 &&
    (int)$admin->query(
    "SELECT count(*) FROM sites_f360.outbox WHERE operation_ref LIKE 'idem-f364-%'"
)->fetchColumn()===2 &&
    (int)$admin->query(
    "SELECT count(*) FROM sites_f360.replay_nonces WHERE nonce LIKE 'nonce-f364-%'"
)->fetchColumn()===4,
    'exactly 2 committed projects/outbox and 4 nonces for first+fresh retry');

echo "$checks F1-364 reusable signed admission adapter / PG18 transaction tests passed.\n";
echo "NO-GO: CI-only adapter relies on public synthetic credentials and pinned candidate code, not Portal IAM or production migrations.\n";
