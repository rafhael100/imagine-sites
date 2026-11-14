<?php

declare(strict_types=1);

/**
 * F1-381: two independent real PG18 databases, one fake Portal purchase
 * source with SQL FORCE RLS, and Sites HTTP->Laravel->verified TLS PG18.
 *
 * Both customer accounts and payment outcomes are fictional. This is NOT
 * a production PSP, authorization API or customer entitlement proof.
 */
if(PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F381_PURCHASE_HTTP_LAB')!=='1' ||
   getenv('SITES_F377_CI_HTTP_LAB')!=='1') {
    fwrite(STDERR,"F1-381: disposable purchase CI fixture denied.\n");
    exit(1);
}
$purchasePath=(string)getenv('SITES_F381_PORTAL_PURCHASE_PATH');
$signerPath=(string)getenv('SITES_F378_PORTAL_PATH');
$purchaseBase=$purchasePath.'/apps/portal/app/Homologation/';
$signerBase=$signerPath.'/apps/portal/app/Domain/SitesIntegration/';
if($purchasePath==='' || $signerPath==='' ||
    is_link($purchasePath) || is_link($signerPath) ||
    !is_file($purchaseBase.'RoleBoundTestPurchase.php') ||
    !is_file($signerBase.'SitesCreateProjectEncoder.php')) {
    throw new RuntimeException('Required immutable Portal sources unavailable.');
}
foreach(['PersistedTestPurchase.php','RoleBoundTestPurchase.php'] as $file) {
    require_once $purchaseBase.$file;
}
foreach([
    'SiteContractSnapshot.php',
    'PortalSiteContractSource.php',
    'SitesCreateProjectEncoder.php',
    'SyntheticSitesEnvelopeSigner.php',
] as $file) {
    require_once $signerBase.$file;
}

use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use App\Homologation\RoleBoundTestPurchase;

const F381_FAKE_SIGNER='F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI';
const F381_FAKE_BUSINESS='F381 fictional enterprise';
const F381_HTTP_URL='http://127.0.0.1:18081/_ci/sites/v1/admit';

function f381Db(string $role,string $password):PDO {
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
    $db=new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal;connect_timeout=5',
        $role,$password,$options,
    );
    if((string)$db->query('SELECT current_database()')->fetchColumn()!==
        'portal_purchase_rehearsal' ||
        (string)$db->query('SELECT session_user')->fetchColumn()!==$role ||
        (int)$db->query('SHOW server_version_num')->fetchColumn()<180000) {
        throw new RuntimeException('Wrong disposable Portal PostgreSQL role.');
    }
    return $db;
}
$dbA=f381Db('purchase_rehearsal_a','ci-synthetic-a-only');
$dbB=f381Db('purchase_rehearsal_b','ci-synthetic-b-only');
$storeA=new RoleBoundTestPurchase($dbA);
$storeB=new RoleBoundTestPurchase($dbB);

