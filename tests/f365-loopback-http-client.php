<?php

declare(strict_types=1);

/**
 * F1-365: genuine localhost HTTP requests across PHP processes using the
 * pinned Portal candidate encoder/signer and a disposable PostgreSQL18 DB.
 * All credentials and contracts are fictional. Never call real services.
 */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
   getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F365_HTTP_LAB')!=='1' ||
   getenv('SITES_F364_BRIDGE_LAB')!=='1' ||
   getenv('DB_DATABASE')!=='sites_f360_lab') {
    fwrite(STDERR,"F1-365: refusing non-CI loopback test.\n");
    exit(1);
}
$portalDir=(string)getenv('SITES_F362_PORTAL_PATH');
$sitesDir=(string)getenv('SITES_F362_SITES_PATH');
if(!is_file($portalDir.'/apps/portal/app/Domain/SitesIntegration/SyntheticSitesEnvelopeSigner.php') ||
   !is_file($sitesDir.'/src/Security/SyntheticAuthorityAttestation.php')) {
    throw new RuntimeException('Pinned public Portal/Sites fixture sources unavailable.');
}
spl_autoload_register(static function(string $class) use($portalDir,$sitesDir):void {
    foreach([
        'App\\Domain\\SitesIntegration\\'=>
            $portalDir.'/apps/portal/app/Domain/SitesIntegration/',
        'Imagine\\Sites\\Integration\\'=>$sitesDir.'/src/Integration/',
        'Imagine\\Sites\\Security\\'=>$sitesDir.'/src/Security/',
    ] as $prefix=>$dir) {
        if(!str_starts_with($class,$prefix))continue;
        $short=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
            throw new RuntimeException('Rejected fictional class reference.');
        }
        require $dir.$short.'.php';
        return;
    }
});
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;

