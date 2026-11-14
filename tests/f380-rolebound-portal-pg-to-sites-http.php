<?php

declare(strict_types=1);

/**
 * F1-380: genuine Portal role-bound PostgreSQL synthetic purchase rows
 * become Portal-issued signed commands, then traverse HTTP -> Nginx ->
 * Laravel -> an INDEPENDENT private PostgreSQL18 Sites outbox.
 * This is a test of service integration, NOT real commerce or PSP evidence.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('SITES_F380_PORTAL_DB_LAB')!=='1' ||
    getenv('SITES_F377_CI_HTTP_LAB')!=='1' ||
    getenv('SITES_F380_PORTAL_PURCHASE_PATH')===false ||
    getenv('SITES_F380_PORTAL_SIGNER_PATH')===false) {
    fwrite(STDERR,"F1-380 requires isolated disposable CI opt-in.\n");
    exit(1);
}
$purchaseBase=(string)getenv('SITES_F380_PORTAL_PURCHASE_PATH');
$signerBase=(string)getenv('SITES_F380_PORTAL_SIGNER_PATH');
foreach ([
  $purchaseBase.'/apps/portal/app/Homologation/PersistedTestPurchase.php',
  $purchaseBase.'/apps/portal/app/Homologation/RoleBoundTestPurchase.php',
  $signerBase.'/apps/portal/app/Domain/SitesIntegration/PortalSiteContractSource.php',
  $signerBase.'/apps/portal/app/Domain/SitesIntegration/SiteContractSnapshot.php',
  $signerBase.'/apps/portal/app/Domain/SitesIntegration/SitesCreateProjectEncoder.php',
  $signerBase.'/apps/portal/app/Domain/SitesIntegration/SyntheticSitesEnvelopeSigner.php',
] as $path) {
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Pinned Portal candidate source missing.');
    }
    require_once $path;
}

use App\Homologation\RoleBoundTestPurchase;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;

const F380_KEY='F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI';
const F380_URL='http://127.0.0.1:18081/_ci/sites/v1/admit';
const F380_BRIEF_COMPANY='Fictitious Shop for CI-only Integration';

function f380Check(bool $yes,string $message):void {
    global $checks;
    if (!$yes) throw new RuntimeException('F1-380 FAIL: '.$message);
    ++$checks;
}
function f380Rejected(callable $call):bool {
    try { $call(); } catch (DomainException|\InvalidArgumentException|\RuntimeException) {return true;}
    return false;
}
function f380Http(array $signed,string $siteRef):array {
    $curl=curl_init(F380_URL);
    if ($curl===false) throw new RuntimeException('CI HTTP unavailable.');
    curl_setopt_array($curl,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(
            ['siteRef'=>$siteRef,'signed'=>$signed],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
        ),
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Imagine-CI-Transport: f377-local',
        ],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>8,
        CURLOPT_PROXY=>'',
    ]);
    $response=curl_exec($curl);
    $code=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($response)) throw new RuntimeException('CI request failed.');
    $decoded=json_decode($response,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Invalid Sites response.');
    return [$code,$decoded];
}
function f380Wire(array $payload,string $nonce):array {
    return SyntheticSitesEnvelopeSigner::sign(
        $payload,F380_KEY,$nonce,time(),time()+60
    );
}

/**
 * Reads the actual Portal PostgreSQL order table with FORCE RLS and
 * authenticated SQL session. This is NOT a real payment authority.
 */
final readonly class F380RoleBoundPortalOrderSource implements PortalSiteContractSource {
    public function __construct(private PDO $db) {
        $row=$db->query(
            "SELECT current_database() AS db,session_user AS role"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) ||
            ($row['db']??null)!=='portal_purchase_rehearsal' ||
            ($row['role']??null)!=='purchase_rehearsal_a') {
            throw new RuntimeException('Restricted Portal SQL role A required.');
        }
    }

    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot {
        if (preg_match('/\ATEST-[a-f0-9]{32}\z/D',$orderRef)!==1) return null;
        $q=$this->db->prepare(
            'SELECT id,product,payment,briefing FROM purchase_rehearsal.orders
             WHERE tenant=? AND id=? LIMIT 1'
        );
        $q->execute(['test-org-a',$orderRef]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['payment']??null)!=='approved' ||
            !in_array($row['product']??null,['onepage','landing-pages'],true) ||
            !is_string($row['briefing']??null)) return null;
        $brief=json_decode($row['briefing'],true,8);
        if (!is_array($brief) ||
            ($brief['business']??null)!==F380_BRIEF_COMPANY ||
            count($brief)!==4 ||
            array_diff(array_keys($brief),['business','goal','audience','materials'])!==[]) {
            return null;
        }
        return new SiteContractSnapshot(
            'tenant-f360-b',$orderRef,
            'contract-f380-'.substr($orderRef,5),1,
            $row['product']==='landing-pages'?'landing_page':'onepage',
        );
    }
}

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal';
$opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$a=new PDO($dsn,'purchase_rehearsal_a','ci-synthetic-a-only',$opt);
$b=new PDO($dsn,'purchase_rehearsal_b','ci-synthetic-b-only',$opt);
$portal=new RoleBoundTestPurchase($a);
$foreignPortal=new RoleBoundTestPurchase($b);
$source=new F380RoleBoundPortalOrderSource($a);
$encoder=new SitesCreateProjectEncoder($source);
$checks=0;
f380Check($portal->organization()==='test-org-a' &&
    $foreignPortal->organization()==='test-org-b',
    'Portal actual PostgreSQL identities bind separate organizations');

