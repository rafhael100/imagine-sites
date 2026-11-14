<?php

declare(strict_types=1);

/**
 * F1-377: a real external HTTP client calling Nginx -> Laravel FPM -> PG18
 * in ONE disposable GitHub runner, with ONLY fictional Portal authorization.
 */
if(PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F377_CI_HTTP_LAB')!=='1') {
    fwrite(STDERR,"F1-377 requires disposable CI HTTP opt-in.\n");
    exit(1);
}
require __DIR__.'/../src/Integration/SiteProjectCommand.php';
require __DIR__.'/../src/Security/SyntheticSignedCommand.php';

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\SyntheticSignedCommand;

const F377_FAKE_SIGNING_KEY='F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI';
const F377_CI_URL='http://127.0.0.1:18081/_ci/sites/v1/admit';

function f377Command(
    string $op,string $product='onepage',
    string $tenant='tenant-f360-b',string $idem='',
):SiteProjectCommand {
    return new SiteProjectCommand(
        '1','operation-'.$op,$tenant,'order-f377','contract-f377',1,
        $product,$idem!==''?$idem:'idem-'.$op
    );
}
function f377Sign(SiteProjectCommand $command,string $nonce):array {
    return SyntheticSignedCommand::sign(
        $command,F377_FAKE_SIGNING_KEY,$nonce,time(),time()+60
    );
}
function f377Post(
    array $body,string $contentType='application/json',bool $marker=true,
):array {
    $c=curl_init(F377_CI_URL);
    if($c===false)throw new RuntimeException('curl unavailable');
    $headers=['Content-Type: '.$contentType,'Accept: application/json'];
    if($marker)$headers[]='X-Imagine-CI-Transport: f377-local';
    curl_setopt_array($c,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>6,
        CURLOPT_PROXY=>'',
        CURLOPT_HTTPHEADER=>$headers,
    ]);
    $raw=curl_exec($c);
    $code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
    curl_close($c);
    if(!is_string($raw))throw new RuntimeException('HTTP request failed.');
    $decoded=json_decode($raw,true,20,JSON_THROW_ON_ERROR);
    if(!is_array($decoded))throw new RuntimeException('Invalid HTTP JSON.');
    return [$code,$decoded];
}
function f377Check(bool $condition,string $label):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-377 FAIL: '.$label);
    ++$checks;
}
$checks=0;
foreach(['onepage','landing_page'] as $product) {
    $id='f377-'.$product;
    $wire=f377Sign(f377Command($id,$product),'nonce-'.$id.'-first');
    [$status,$data]=f377Post(['siteRef'=>'site-'.$id,'signed'=>$wire]);
    f377Check($status===202 && ($data['status']??null)==='queued' &&
        ($data['operationRef']??null)==='idem-'.$id &&
        ($data['publishable']??null)===false &&
        ($data['realOrderAccepted']??null)===false,
        'actual Nginx -> FPM -> PostgreSQL18 accepted fictional '.$product);
    [$replay,$replayed]=f377Post(['siteRef'=>'site-'.$id,'signed'=>$wire]);
    f377Check($replay===403 && !array_key_exists('operationRef',$replayed),
        'same signed nonce replay denied across independent HTTP requests');
    [$fresh,$dedup]=f377Post([
       'siteRef'=>'site-'.$id,
       'signed'=>f377Sign(
           f377Command($id,$product),'nonce-'.$id.'-second'
       ),
    ]);
    f377Check($fresh===200 &&
        ($dedup['status']??null)==='duplicate_ignored',
        'freshly signed idempotent retry cannot duplicate persisted project');
}
$cmd=f377Command('f377-forged');
$wire=f377Sign($cmd,'nonce-f377-forged');
$wire['payload']['contractRevision']=2;
f377Check(f377Post(['siteRef'=>'site-f377-forged','signed'=>$wire])[0]===403,
    'valid JSON with modified contract under old MAC denied');
$foreign=f377Sign(
    f377Command('f377-other-tenant','onepage','tenant-f360-a'),
    'nonce-f377-foreign-tenant'
);
f377Check(f377Post(['siteRef'=>'site-f377-foreign','signed'=>$foreign])[0]===403,
    'fictional Portal gateway permits foreign tenant but SQL login rejects it');
$base=f377Command('f377-conflict');
f377Check(f377Post([
   'siteRef'=>'site-f377-conflict',
   'signed'=>f377Sign($base,'nonce-f377-conflict-first'),
])[0]===202,'new operation accepted as fictional lab item');
f377Check(f377Post([
   'siteRef'=>'site-f377-conflict',
   'signed'=>f377Sign(
       f377Command('f377-conflict','landing_page'),
       'nonce-f377-conflict-second'
   ),
])[0]===409,'conflicting idempotency key returned HTTP409');
$failure=f377Sign(new SiteProjectCommand(
    '1','operation-f377-broken-outbox','tenant-f360-b','order-f377',
    'contract-f377',1,'onepage','failoutbox-f360'
),'nonce-f377-outbox-failure');
f377Check(f377Post(['siteRef'=>'site-f377-broken','signed'=>$failure])[0]===503,
    'outbox failure returned HTTP503 without issuing a fake business approval');
f377Check(f377Post(['siteRef'=>'site-f377-broken','signed'=>$failure])[0]===503,
    'repeating failed outbox nonce is not poisoned by prior rolled-back transaction');
$expired=SyntheticSignedCommand::sign(
    f377Command('f377-expired'),F377_FAKE_SIGNING_KEY,
    'nonce-f377-expired',time()-100,time()-40
);
f377Check(f377Post(['siteRef'=>'site-f377-expired','signed'=>$expired])[0]===403,
    'expired signed command denied before SQL');
f377Check(f377Post([
    'siteRef'=>'../unsafe','signed'=>f377Sign(
        f377Command('f377-path'),'nonce-f377-path'
    )
])[0]===422,'unsafe site path denied');
f377Check(f377Post([
    'siteRef'=>'site-f377-content','signed'=>f377Sign(
        f377Command('f377-type'),'nonce-f377-type'
    )
],'text/plain')[0]===415,'wrong content-type refused');
f377Check(f377Post([
    'siteRef'=>'site-f377-header','signed'=>f377Sign(
        f377Command('f377-header'),'nonce-f377-header'
    )
],'application/json',false)[0]===415,
    'missing CI transport marker refused');
f377Check(f377Post([
    'siteRef'=>'site-f377-large',
    'signed'=>f377Sign(f377Command('f377-large'),'nonce-f377-large'),
    'pad'=>str_repeat('x',6000),
])[0]===413,'oversized HTTP request cannot reach signed admission');
echo "$checks F1-377 real CI HTTP -> Nginx -> Laravel -> restricted PG18 tests passed.\n";
echo "NO-GO: fictional signer, fictional Portal authority, test-only route and disposable database.\n";