final class F365PortalContractFixture implements PortalSiteContractSource
{
    public function __construct(
        private readonly string $product,
        private readonly string $tenant='tenant-f360-b'
    ) {}
    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot
    {
        if($orderRef!=='order-f365')return null;
        return new SiteContractSnapshot($this->tenant,$orderRef,
            'contract-f365',1,$this->product);
    }
}
$checks=0;
function f365Check(bool $value,string $description):void {
    global $checks;
    if(!$value)throw new RuntimeException('F1-365 FAIL: '.$description);
    ++$checks;
}
function f365Request(
    string $method,
    string $body='',
    string $type='application/json',
    string $path='/v1/lab/sites/admit',
    array $additionalHeaders=[]
):array {
    $url='http://127.0.0.1:18965'.$path;
    $ch=curl_init($url);
    if($ch===false)throw new RuntimeException('Unable to create loopback client.');
    $options=[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>6,
        CURLOPT_PROXY=>'',
        CURLOPT_HTTPHEADER=>array_merge([
            'Content-Type: '.$type,'Accept: application/json'
        ],$additionalHeaders),
        CURLOPT_CUSTOMREQUEST=>$method,
    ];
    if($method==='POST')$options[CURLOPT_POSTFIELDS]=$body;
    curl_setopt_array($ch,$options);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    if(!is_string($raw))throw new RuntimeException('Loopback failure: '.$error);
    try{$json=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){throw new RuntimeException('Invalid response from lab HTTP');}
    if(!is_array($json))throw new RuntimeException('Invalid HTTP JSON shape.');
    return [$status,$json,$raw];
}
function f365Command(string $product,string $tenant='tenant-f360-b',
    string $idempotency=''):array {
    $encoder=new SitesCreateProjectEncoder(
        new F365PortalContractFixture($product,$tenant)
    );
    return $encoder->build('order-f365','operation-f365-'.$product,
        $idempotency!==''?$idempotency:'idem-f365-'.$product);
}
function f365Proofs(SiteProjectCommand $command,bool $externalActive=true):array {
    $now=time();
    $portal=new SyntheticAuthoritySnapshot(
        $command->tenantRef,$command->contractRef,$command->contractRevision,
        true,$now-1,$now+25
    );
    $external=new SyntheticAuthoritySnapshot(
        $command->tenantRef,$command->contractRef,$command->contractRevision,
        $externalActive,$now-1,$now+25
    );
    return [
        SyntheticAuthorityAttestation::sign('imagine-portal-authority',$portal,
            'F365-FICTIONAL-PORTAL-AUTHORITY-KEY-0002'),
        SyntheticAuthorityAttestation::sign('imagine-independent-revocation',$external,
            'F365-FICTIONAL-EXTERNAL-REVOCATION-KEY-03')
    ];
}
function f365Envelope(array $payload,string $nonce,bool $externalActive=true,
    string $siteRef='f365-site'):string {
    $now=time();
    $cmd=SiteProjectCommand::fromArray($payload);
    [$portal,$external]=f365Proofs($cmd,$externalActive);
    $signed=SyntheticSitesEnvelopeSigner::sign(
        $payload,'F365-FICTIONAL-PORTAL-COMMAND-KEY-00001',
        $nonce,$now,$now+60
    );
    return json_encode([
        'signedCommand'=>$signed,
        'portalProof'=>$portal,
        'externalProof'=>$external,
        'siteRef'=>$siteRef,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
function f365Accepted(array $response,int $http,string $status):bool {
    return $response[0]===$http &&
        $response[1]['status']===$status &&
        $response[1]['accepted']===true &&
        $response[1]['publishable']===false &&
        $response[1]['customerAcceptance']===false;
}
function f365Rejected(array $response,int $http,string $status):bool {
    return $response[0]===$http &&
        $response[1]['status']===$status &&
        $response[1]['accepted']===false &&
        $response[1]['publishable']===false &&
        $response[1]['customerAcceptance']===false &&
        !str_contains($response[2],'SQLSTATE') &&
        !str_contains($response[2],'Exception') &&
        !str_contains($response[2],'F365-FICTIONAL');
}

$pdo=new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5',
    'postgres','f360-fictional-admin-disposable-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
$before=(int)$pdo->query('SELECT count(*) FROM sites_f360.admissions')->fetchColumn();
$beforeEvents=(int)$pdo->query('SELECT count(*) FROM sites_f360.outbox')->fetchColumn();
$beforeNonces=(int)$pdo->query('SELECT count(*) FROM sites_f360.replay_nonces')->fetchColumn();

$health=f365Request('GET','','application/json','/health');
f365Check($health[0]===200 && ($health[1]['status']??'')==='test-only',
    'real loopback PHP HTTP listener answers health check');
f365Check(f365Request('GET')[0]===405,
    'non-POST HTTP method refused');
f365Check(f365Request('POST','{}','text/plain')[0]===415,
    'non-JSON HTTP content type refused');
f365Check(f365Request('POST','{invalid}')[0]===400,
    'malformed JSON refused without server crash');
f365Check(f365Request('POST',str_repeat('x',8200))[0]===413,
    'oversized request refused before worker');
f365Check(f365Request('POST','{}','application/json','/unknown')[0]===404,
    'unregistered routes do not leak test worker');
f365Check(f365Request('POST','{}','application/json',
    '/v1/lab/sites/admit',['Host: malicious.example'])[0]===404,
    'untrusted Host header rejected by loopback router');

foreach(['onepage','landing_page'] as $index=>$product) {
    $payload=f365Command($product);
    $site='f365-site-'.$product;
    $first=f365Envelope($payload,'f365-'.$product.'-first',true,$site);
    f365Check(f365Accepted(f365Request('POST',$first),202,'queued'),
        'actual Portal signer sent HTTP POST to Sites PG18 ingress: '.$product);
    f365Check(f365Rejected(f365Request('POST',$first),403,'denied'),
        'repeating signed HTTP command across worker process rejected: '.$product);
    $retry=f365Envelope($payload,'f365-'.$product.'-retry',true,$site);
    f365Check(f365Accepted(f365Request('POST',$retry),200,'duplicate_ignored'),
        'distinct signed retry passes nonce but deduplicates durable project: '.$product);

    $altered=json_decode(
        f365Envelope($payload,'f365-'.$product.'-tamper',true,$site),
        true,16,JSON_THROW_ON_ERROR
    );
    $altered['signedCommand']['payload']['tenantRef']='tenant-attacker';
    f365Check(f365Rejected(f365Request('POST',json_encode(
        $altered,JSON_THROW_ON_ERROR
    )),403,'denied'),'signed tenant alteration rejected: '.$product);

    $revoked=f365Envelope($payload,'f365-'.$product.'-revoked',false,$site);
    f365Check(f365Rejected(f365Request('POST',$revoked),403,'denied'),
        'signed independently revoked contract rejected over HTTP: '.$product);
}

$foreign=f365Command('onepage','tenant-f360-a','idem-f365-foreign');
f365Check(f365Rejected(f365Request('POST',f365Envelope(
    $foreign,'f365-foreign-tenant',true,'f365-foreign-site'
)),403,'denied'),'valid signed tenant A refused by Sites tenant B SQL login');

$malformed=json_decode(f365Envelope(
    f365Command('onepage'),'f365-invalid-site',true,'f365-valid'
),true,16,JSON_THROW_ON_ERROR);
$malformed['siteRef']='../../unauthorized';
f365Check(f365Rejected(f365Request('POST',json_encode(
    $malformed,JSON_THROW_ON_ERROR
)),400,'invalid'),'path traversal siteRef refused at protocol boundary');

$fail=f365Command('onepage','tenant-f360-b','failoutbox-f360');
$failBody=f365Envelope($fail,'f365-outbox-failure',true,'f365-outbox-site');
f365Check(f365Rejected(f365Request('POST',$failBody),503,'error'),
    'injected outbox DB failure denied with no private error leakage');
f365Check(f365Rejected(f365Request('POST',$failBody),503,'error'),
    'exact nonce may retry when prior outbox failure rolled back entire transaction');

$after=(int)$pdo->query('SELECT count(*) FROM sites_f360.admissions')->fetchColumn();
$afterEvents=(int)$pdo->query('SELECT count(*) FROM sites_f360.outbox')->fetchColumn();
$afterNonces=(int)$pdo->query('SELECT count(*) FROM sites_f360.replay_nonces')->fetchColumn();
f365Check($after===$before+2 && $afterEvents===$beforeEvents+2,
    'HTTP accepted exactly two atomic PG admission + outbox pairs');
f365Check($afterNonces===$beforeNonces+4,
    'only four accepted signed deliveries persist durable nonce; all denied remain absent');
f365Check((int)$pdo->query(
    "SELECT count(*) FROM sites_f360.admissions
     WHERE operation_ref IN ('idem-f365-onepage','idem-f365-landing_page')"
)->fetchColumn()===2,'both product types durably admitted exactly once');
f365Check((int)$pdo->query(
    "SELECT count(*) FROM sites_f360.outbox
     WHERE operation_ref='failoutbox-f360'"
)->fetchColumn()===0,'failing request creates no orphan outbox');
f365Check((int)$pdo->query(
    "SELECT count(*) FROM sites_f360.replay_nonces
     WHERE nonce='f365-outbox-failure'"
)->fetchColumn()===0,'failing HTTP request never commits replay token');

echo "$checks F1-365 loopback HTTP Portal signer -> independent PHP worker -> PG18 tests passed.\n";
echo "NO-GO: synthetic keys, pinned code, loopback CI server and disposable DB. No real Portal/Sites service, checkout, domain or publication.\n";
