<?php

declare(strict_types=1);

/**
 * F1-362: bridge TWO pinned real candidate source trees (Portal + Sites)
 * with a standalone, disposable PostgreSQL18 admission/outbox transaction.
 * No API, customers, real secrets, billing, or deployment.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_F360_PG_LAB') !== '1' ||
    getenv('SITES_F362_CROSS_STACK_LAB') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_PORT') !== '5432' ||
    getenv('DB_DATABASE') !== 'sites_f360_lab' ||
    getenv('DB_USERNAME') !== 'postgres' ||
    getenv('DB_PASSWORD') !== 'f360-fictional-admin-disposable-only') {
    fwrite(STDERR,"F1-362: refusing non-disposable or non-CI bridge.\n");
    exit(1);
}
$portalDir=(string)getenv('SITES_F362_PORTAL_PATH');
$sitesDir=(string)getenv('SITES_F362_SITES_PATH');
if (!is_file($portalDir.'/apps/portal/app/Domain/SitesIntegration/SyntheticSitesEnvelopeSigner.php') ||
    !is_file($sitesDir.'/src/Security/SyntheticSignedAuthorityAdmission.php')) {
    throw new RuntimeException('F1-362: pinned Portal/Sites sources not checked out.');
}

spl_autoload_register(static function (string $class) use ($portalDir,$sitesDir):void {
    foreach([
      'App\\Domain\\SitesIntegration\\' =>
        $portalDir.'/apps/portal/app/Domain/SitesIntegration/',
      'Imagine\\Sites\\Security\\' => $sitesDir.'/src/Security/',
      'Imagine\\Sites\\Integration\\' => $sitesDir.'/src/Integration/',
    ] as $prefix=>$base) {
        if(!str_starts_with($class,$prefix))continue;
        $name=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
            throw new RuntimeException('Invalid test class path.');
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
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;
use Imagine\Sites\Security\SyntheticSignedAuthorityAdmission;

final class F362ContractSource implements PortalSiteContractSource
{
    public function __construct(
        private readonly string $product,
        private readonly string $tenant='tenant-f360-b',
    ) {}
    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot
    {
        if($orderRef!=='order-f362')return null;
        return new SiteContractSnapshot($this->tenant,$orderRef,
            'contract-f362',1,$this->product);
    }
}
final class F362Replay implements ReplayStore
{
    private array $seen=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $k=$issuer.':'.$nonce;
        if(isset($this->seen[$k]))return false;
        $this->seen[$k]=true;
        return true;
    }
}

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$admin=new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',$opts);
$app=new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$opts);
if((int)$admin->query('SELECT count(*) FROM sites_f360.admissions')->fetchColumn()!==5) {
    throw new RuntimeException('F1-362: F1-360/F1-361 setup not applied in this CI job.');
}
$now=1790000000;
$commandKey='F362-FICTIONAL-PORTAL-COMMAND-KEY-NOT-PRODUCTION';
$portalKey='F362-FICTIONAL-PORTAL-AUTHORITY-KEY-NOT-PROD';
$externalKey='F362-FICTIONAL-INDEPENDENT-KEY-NOT-PRODUCTION';
$replay=new F362Replay();
$checks=0;
function f362Check(bool $valid,string $label):void
{
    global $checks;
    if(!$valid)throw new RuntimeException('F1-362: '.$label);
    ++$checks;
}
function f362Denied(callable $work):bool
{
    try{$work();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
function f362Proofs(SiteProjectCommand $cmd,bool $externalActive=true):array
{
    global $now,$portalKey,$externalKey;
    $portal=new SyntheticAuthoritySnapshot($cmd->tenantRef,$cmd->contractRef,
        $cmd->contractRevision,true,$now-1,$now+20);
    $external=new SyntheticAuthoritySnapshot($cmd->tenantRef,$cmd->contractRef,
        $cmd->contractRevision,$externalActive,$now-1,$now+20);
    return [
      SyntheticAuthorityAttestation::sign(
        'imagine-portal-authority',$portal,$portalKey),
      SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation',$external,$externalKey),
    ];
}
function f362Verify(array $signed,array $portal,array $external):SiteProjectCommand
{
    global $now,$commandKey,$portalKey,$externalKey,$replay;
    return SyntheticSignedAuthorityAdmission::admit($signed,$portal,$external,
        $commandKey,$portalKey,$externalKey,$now,$replay);
}
function f362Persist(PDO $app,SiteProjectCommand $cmd,string $site):string
{
    $stmt=$app->prepare(
        'SELECT sites_f360.admit_project(?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $cmd->tenantRef, $cmd->idempotencyKey, $site,
        $cmd->contractRef, $cmd->productCode, $cmd->contractRevision,
        $cmd->semanticFingerprint()
    ]);
    return (string)$stmt->fetchColumn();
}
function f362Sign(array $payload,string $nonce):array
{
    global $now,$commandKey;
    return SyntheticSitesEnvelopeSigner::sign(
        $payload,$commandKey,$nonce,$now,$now+60
    );
}

foreach(['onepage','landing_page'] as $i=>$product) {
    $payload=(new SitesCreateProjectEncoder(
        new F362ContractSource($product)
    ))->build('order-f362','operation-f362-'.$i,'idem-f362-'.$i);
    $cmd=SiteProjectCommand::fromArray($payload);
    [$portal,$external]=f362Proofs($cmd);
    $verified=f362Verify(f362Sign($payload,'nonce-f362-'.$i.'-first'),
        $portal,$external);
    f362Check($verified->tenantRef==='tenant-f360-b' &&
        $verified->productCode===$product,
        'actual pinned Portal command and dual proof verified: '.$product);
    f362Check(f362Persist($app,$verified,'site-f362-'.$i)==='queued',
        'signed candidate admitted to PG18 and outbox atomically: '.$product);

    $retry=f362Verify(f362Sign($payload,'nonce-f362-'.$i.'-retry'),
        $portal,$external);
    f362Check(f362Persist($app,$retry,'site-f362-'.$i)==='duplicate_ignored',
        'distinct signed delivery deduplicated in durable PG18: '.$product);

    $changed=$payload;
    $changed['tenantRef']='tenant-f360-a';
    f362Check(f362Denied(static fn()=>f362Verify(
        f362Sign($changed,'nonce-f362-'.$i.'-tamper'),$portal,$external)),
        'unsigned tenant modification rejected before DB');
    [$goodPortal,$revoked]=f362Proofs($cmd,false);
    f362Check(f362Denied(static fn()=>f362Verify(
        f362Sign($payload,'nonce-f362-'.$i.'-revoked'),
        $goodPortal,$revoked)),
        'genuine signed external revocation denies before DB');
}
$foreignPayload=(new SitesCreateProjectEncoder(
    new F362ContractSource('onepage','tenant-f360-a')
))->build('order-f362','operation-f362-foreign','idem-f362-foreign');
$foreignCommand=SiteProjectCommand::fromArray($foreignPayload);
[$foreignPortal,$foreignExternal]=f362Proofs($foreignCommand);
$foreignVerified=f362Verify(
    f362Sign($foreignPayload,'nonce-f362-foreign'),$foreignPortal,$foreignExternal
);
f362Check($foreignVerified->tenantRef==='tenant-f360-a',
    'validly signed foreign tenant claim remains A at trust boundary');
$crossDenied=false;
try {
    f362Persist($app,$foreignVerified,'site-f362-foreign');
} catch(PDOException $e) { $crossDenied=$e->getCode()==='42501'; }
f362Check($crossDenied,
    'PG18 tenant B SQL login refuses validly signed command for tenant A');

f362Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.admissions WHERE operation_ref LIKE 'idem-f362-%'"
)->fetchColumn()===2 &&
    (int)$admin->query(
    "SELECT count(*) FROM sites_f360.outbox WHERE operation_ref LIKE 'idem-f362-%'"
)->fetchColumn()===2,
    'exactly two signed orders created two durable admissions and outbox events');
f362Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.admissions
     WHERE operation_ref LIKE 'idem-f362-%' AND tenant_ref<>'tenant-f360-b'"
)->fetchColumn()===0,
    'no signed cross-tenant admission persisted under unrelated SQL login');

echo "$checks F1-362 pinned Portal/Sites authorization -> PG18 atomic outbox checks passed.\n";
echo "NO-GO: same-process synthetic signatures, public fixture keys, simulated IAM and disposable DB; no live HTTPS or payments.\n";