$brief=[
    'business'=>F380_BRIEF_COMPANY,
    'goal'=>'Publicize a fictitious CI-only service',
    'audience'=>'Fictitious recipient set',
    'materials'=>'Synthetic graphics only',
];
$success=[];
foreach (['onepage','landing-pages'] as $product) {
    $created=$portal->create(
        'TEST-f380-'.$product.'-'.bin2hex(random_bytes(8)),
        $product
    );
    f380Check($created['payment']==='pending' &&
        $source->findAuthorizedSiteOrder($created['id'])===null,
        'pending Portal DB order must not produce a Sites command');
    $approved=$portal->payment($created['id'],$created['revision'],'approved');
    f380Check($source->findAuthorizedSiteOrder($created['id'])===null,
        'fake approval without briefing is still not eligible');
    $stored=$portal->briefing(
        $created['id'],$approved['revision'],$brief
    );
    f380Check($stored['real_payment']===false &&
        $stored['authorizes_http']===false &&
        $source->findAuthorizedSiteOrder($created['id']) instanceof SiteContractSnapshot,
        'real Portal DB contains a fully briefed fake-approved synthetic order');

    $suffix=substr($created['id'],5);
    $payload=$encoder->build(
        $created['id'],'operation-f380-'.$suffix,'idem-f380-'.$suffix
    );
    $wire=f380Wire($payload,'nonce-f380-'.$suffix.'-one');
    [$code,$response]=f380Http($wire,'site-f380-'.$suffix);
    if ($code!==202) {
        fwrite(STDERR,'F1-380 safe HTTP status='.$code.
            ' error='.(string)($response['error']??'none')."\n");
    }
    f380Check($code===202 &&
        ($response['status']??null)==='queued' &&
        ($response['operationRef']??null)==='idem-f380-'.$suffix &&
        ($response['publishable']??null)===false &&
        ($response['realOrderAccepted']??null)===false,
        'Portal RLS-backed fake order -> signed HTTP -> Sites PG18 outbox');
    f380Check(f380Http($wire,'site-f380-'.$suffix)[0]===403,
        'durable nonce rejects replay of actual Portal-signed command');
    $new=f380Wire($payload,'nonce-f380-'.$suffix.'-two');
    [$retry,$seen]=f380Http($new,'site-f380-'.$suffix);
    f380Check($retry===200 && ($seen['status']??null)==='duplicate_ignored',
        'new nonce idempotently reuses same Portal order');
    $success[]=$created['id'];
}

$performance=$portal->create(
    'TEST-f380-performance-'.bin2hex(random_bytes(8)), 'performance'
);
$performanceApproved=$portal->payment(
    $performance['id'],$performance['revision'],'approved'
);
$portal->briefing($performance['id'],$performanceApproved['revision'],$brief);
f380Check(f380Rejected(static fn()=>$encoder->build(
    $performance['id'],'operation-f380-performance','idem-f380-performance'
)), 'paid-looking Performance order excluded from Sites');

$pending=$portal->create(
    'TEST-f380-pending-'.bin2hex(random_bytes(8)),'landing-pages'
);
f380Check(f380Rejected(static fn()=>$encoder->build(
    $pending['id'],'operation-f380-pending','idem-f380-pending'
)), 'pending Portal DB purchase cannot be serialized for Sites');

$unbriefed=$portal->create(
    'TEST-f380-unbriefed-'.bin2hex(random_bytes(8)),'onepage'
);
$portal->payment($unbriefed['id'],$unbriefed['revision'],'approved');
f380Check(f380Rejected(static fn()=>$encoder->build(
    $unbriefed['id'],'operation-f380-unbriefed','idem-f380-unbriefed'
)), 'mock-approved Portal order without briefing cannot be signed');

$foreign=$foreignPortal->create(
    'TEST-f380-foreign-'.bin2hex(random_bytes(8)),'onepage'
);
$fa=$foreignPortal->payment($foreign['id'],$foreign['revision'],'approved');
$foreignPortal->briefing($foreign['id'],$fa['revision'],$brief);
$q=$a->prepare('SELECT count(*) FROM purchase_rehearsal.orders WHERE id=?');
$q->execute([$foreign['id']]);
f380Check((int)$q->fetchColumn()===0,
    'SQL FORCE RLS hides fully approved foreign order even when company name matches');
f380Check(f380Rejected(static fn()=>$encoder->build(
    $foreign['id'],'operation-f380-foreign','idem-f380-foreign'
)), 'Portal sender cannot issue command for a different SQL organization');

$test=f380Wire(
    $encoder->build(
        $success[0],'operation-f380-forged-twin','idem-f380-forged-twin'
    ),
    'nonce-f380-forged-tenant'
);
$test['payload']['tenantRef']='tenant-f360-a';
f380Check(f380Http($test,'site-f380-forged-tenant')[0]===403,
    'mutating signed tenant invalidates HMAC at Sites receiver');

echo "$checks F1-380 checks: real RLS-bound Portal PG synthetic order -> signer -> HTTP -> Sites PG18 passed.\n";
echo "NO-GO: all payments, identities, signing keys, brands and orders are fictional in disposable CI.\n";
