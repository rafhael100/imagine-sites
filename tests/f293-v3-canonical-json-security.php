<?php

declare(strict_types=1);

// F1-293: no HTTP, secrets, real tenant, signed Portal issuer or persistent DB.
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $relative=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Security|Integration)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('F1-293 unexpected fixture class.');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\SyntheticCommercialEpochSignedCommand as V3;
use Imagine\Sites\Security\SyntheticSignedCommand as V1;
use Imagine\Sites\Security\SyntheticVersionedSignedCommand as V2;
use Imagine\Sites\Security\ReplayStore;

$passed=0;
function pass293(bool $result,string $label):void {
    global $passed;
    if (!$result) throw new RuntimeException('F1-293 FAIL '.$label);
    ++$passed;
}
function deny293(callable $try,string $label):void {
    try {$try();}
    catch (DomainException|InvalidArgumentException) {
        pass293(true,$label);
        return;
    }
    throw new RuntimeException('F1-293 unsafe accepted '.$label);
}
final class Replay293 implements ReplayStore {
    public int $count=0;
    private array $seen=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool {
        $this->count++;
        $id=$issuer."\0".$nonce;
        if (isset($this->seen[$id])) return false;
        $this->seen[$id]=true;
        return true;
    }
}
$command=new SiteProjectCommand('1','op-f293','tenant-f293','order-f293',
    'contract-f293',1,'onepage','idem-f293');
$now=time();
$key=str_repeat('X',32); // synthetic fixture, NOT a service signing secret
$unsigned=V3::sign($command,$key,'nonce-293-example',$now-3,$now+30,3,7);
$flags=JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
$wire=json_encode($unsigned,$flags);
pass293(is_string($wire) && strlen($wire)<4096,
    'fixture canonical wire within size limit');
pass293(V3::verifyCanonicalJsonSignature($wire,$key,3,7,$now)
    ->semanticFingerprint()===$command->semanticFingerprint(),
    'raw canonical v3 HMAC signature accepted');
$store=new Replay293();
pass293(V3::verifyCanonicalJson($wire,$key,3,7,$now,$store)
    ->semanticFingerprint()===$command->semanticFingerprint() &&
    $store->count===1,'first nonce accepted exactly once');
deny293(static fn()=>V3::verifyCanonicalJson($wire,$key,3,7,$now,$store),
    'replay on unchanged canonical bytes');
pass293($store->count===2,'replay storage invoked only after valid signature');

$store=new Replay293();
$negative=[
    'blank wire'=>'',
    'oversized wire'=>str_repeat('x',4097),
    'null root'=>'null',
    'array root'=>'[]',
    'object with no payload'=>'{}',
    'leading whitespace'=>"\n".$wire,
    'trailing whitespace'=>$wire."\n",
    'UTF8 byte-order mark'=>"\xef\xbb\xbf".$wire,
    'escaped issuer key'=>str_replace('"issuer"', '"\u0069ssuer"', $wire),
    'escaped issuer value'=>str_replace('imagine-portal', 'imagine-\u0070ortal', $wire),
    'extra spaces'=>str_replace(',"audience"', ', "audience"', $wire),
    'duplicate top-level mac'=>str_replace('"mac":', '"mac":"'.str_repeat('0',64).'","mac":', $wire),
    'duplicate top-level issuer'=>str_replace('"issuer":', '"issuer":"imagine-portal","issuer":', $wire),
    'duplicate payload tenant'=>str_replace('"tenantRef":', '"tenantRef":"evil","tenantRef":', $wire),
    'payload array'=>str_replace('"payload":{','"payload":[', $wire),
    'invalid JSON'=>substr($wire,0,-1),
];
foreach($negative as $label=>$raw) {
    deny293(static fn()=>V3::verifyCanonicalJson($raw,$key,3,7,$now,$store),$label);
}
pass293($store->count===0,'malformed candidates never reserve replay nonce');

$other=$unsigned;
$other['commercialEpoch']=8;
deny293(static fn()=>V3::verifyCanonicalJsonSignature(
    json_encode($other,$flags),$key,3,8,$now),'tampered epoch HMAC denied');
deny293(static fn()=>V3::verifyCanonicalJsonSignature($wire,$key,3,8,$now),
    'stale commercial epoch rejected');
deny293(static fn()=>V3::verifyCanonicalJsonSignature($wire,$key,4,7,$now),
    'stale key epoch rejected');
$other=$unsigned;
$other['payload']['tenantRef']='cross-tenant-f293';
deny293(static fn()=>V3::verifyCanonicalJsonSignature(
    json_encode($other,$flags),$key,3,7,$now),'tenant tamper rejected');
$other=$unsigned;
$other['issuer']='untrusted';
deny293(static fn()=>V3::verifyCanonicalJsonSignature(
    json_encode($other,$flags),$key,3,7,$now),'issuer tamper rejected');
$other=$unsigned;
$other['mac']=str_repeat('0',64);
deny293(static fn()=>V3::verifyCanonicalJsonSignature(
    json_encode($other,$flags),$key,3,7,$now),'MAC tamper rejected');
$other=$unsigned;
$other['payload']['contractRevision']=1.0;
deny293(static fn()=>V3::verifyCanonicalJsonSignature(
    json_encode($other,$flags|JSON_PRESERVE_ZERO_FRACTION),$key,3,7,$now),
    'float revision denied');
$reordered=$unsigned;
unset($reordered['issuer']);
$reordered['issuer']='imagine-portal';
deny293(static fn()=>V3::verifyCanonicalJsonSignature(
    json_encode($reordered,$flags),$key,3,7,$now),
    'reordered fields denied');
$legacy1=V1::sign($command,$key,'nonce-293-legacy1',$now-2,$now+29);
$legacy2=V2::sign($command,$key,'nonce-293-legacy2',$now-2,$now+29,3);
deny293(static fn()=>V3::verifyCanonicalJsonSignature(
    json_encode($legacy1,$flags),$key,3,7,$now),'v1 downgrade denied');
deny293(static fn()=>V3::verifyCanonicalJsonSignature(
    json_encode($legacy2,$flags),$key,3,7,$now),'v2 downgrade denied');

deny293(static fn()=>V3::verifyCanonicalJson($wire,$key,3,7,$now),
    'default replay backend denies');
$landing=new SiteProjectCommand('1','op-f293-b','tenant-f293',
    'order-f293-b','contract-f293-b',2,'landing_page','idem-f293-b');
$land=V3::sign($landing,$key,'nonce-293-landing',$now-3,$now+30,3,7);
pass293(V3::verifyCanonicalJsonSignature(json_encode($land,$flags),$key,3,7,$now)
    ->productCode==='landing_page','landing page has same strict raw wire');

echo "$passed F1-293 strict raw JSON v3 tests PASSED; no endpoint or real authority.\n";
