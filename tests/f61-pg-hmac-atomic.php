<?php

declare(strict_types=1);

/**
 * F1-61: exercise one logical path: signed envelope -> Portal state check
 * -> ATOMIC recheck + nonce under PostgreSQL revocation locks.
 * No real Portal service, IAM or client approval.
 */
if (PHP_SAPI!=='cli' || getenv('SITES_F61_PG_LAB')!=='1') {
    fwrite(STDERR,"F1-61 requires disposable PG18 CLI laboratory.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Application|Security|Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $relative))throw new RuntimeException('Invalid F1-61 test-only class');
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\SyntheticReviewHandoffEpochEnvelope as V2;
use Imagine\Sites\Security\LabAtomicReviewHandoffVerifier as Atomic;

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_auth_replay_lab';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false];
$connect=static fn()=>new PDO($dsn,'postgres','nonce-lab-fictional-only',$options);
$owner=$connect();$a=$connect();$b=$connect();
$a->exec('SET ROLE sites_review_tenant_a');
$b->exec('SET ROLE sites_review_tenant_b');
$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET checked_at=clock_timestamp(),
 valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref IN ('tenant-f49-a','tenant-f49-b')");

$key='IMAGINE-SITES-F61-FICTIONAL-HMAC-KEY-DO-NOT-DEPLOY-0000000';
$now=time();
$make=static fn(string $tenant,string $site,string $workflow,string $digest):Workflow=>
 Workflow::create($tenant,$site,$workflow)
 ->advance('agent','start_draft',0)
 ->advance('agent','submit_draft',1,$digest)
 ->advance('imagine_reviewer','approve_internal',2,$digest);
$wa=$make('tenant-f49-a','site-f49-a','workflow-f49-a',str_repeat('c',64));
$wb=$make('tenant-f49-b','site-f49-b','workflow-f49-b',str_repeat('b',64));
$sign=static fn(Workflow $workflow,string $release,int $epoch,string $nonce)=>
 V2::sign($workflow,$release,$epoch,$key,$nonce,$now,$now+60);
$verify=static fn(array $wire,Workflow $workflow,string $release,PDO $pdo)=>
 Atomic::verify($wire,$workflow,$release,$key,$now,$pdo);
$ledger=static fn():int=>(int)$owner->query(
 'SELECT count(*) FROM auth_replay.review_v2_nonce_consumptions'
)->fetchColumn();
$n=0;
function f61Check(bool $ok,string $description):void {
 global $n;if(!$ok)throw new RuntimeException('FAIL '.$description);
 ++$n;echo 'PASS '.$description."\n";
}
function f61Denied(callable $fn):bool {
 try{$fn();}catch(DomainException|InvalidArgumentException){return true;}
 return false;
}
$wireA=$sign($wa,'release-f49-a',7,'f61-a-first-signed-00001');
f61Check(f61Denied(fn()=>V2::verify($wireA,$wa,'release-f49-a',$key,$now)),
 'v2 default source/nonce still DENY without explicit trusted lab');
f61Check(f61Denied(fn()=>$verify($wireA,$wa,'release-f49-a',$owner)),
 'privileged database owner cannot masquerade as restricted review role');
putenv('SITES_F61_PG_LAB=0');
f61Check(f61Denied(fn()=>$verify($wireA,$wa,'release-f49-a',$a)),
 'bridge is disabled without explicit CLI test flag');
putenv('SITES_F61_PG_LAB=1');

$first=$verify($wireA,$wa,'release-f49-a',$a);
f61Check($first['artifactDigest']===str_repeat('c',64) &&
 $first['officialClientAcceptance']===false && $first['publishable']===false,
 'HMAC-authentic proposal and locked current authority permit only informative receipt');
f61Check($ledger()===1,'one v2 nonce consumed after HMAC and DB recheck');
f61Check(f61Denied(fn()=>$verify($wireA,$wa,'release-f49-a',$a)),
 'repeated signed proposal cannot consume identical nonce again');
f61Check($ledger()===1,'replay does not add a row');

$bad=$sign($wa,'release-f49-a',7,'f61-bad-mac-nonce-00002');
$bad['mac']=str_repeat('0',64);
f61Check(f61Denied(fn()=>$verify($bad,$wa,'release-f49-a',$a)),
 'bad HMAC rejected before SQL nonce consumption');
$badClaim=$sign($wa,'release-f49-a',7,'f61-invalid-approval-00003');
$badClaim['candidate']['publishable']=true;
$badClaim['mac']=hash_hmac('sha256',
 json_encode(array_slice($badClaim,0,-1,true),
 JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$key);
f61Check(f61Denied(fn()=>$verify($badClaim,$wa,'release-f49-a',$a)),
 'even recomputed synthetic MAC cannot approve client or claim publishable');
f61Check($ledger()===1,'invalid signed claims do not consume a nonce');

$wireB=$sign($wb,'release-f49-b',3,'f61-b-other-tenant-00004');
f61Check(f61Denied(fn()=>$verify($wireB,$wb,'release-f49-b',$a)),
 'A cannot process valid B-signed message by selecting tenant from payload');
f61Check($verify($wireB,$wb,'release-f49-b',$b)['workflowRevision']===3,
 'B uses independent restricted role and same locked authority proof');
f61Check($ledger()===2,'A and B each consumed exactly one distinct valid nonce');

$old=$sign($wa,'release-f49-a',7,'f61-while-revoking-00005');
$owner->beginTransaction();
$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET status='revoked',authorization_revision=8,
 checked_at=clock_timestamp(),valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref='tenant-f49-a'");
$a->exec("SET lock_timeout='120ms'");
$raceDenied=f61Denied(fn()=>$verify($old,$wa,'release-f49-a',$a));
$owner->commit();$a->exec("SET lock_timeout='0'");
f61Check($raceDenied,'valid HMAC cannot bypass concurrent revocation row lock');
f61Check($ledger()===2,'revocation race did not consume the signed nonce');
f61Check(f61Denied(fn()=>$verify($old,$wa,'release-f49-a',$a)),
 'after revocation commit the previous epoch remains invalid');

$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET status='reviewable',authorization_revision=9,
 checked_at=clock_timestamp(),valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref='tenant-f49-a'");
f61Check(f61Denied(fn()=>$verify($old,$wa,'release-f49-a',$a)),
 'reactivation does not revive old epoch 7 proposal');
$fresh=$sign($wa,'release-f49-a',9,'f61-fresh-new-epoch-00006');
f61Check($verify($fresh,$wa,'release-f49-a',$a)['publishable']===false,
 'only newly signed epoch 9 can produce another NON-PUBLISHABLE candidate');
f61Check($ledger()===3,'exactly three authorized lab nonces stored');

$changed=$fresh;
$changed['authorizationRevision']=10;
f61Check(f61Denied(fn()=>$verify($changed,$wa,'release-f49-a',$a)),
 'mutation of signed authorization epoch fails HMAC before SQL');
$rework=$wa->advance('imagine_reviewer','return_for_revision',3);
f61Check(f61Denied(fn()=>$verify($fresh,$rework,'release-f49-a',$a)),
 'revised workflow does not inherit previous signed review');
f61Check($ledger()===3,'invalid updates do not advance nonce ledger');

echo "\n$n F1-61 HMAC->atomic-PG fixtures passed. No distributed Portal identity or approval.\n";
