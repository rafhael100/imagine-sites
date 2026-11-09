<?php

declare(strict_types=1);

/** F1-48: only a separate, current Portal mock source can admit review. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $rest=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Application|Production|Security)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$rest)) {
        throw new RuntimeException('Unsafe test autoload path');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$rest).'.php';
});

use Imagine\Sites\Application\CurrentPortalReviewSnapshot as Snap;
use Imagine\Sites\Application\CurrentPortalReviewSnapshotSource as Source;
use Imagine\Sites\Application\SnapshotBoundReviewAuthorityGateway as Gate;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticReviewHandoffEnvelope as Wire;

final class F48Source implements Source
{
    public ?Snap $current=null;
    public bool $offline=false;
    public int $lookups=0;
    public function findCurrentFor(string $tenantRef,string $siteRef):?Snap
    {
        ++$this->lookups;
        if($this->offline)throw new RuntimeException('Fictional Portal unavailable');
        // Fixture does NOT trust tenant from the message as permission.
        // The source is separately populated by test harness.
        return $this->current;
    }
}
final class F48Nonce implements ReplayStore
{
    /** @var array<string,bool> */
    public array $seen=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $id=$issuer.':'.$nonce;
        if(isset($this->seen[$id])) return false;
        $this->seen[$id]=true;
        return true;
    }
}
$checks=0;
function f48Check(bool $cond,string $name):void {
    global $checks;
    if(!$cond)throw new RuntimeException('FAIL '.$name);
    ++$checks;
    echo 'PASS '.$name."\n";
}
function f48Denied(callable $cb):bool {
    try{$cb();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$now=time();
$key='F48-TEST-ONLY-PORTAL-SNAPSHOT-KEY-0000000000000000000000';
$digest=str_repeat('d',64);
$workflow=Workflow::create('tenant-f48-a','site-f48-a','wf-f48-a')
    ->advance('agent','start_draft',0)
    ->advance('agent','submit_draft',1,$digest)
    ->advance('imagine_reviewer','approve_internal',2,$digest);
$release='release-f48';
$token=Wire::sign($workflow,$release,$key,'f48-review-nonce-001',$now,$now+60);
$source=new F48Source();
$gate=new Gate($source);
$fence=new F48Nonce();
$make=static fn(array $changes=[]):Snap=>new Snap(
    $changes['tenantRef']??'tenant-f48-a',
    $changes['siteRef']??'site-f48-a',
    $changes['workflowRef']??'wf-f48-a',
    $changes['workflowRevision']??3,
    $changes['releaseRef']??'release-f48',
    $changes['artifactDigest']??$digest,
    $changes['authorizationRevision']??5,
    $changes['status']??'reviewable',
    $changes['checkedAt']??$now,
    $changes['validUntil']??($now+25),
);
f48Check(f48Denied(fn()=>Wire::verify($token,$workflow,$release,$key,$now,
    $fence,new Gate())) && $fence->seen===[],
    'no Portal source rejects by default without nonce consumption');
f48Check(f48Denied(fn()=>Wire::verify($token,$workflow,$release,$key,$now,
    $fence,$gate)) && $fence->seen===[],
    'missing current snapshot rejects before consuming nonce');
$source->current=$make();
f48Check(Wire::verify($token,$workflow,$release,$key,$now,$fence,$gate)
    ['artifactDigest']===$digest,
    'signed proposal accepted once with fresh, independently supplied mock snapshot');
f48Check(count($fence->seen)===1 && $source->lookups>=2,
    'source queried independently and nonce spent once');
f48Check(f48Denied(fn()=>Wire::verify($token,$workflow,$release,$key,$now,
    $fence,$gate)),'replay still denied after current snapshot verification');

$negatives=[
    ['tenantRef'=>'tenant-f48-other'],
    ['siteRef'=>'site-f48-other'],
    ['workflowRef'=>'wf-f48-other'],
    ['workflowRevision'=>2],
    ['releaseRef'=>'release-f48-other'],
    ['artifactDigest'=>str_repeat('e',64)],
    ['status'=>'revoked'],
    ['status'=>'suspended'],
    ['checkedAt'=>$now-31,'validUntil'=>$now-1],
    ['checkedAt'=>$now+6,'validUntil'=>$now+29],
];
foreach($negatives as $i=>$patch) {
    $source->current=$make($patch);
    $nonce=new F48Nonce();
    f48Check(f48Denied(fn()=>Wire::verify($token,$workflow,$release,$key,$now,
        $nonce,$gate)) && $nonce->seen===[],
        'independent Portal denial '.$i.' does not consume nonce');
}
$source->current=$make();
$source->offline=true;
f48Check(f48Denied(fn()=>Wire::verify($token,$workflow,$release,$key,$now,
    new F48Nonce(),$gate)),
    'Portal source unavailable: no speculative review handoff');
$source->offline=false;
$oldWorkflow=$workflow->advance('imagine_reviewer','return_for_revision',3);
f48Check(f48Denied(fn()=>Wire::verify($token,$oldWorkflow,$release,$key,$now,
    new F48Nonce(),$gate)),
    'draft rework never preserves prior review authority');
f48Check(!($gate->isCurrentlyAuthorized($workflow,array_replace(
    $token['candidate'],['officialClientAcceptance'=>true]))),
    'caller cannot forge official acceptance using boolean');
f48Check(!($gate->isCurrentlyAuthorized($workflow,array_replace(
    $token['candidate'],['publishable'=>true]))),
    'caller cannot force technical publication');
f48Check(f48Denied(fn()=>new Snap('bad tenant','site-f48-a','wf-f48-a',3,
    $release,$digest,5,'reviewable',$now,$now+25)),
    'unsafe snapshot reference refused before gateway');
f48Check(f48Denied(fn()=>new Snap('tenant-f48-a','site-f48-a','wf-f48-a',3,
    $release,$digest,5,'reviewable',$now,$now+300)),
    'abnormally long source snapshot lifetime refused');
echo "\n$checks F1-48 independently sourced current-authority checks passed; ";
echo "no official Portal identity, approvals or publication.\n";
