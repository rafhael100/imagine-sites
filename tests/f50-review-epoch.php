<?php

declare(strict_types=1);

/** F1-50: signed epoch cannot revive after revoke/reactivation. CLI lab. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $r=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Application|Security|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$r)){
        throw new RuntimeException('Unsafe test class reference');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});
use Imagine\Sites\Application\CurrentPortalReviewSnapshot as Snapshot;
use Imagine\Sites\Application\CurrentPortalReviewSnapshotSource as Source;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticReviewHandoffEpochEnvelope as V2;

final class F50Source implements Source
{
    public ?Snapshot $snapshot=null;
    public bool $offline=false;
    public function findCurrentFor(string $tenantRef,string $siteRef):?Snapshot
    {
        if($this->offline)throw new RuntimeException('Source unavailable');
        return $this->snapshot;
    }
}
final class F50Nonce implements ReplayStore
{
    public array $used=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        if($issuer!=='imagine-sites:review-handoff.v2')return false;
        $key=$issuer.':'.$nonce;
        if(isset($this->used[$key]))return false;
        $this->used[$key]=true;
        return true;
    }
}
$checkCount=0;
function f50Assert(bool $ok,string $label):void {
    global $checkCount;
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    ++$checkCount;
    echo 'PASS '.$label."\n";
}
function f50Denied(callable $test):bool {
    try{$test();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$now=time();
$digest=str_repeat('f',64);
$key='F50-SYNTHETIC-PORTAL-AUTHORITY-EPOCH-KEY-DO-NOT-DEPLOY-001';
$wf=Workflow::create('tenant-f50-a','site-f50-a','workflow-f50-a')
    ->advance('agent','start_draft',0)
    ->advance('agent','submit_draft',1,$digest)
    ->advance('imagine_reviewer','approve_internal',2,$digest);
$source=new F50Source();
$nonce=new F50Nonce();
$make=static fn(int $revision,string $status):Snapshot=>new Snapshot(
    'tenant-f50-a','site-f50-a','workflow-f50-a',3,'release-f50',
    $digest,$revision,$status,time(),time()+25
);
$old=V2::sign($wf,'release-f50',7,$key,'f50-old-epoch-nonce-0001',$now,$now+60);
$source->snapshot=$make(7,'reviewable');
f50Assert($old['authorizationRevision']===7 &&
    $old['candidate']['officialClientAcceptance']===false &&
    $old['candidate']['publishable']===false,
    'v2 MAC covers explicit authority revision and no client approval');
f50Assert(f50Denied(fn()=>V2::verify($old,$wf,'release-f50',$key,$now)),
    'v2 refuses to operate without independent snapshot and replay source');
f50Assert(f50Denied(fn()=>V2::verify($old,$wf,'release-f50',$key,$now,
    $nonce)) && $nonce->used===[],
    'missing source denies before nonce consumption');
f50Assert(f50Denied(fn()=>V2::verify($old,$wf,'release-f50',$key,$now,
    null,$source)),'missing replay store denies by default');
f50Assert(V2::verify($old,$wf,'release-f50',$key,$now,$nonce,$source)
    ['artifactDigest']===$digest,'matching current epoch permits one fictional proposal');
f50Assert(f50Denied(fn()=>V2::verify($old,$wf,'release-f50',$key,$now,
    $nonce,$source)),'second use of signed epoch replay denied');

$revoke=V2::sign($wf,'release-f50',7,$key,'f50-revoke-epoch-nonce-02',$now,$now+60);
$source->snapshot=$make(8,'revoked');
$clean=new F50Nonce();
f50Assert(f50Denied(fn()=>V2::verify($revoke,$wf,'release-f50',$key,$now,
    $clean,$source)) && $clean->used===[],
    'revocation blocks v2 proposal before nonce consumption');
$source->snapshot=$make(9,'reviewable');
f50Assert(f50Denied(fn()=>V2::verify($revoke,$wf,'release-f50',$key,$now,
    $clean,$source)) && $clean->used===[],
    'OLD AUTHORITY EPOCH cannot revive after status returns to reviewable');
$new=V2::sign($wf,'release-f50',9,$key,'f50-reactivated-nonce-03',$now,$now+60);
f50Assert(V2::verify($new,$wf,'release-f50',$key,$now,$clean,$source)
    ['workflowRevision']===3,'new epoch requires freshly signed proposal');

$wrong=V2::sign($wf,'release-f50',10,$key,'f50-wrong-epoch-nonce-04',$now,$now+60);
f50Assert(f50Denied(fn()=>V2::verify($wrong,$wf,'release-f50',$key,$now,
    new F50Nonce(),$source)),'future forged epoch cannot override snapshot');
$altered=$new;$altered['authorizationRevision']=7;
f50Assert(f50Denied(fn()=>V2::verify($altered,$wf,'release-f50',$key,$now,
    new F50Nonce(),$source)),'epoch mutation without a new MAC is rejected');

$source->offline=true;
f50Assert(f50Denied(fn()=>V2::verify($new,$wf,'release-f50',$key,$now,
    new F50Nonce(),$source)),'offline authoritative source denies even with signed epoch');
$source->offline=false;

$cross=Workflow::create('tenant-other','site-f50-a','workflow-f50-a')
    ->advance('agent','start_draft',0)
    ->advance('agent','submit_draft',1,$digest)
    ->advance('imagine_reviewer','approve_internal',2,$digest);
f50Assert(f50Denied(fn()=>V2::verify($new,$cross,'release-f50',$key,$now,
    new F50Nonce(),$source)),'cross-tenant v2 message cannot match current snapshot');
$stale=$wf->advance('imagine_reviewer','return_for_revision',3);
f50Assert(f50Denied(fn()=>V2::verify($new,$stale,'release-f50',$key,$now,
    new F50Nonce(),$source)),'workflow rework invalidates existing signed epoch');

$forged=$new;$forged['candidate']['officialClientAcceptance']=true;
f50Assert(f50Denied(fn()=>V2::verify($forged,$wf,'release-f50',$key,$now,
    new F50Nonce(),$source)),'signed candidate cannot be modified into approval');
f50Assert(f50Denied(fn()=>V2::verify($new,$wf,'release-f50',$key,$now+60,
    new F50Nonce(),$source)),'exact expiry boundary denied');

// F1-92: fixed deterministic verifier clock. Before F1-92, the envelope
// TTL used the supplied instant while the authority snapshot used time(),
// giving contradictory decisions in a single request.
$fixed=1700000000;
$historical=V2::sign(
    $wf,'release-f50',12,$key,'f50-fixed-clock-nonce-001',
    $fixed,$fixed+90
);
$source->snapshot=new Snapshot(
    'tenant-f50-a','site-f50-a','workflow-f50-a',3,'release-f50',
    $digest,12,'reviewable',$fixed-4,$fixed+24
);
$fixedReplay=new F50Nonce();
f50Assert(V2::verify(
    $historical,$wf,'release-f50',$key,$fixed,$fixedReplay,$source
)['publishable']===false,
    'one caller-provided trusted test clock checks both envelope and current authority');
f50Assert(f50Denied(fn()=>V2::verify(
    $historical,$wf,'release-f50',$key,$fixed,$fixedReplay,$source
)), 'the fixed-clock signed nonce is still single-use');

$expiredSnapshotReplay=new F50Nonce();
f50Assert(f50Denied(fn()=>V2::verify(
    $historical,$wf,'release-f50',$key,$fixed+25,
    $expiredSnapshotReplay,$source
)) && $expiredSnapshotReplay->used===[],
    'snapshot expired at trusted clock denies even if signed envelope is valid');
$futureSnapshot=new Snapshot(
    'tenant-f50-a','site-f50-a','workflow-f50-a',3,'release-f50',
    $digest,12,'reviewable',$fixed+6,$fixed+30
);
$source->snapshot=$futureSnapshot;
$futureReplay=new F50Nonce();
f50Assert(f50Denied(fn()=>V2::verify(
    $historical,$wf,'release-f50',$key,$fixed,$futureReplay,$source
)) && $futureReplay->used===[],
    'future snapshot checkedAt beyond allowed skew denies before nonce spend');
$source->snapshot=new Snapshot(
    'tenant-f50-a','site-f50-a','workflow-f50-a',3,'release-f50',
    $digest,13,'revoked',$fixed-4,$fixed+24
);
$revokedReplay=new F50Nonce();
f50Assert(f50Denied(fn()=>V2::verify(
    $historical,$wf,'release-f50',$key,$fixed,$revokedReplay,$source
)) && $revokedReplay->used===[],
    'revoked epoch denies at exact fixed verifier clock');

echo "\n$checkCount F1-50 epoch-bound review checks passed; ";
echo "no client acceptance, IAM, operational Portal or publication.\n";
