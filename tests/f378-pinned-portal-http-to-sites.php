<?php

declare(strict_types=1);

/**
 * F1-378: compile ACTUAL pinned Portal source into a synthetic HTTP client
 * that calls Sites' CI-only Nginx/Laravel/PG18 receiver. Not a Portal
 * application runtime, real contract verification or production payment.
 */
if(PHP_SAPI!=='cli' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F377_CI_HTTP_LAB')!=='1' ||
    getenv('SITES_F378_PINNED_PORTAL_LAB')!=='1') {
    fwrite(STDERR,"F1-378 requires pinned Portal code and CI-only opt-in.\n");
    exit(1);
}
$portal=(string)getenv('SITES_F378_PORTAL_PATH');
$base=$portal.'/apps/portal/app/Domain/SitesIntegration/';
if($portal==='' || !is_dir($base) || is_link($portal) ||
   !is_file($base.'SyntheticSitesEnvelopeSigner.php')) {
    throw new RuntimeException('Missing immutable Portal candidate.');
}
foreach([
    'SiteContractSnapshot.php',
    'PortalSiteContractSource.php',
    'SitesCreateProjectEncoder.php',
    'SyntheticSitesEnvelopeSigner.php',
] as $file) {
    require_once $base.$file;
}

use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;

const F378_SYNTHETIC_KEY='F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI';
const F378_URL='http://127.0.0.1:18081/_ci/sites/v1/admit';

final readonly class F378FakePortalContractSource implements PortalSiteContractSource
{
    public function __construct(
        private string $product,
        private string $tenant='tenant-f360-b',
        private int $revision=1,
        private bool $active=true,
    ) {}

    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot
    {
        if(!$this->active || $orderRef!=='order-f377')return null;
        return new SiteContractSnapshot(
            $this->tenant,$orderRef,'contract-f377',
            $this->revision,$this->product,
        );
    }
}
function f378Envelope(
    string $product,string $id,string $nonce,
    string $tenant='tenant-f360-b',int $revision=1,
    string $key=F378_SYNTHETIC_KEY,
):array {
    $builder=new SitesCreateProjectEncoder(
        new F378FakePortalContractSource($product,$tenant,$revision)
    );
    $payload=$builder->build(
        'order-f377','operation-'.$id,'idem-'.$id
    );
    return SyntheticSitesEnvelopeSigner::sign(
        $payload,$key,$nonce,time(),time()+60
    );
}
function f378Post(array $envelope,string $siteRef):array
{
    $c=curl_init(F378_URL);
    if($c===false)throw new RuntimeException('cURL unavailable');
    curl_setopt_array($c,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode([
            'siteRef'=>$siteRef,'signed'=>$envelope,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json','Accept: application/json',
            'X-Imagine-CI-Transport: f377-local',
        ],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>7,
        CURLOPT_PROXY=>'',
    ]);
    $raw=curl_exec($c);
    $code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
    curl_close($c);
    if(!is_string($raw))throw new RuntimeException('Portal candidate HTTP request failed.');
    $response=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($response))throw new RuntimeException('Invalid response.');
    return [$code,$response];
}
$checks=0;
function f378Check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('F1-378 FAIL: '.$label);
    ++$checks;
}
foreach(['onepage','landing_page'] as $product) {
    $id='f378-'.$product;
    $nonce='nonce-'.$id.'-first';
    $envelope=f378Envelope($product,$id,$nonce);
    [$status,$reply]=f378Post($envelope,'site-'.$id);
    f378Check($status===202 &&
        ($reply['status']??null)==='queued' &&
        ($reply['operationRef']??null)==='idem-'.$id &&
        ($reply['realOrderAccepted']??null)===false,
        'pinned Portal encoder/signer -> real HTTP -> Laravel PG18 '.$product);
    f378Check(f378Post($envelope,'site-'.$id)[0]===403,
        'signed Portal replay denied by durable Sites PostgreSQL nonce');
    [$retry,$result]=f378Post(
        f378Envelope($product,$id,'nonce-'.$id.'-second'),
        'site-'.$id
    );
    f378Check($retry===200 &&
        ($result['status']??null)==='duplicate_ignored',
        'pinned Portal fresh nonce causes idempotent original admission');
}
f378Check(f378Post(
    f378Envelope('onepage','f378-foreign','nonce-f378-foreign',
        'tenant-f360-a'
    ),'site-f378-foreign'
)[0]===403,
    'valid Portal-signed foreign tenant refused by Sites PostgreSQL login');
f378Check(f378Post(
    f378Envelope('onepage','f378-revision','nonce-f378-revision',
        'tenant-f360-b',2
    ),'site-f378-revision'
)[0]===403,'unapproved fictional Portal revision denied by separate gateway');
f378Check(f378Post(
    f378Envelope('onepage','f378-wrong-key','nonce-f378-wrong-key',
        'tenant-f360-b',1,'F378-WRONG-FICTIONAL-KEY-000000000000'
    ),'site-f378-wrong-key'
)[0]===403,'signed command using different mock key is rejected');
$source=new SitesCreateProjectEncoder(
    new F378FakePortalContractSource('onepage','tenant-f360-b',1,false)
);
try {
    $source->build('order-f377','operation-f378-revoked','idem-f378-revoked');
    throw new RuntimeException('Revoked source should not produce a command.');
} catch (DomainException) {
    f378Check(true,'pinned Portal denies absent/revoked fictional contract before signing');
}
echo "$checks F1-378 pinned Portal source -> HTTP Nginx -> Laravel -> PG18 checks passed.\n";
echo "NO-GO: local fictional Portal contract provider, public HMAC key and no official commercial authorization.\n";