final readonly class F381RoleBoundPaidBriefedSource implements PortalSiteContractSource
{
    public function __construct(private PDO $roleBoundPortalDb) {}
    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot
    {
        if(preg_match('/\ATEST-[a-f0-9]{32}\z/D',$orderRef)!==1)return null;
        $q=$this->roleBoundPortalDb->prepare(
            "SELECT id,product,payment,briefing FROM purchase_rehearsal.orders
              WHERE id=? AND tenant='test-org-a'"
        );
        $q->execute([$orderRef]);
        $order=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($order) || $order['payment']!=='approved' ||
           !in_array($order['product'],['onepage','landing-pages'],true) ||
           !is_string($order['briefing']??null))return null;
        try{$brief=json_decode($order['briefing'],true,8,JSON_THROW_ON_ERROR);}
        catch(JsonException){return null;}
        if(!is_array($brief) || ($brief['business']??null)!==F381_FAKE_BUSINESS ||
           !is_string($brief['goal']??null) ||
           !is_string($brief['audience']??null) ||
           !is_string($brief['materials']??null))return null;
        return new SiteContractSnapshot(
            'tenant-f360-b',$order['id'],
            'contract-p0-'.substr($order['id'],5),1,
            $order['product']==='onepage'?'onepage':'landing_page',
        );
    }
}
$source=new F381RoleBoundPaidBriefedSource($dbA);
$encoder=new SitesCreateProjectEncoder($source);
$checks=0;
function f381Check(bool $ok,string $message):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-381 FAIL: '.$message);
    ++$checks;
}
function f381Denied(callable $call):bool {
    try{$call();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
function f381Create(
    RoleBoundTestPurchase $actor,string $key,string $product,
    bool $pay=true,bool $brief=true,
):array {
    $row=$actor->create($key,$product);
    if($pay) $row=$actor->payment($row['id'],$row['revision'],'approved');
    if($brief && $pay) {
        $row=$actor->briefing($row['id'],$row['revision'],[
            'business'=>F381_FAKE_BUSINESS,
            'goal'=>'Synthetic service purchase',
            'audience'=>'Only fictional customers',
            'materials'=>'No client information',
        ]);
    }
    return $row;
}
function f381Wire(
    SitesCreateProjectEncoder $encoder,
    string $orderRef,string $suffix,string $nonce,
):array {
    $payload=$encoder->build(
        $orderRef,'operation-f381-'.$suffix,'idem-f381-'.$suffix
    );
    return SyntheticSitesEnvelopeSigner::sign(
        $payload,F381_FAKE_SIGNER,$nonce,time(),time()+60
    );
}
function f381Http(array $wire,string $siteRef):array {
    $c=curl_init(F381_HTTP_URL);
    if($c===false)throw new RuntimeException('Missing CI HTTP client');
    curl_setopt_array($c,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode([
            'siteRef'=>$siteRef,'signed'=>$wire
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Imagine-CI-Transport: f377-local',
        ],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>8,CURLOPT_PROXY=>'',
    ]);
    $raw=curl_exec($c);
    $status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
    curl_close($c);
    if(!is_string($raw))throw new RuntimeException('Sites HTTP response not received.');
    $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('Invalid Sites HTTP response.');
    return [$status,$data];
}

$eligible=[];
foreach(['onepage','landing-pages'] as $product) {
    $eligible[]=f381Create(
        $storeA,'TEST-f381-'.str_replace('-','',$product).'-01',$product
    );
}
$performance=f381Create($storeA,'TEST-f381-performance-01','performance');
$pending=f381Create($storeA,'TEST-f381-pending-site-01','onepage',false,false);
$unbriefed=f381Create(
    $storeA,'TEST-f381-unbriefed-site-01','landing-pages',true,false
);
$foreign=f381Create($storeB,'TEST-f381-foreign-site-01','onepage');
f381Check($storeA->organization()==='test-org-a' &&
    $storeB->organization()==='test-org-b',
    'Portal contract source uses real DB-authenticated organization roles');

foreach($eligible as $item) {
    $id=$item['id'];
    $suffix=substr($id,5);
    f381Check(is_array($source->findAuthorizedSiteOrder($id)),
        'paid and briefed synthetic Portal PG18 Site contract visible');
    $wire=f381Wire($encoder,$id,$suffix,'nonce-f381-'.$suffix.'-first');
    [$code,$reply]=f381Http($wire,'site-f381-'.$suffix);
    f381Check($code===202 &&
        ($reply['status']??null)==='queued' &&
        ($reply['operationRef']??null)==='idem-f381-'.$suffix &&
        ($reply['realOrderAccepted']??null)===false,
        'actual Portal PostgreSQL order signed and sent to Sites Laravel PG18');
    f381Check(f381Http($wire,'site-f381-'.$suffix)[0]===403,
        'real HTTP replay protected in Sites nonce store');
    [$retry,$result]=f381Http(
        f381Wire($encoder,$id,$suffix,'nonce-f381-'.$suffix.'-second'),
        'site-f381-'.$suffix
    );
    f381Check($retry===200 &&
        ($result['status']??null)==='duplicate_ignored',
        'new nonce deduplicates same Portal database-backed order');
}
foreach([$performance,$pending,$unbriefed] as $notEligible) {
    f381Check(f381Denied(static fn()=> $encoder->build(
        $notEligible['id'],'operation-f381-forbidden','idem-f381-forbidden'
    )),'performance/unpaid/unbriefed order rejected before signing');
}
$q=$dbA->prepare('SELECT count(*) FROM purchase_rehearsal.orders WHERE id=?');
$q->execute([$foreign['id']]);
f381Check((int)$q->fetchColumn()===0,
    'role A cannot read role B fully completed mock purchase even at SQL level');
f381Check(f381Denied(static fn()=> $encoder->build(
    $foreign['id'],'operation-f381-foreign','idem-f381-foreign'
)), 'foreign organization cannot be admitted via Portal encoder');

$valid=f381Wire(
    $encoder,$eligible[0]['id'],
    substr($eligible[0]['id'],5),'nonce-f381-tenant-a-forgery'
);
$valid['payload']['tenantRef']='tenant-f360-a';
$unsigned=array_slice($valid,0,-1,true);
$valid['mac']=hash_hmac('sha256',json_encode(
    $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
),F381_FAKE_SIGNER);
f381Check(f381Http($valid,'site-f381-wrong-tenant')[0]===403,
    'Sites authenticated SQL login refuses independently signed foreign tenant');

f381Check(RoleBoundTestPurchase::authorizesCustomerHttp()===false,
    'pinned Portal purchase adapter explicitly refuses operational HTTP authority');
echo "$checks F1-381 Portal PG18 fictitious paid+briefed source -> signed HTTP -> Sites PG18 TLS checks passed.\n";
echo "NO-GO: payment is fake, Portal source test-only; no PSP, customer IAM, or production entitlement.\n";
