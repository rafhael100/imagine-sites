<?php

declare(strict_types=1);

/** F1-292: executable v3-only wire verifier, zero endpoints or real keys. */
spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $suffix=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Integration|Security)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$suffix)) {
        throw new RuntimeException('Unexpected class path in v3 contract');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$suffix).'.php';
});

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticCommercialEpochSignedCommand as V3;
use Imagine\Sites\Security\SyntheticVersionedSignedCommand as V2;
use Imagine\Sites\Security\SyntheticSignedCommand as V1;

$checks=0;
function ok292(bool $cond,string $message):void {
    global $checks;
    if(!$cond) throw new RuntimeException('F1-292 '.$message);
    ++$checks;
}
function reject292(callable $callback,string $reason):void {
    try { $callback(); }
    catch (DomainException|InvalidArgumentException) {
        ok292(true,$reason);
        return;
    }
    throw new RuntimeException('F1-292 failed to deny: '.$reason);
}

/** Ephemeral only; does NOT substitute durable cross-worker replay storage. */
final class MemoryReplay292 implements ReplayStore {
    private array $seen=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool {
        $key=$issuer."\0".$nonce;
        if(isset($this->seen[$key])) return false;
        $this->seen[$key]=$expiresAt;
        return true;
    }
}

$now=time();
$key=str_repeat('F',32); // publicly known and NEVER operational
$cmd=new SiteProjectCommand(
    '1','operation-f292','tenant-f292','order-f292',
    'contract-f292',2,'onepage','idempotency-f292'
);
$nonce='nonce-f292-20261112';
$expected=[
    'schemaVersion','issuer','audience','issuedAt','expiresAt','nonce',
    'payload','keyEpoch','commercialEpoch','mac'
];
$v3=V3::sign($cmd,$key,$nonce,$now-2,$now+85,4,9);
ok292(array_keys($v3)===$expected,'exact v3 wire order');
ok292($v3['schemaVersion']==='3' &&
    $v3['keyEpoch']===4 && $v3['commercialEpoch']===9,
    'both signed epochs are explicit integers');
ok292(V3::verifySignature($v3,$key,4,9,$now)->semanticFingerprint()===
    $cmd->semanticFingerprint(), 'valid v3 HMAC matches original command');
$replay=new MemoryReplay292();
ok292(V3::verify($v3,$key,4,9,$now,$replay)->semanticFingerprint()===
    $cmd->semanticFingerprint(),'first lab nonce accepted once');
reject292(static fn()=>V3::verify($v3,$key,4,9,$now,$replay),
    'nonce replay denied');
reject292(static fn()=>V3::verifySignature($v3,$key,5,9,$now),
    'old signing key epoch denied');
reject292(static fn()=>V3::verifySignature($v3,$key,4,10,$now),
    'old commercial authorization epoch denied');
reject292(static fn()=>V3::verifySignature($v3,'not-a-trusted-portal-key',4,9,$now),
    'wrong signing key denied');
$wrong=$v3;
$wrong['commercialEpoch']=10;
reject292(static fn()=>V3::verifySignature($wrong,$key,4,10,$now),
    'mutated commercial epoch with stale HMAC denied');
$wrong=$v3;
$wrong['keyEpoch']=5;
reject292(static fn()=>V3::verifySignature($wrong,$key,5,9,$now),
    'mutated key epoch with stale HMAC denied');
$wrong=$v3;
$wrong['payload']['tenantRef']='other-tenant-f292';
reject292(static fn()=>V3::verifySignature($wrong,$key,4,9,$now),
    'cross-tenant tampering denied');
$wrong=$v3;
$wrong['additional']='unauthorized';
reject292(static fn()=>V3::verifySignature($wrong,$key,4,9,$now),
    'unknown wire fields denied');
$wrong=$v3;
$wrong['keyEpoch']='4';
reject292(static fn()=>V3::verifySignature($wrong,$key,4,9,$now),
    'coerced epoch type denied');
$wrong=$v3;
$wrong['mac']=str_repeat('0',64);
reject292(static fn()=>V3::verifySignature($wrong,$key,4,9,$now),
    'invalid MAC denied');
$wrong=$v3;
$wrong['schemaVersion']='2';
reject292(static fn()=>V3::verifySignature($wrong,$key,4,9,$now),
    'wire schema downgrade denied');
$v1=V1::sign($cmd,$key,$nonce,$now-2,$now+85);
$v2=V2::sign($cmd,$key,$nonce,$now-2,$now+85,4);
reject292(static fn()=>V3::verifySignature($v1,$key,4,9,$now),
    'legacy v1 envelope denied');
reject292(static fn()=>V3::verifySignature($v2,$key,4,9,$now),
    'legacy v2 envelope denied');
reject292(static fn()=>V3::sign($cmd,$key,$nonce,$now,$now+121,4,9),
    'TTL longer than 120 seconds denied');
reject292(static fn()=>V3::verifySignature($v3,$key,4,9,$now+85),
    'expiry is exclusive');
reject292(static fn()=>V3::verifySignature($v3,$key,4,9,$now-8),
    'premature issue rejected');
$landing=new SiteProjectCommand(
    '1','operation-f292-landing','tenant-f292','order-f292',
    'contract-f292',2,'landing_page','idempotency-f292-landing'
);
$land=V3::sign($landing,$key,'nonce-f292-landing',$now-1,$now+40,4,9);
ok292(V3::verifySignature($land,$key,4,9,$now)->productCode===
    'landing_page','v3 same contract for landing pages');
echo "$checks F1-292 v3-only candidate HMAC contract checks passed. NON-OPERATIONAL.\n";
