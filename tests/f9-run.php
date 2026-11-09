<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^(Domain|Publication)\\\\[A-Za-z]+$/D', $name)) {
        throw new RuntimeException('Invalid lab class name.');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Publication\Observation;
use Imagine\Sites\Publication\PublicationOperation;

$c=0;
function t(bool $v,string $s): void { global $c; if(!$v)throw new RuntimeException('FAIL '.$s); $c++;echo 'PASS '.$s."\n"; }
function throws(callable $f): bool { try{$f();}catch(LogicException|InvalidArgumentException){return true;}return false; }
$digest=str_repeat('a',64);
$previous=str_repeat('9',64);
$manifest=new ReleaseManifest('tenant-demo','site-demo','release-002',$digest);
$allow=new PolicyDecision(true,[]);
$deny=new PolicyDecision(false,['no_approval']);
$op=new PublicationOperation($manifest,$previous);
t($op->status()==='not_requested','new operation has no published status');
t(throws(fn()=>$op->request($deny)),'blocked publication never queues');
t($op->status()==='not_requested','blocked request is atomic and has no effect');
t(throws(fn()=>$op->dispatch()),'cannot dispatch without policy acceptance');
$op->request($allow);
t($op->status()==='requested','approved request recorded');
t(throws(fn()=>$op->request($allow)),'duplicate request does not queue twice');
$op->dispatch();
t($op->status()==='dispatched','dispatch is not provider acknowledgment');
t(throws(fn()=>$op->observe(new Observation('release-002',$digest,'obs-1',true,true))),'observation cannot bypass provider sequencing');
$op->acknowledge('provider-job-002');
t($op->status()==='acknowledged','provider 202 is not verified publication');
t($op->observedDigest()===null,'no observed digest on provider acknowledgment');
t(throws(fn()=>$op->acknowledge('duplicate')),'duplicate provider acknowledgment rejected');
$op->observe(new Observation('release-002',$digest,'obs-2',true,true));
t($op->status()==='verified','independent exact release proof permits technical verification');
t($op->observedDigest()===$digest,'exact observed digest recorded');
t($op->proofRef()==='obs-2','proof identity retained');
t($op->previouslyObservedDigest===$previous,'prior live release remains immutable');
t(throws(fn()=>$op->fail()),'cannot fail a completed verification');
t(throws(fn()=>$op->observe(new Observation('release-002',$digest,'obs-3',true,true))),'late replay cannot overwrite verification');

function fakeAttempt(ReleaseManifest $manifest,string $previous,PolicyDecision $allow): PublicationOperation {
    $p=new PublicationOperation($manifest,$previous);
    $p->request($allow);$p->dispatch();$p->acknowledge('job-valid');
    return $p;
}
$wrong=fakeAttempt($manifest,$previous,$allow);
$wrong->observe(new Observation('release-002',str_repeat('b',64),'wrong-digest',true,true));
t($wrong->status()==='inconclusive','wrong observed digest marked inconclusive');
t($wrong->previouslyObservedDigest===$previous,'previous live version not auto-rolled back after mismatch');
t($wrong->observedDigest()===null,'wrong digest not recorded as confirmed');
$wrongId=fakeAttempt($manifest,$previous,$allow);
$wrongId->observe(new Observation('release-003',$digest,'wrong-id',true,true));
t($wrongId->status()==='inconclusive','wrong release identity marked inconclusive');
$unverified=fakeAttempt($manifest,$previous,$allow);
$unverified->observe(new Observation('release-002',$digest,'unsigned',false,true));
t($unverified->status()==='inconclusive','unverified observation cannot publish');
$revoked=fakeAttempt($manifest,$previous,$allow);
$revoked->observe(new Observation('release-002',$digest,'revoked',true,false));
t($revoked->status()==='inconclusive','revoked contract cannot verify publish');
$failed=new PublicationOperation($manifest,$previous);
$failed->request($allow);$failed->dispatch();$failed->fail();
t($failed->status()==='failed','failed provider operation retains failure');
t($failed->previouslyObservedDigest===$previous,'previous version preserved on failure');
t(throws(fn()=>$failed->dispatch()),'failed operation cannot be automatically retried');
$invalid=new PublicationOperation($manifest);
t(throws(fn()=>$invalid->acknowledge('job-x')),'provider cannot acknowledge never-dispatched operation');
t(throws(fn()=>new Observation('release-002','bogus','obs',true,true)),'bogus provider hash rejected');
t(throws(fn()=>new PublicationOperation($manifest,'bad')),'invalid prior release rejected');
echo "\n$c F1-09 synthetic publication-state checks passed, no provider calls.\n";
