<?php

declare(strict_types=1);

/**
 * F1-45: strict signed handoff of a synthetic preview.
 * No real certificates, HTTP, approval, client, database, or customer data.
 */
spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Application|Security|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe test autoload class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Application\ReviewHandoffAuthorityGateway;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticReviewHandoffEnvelope as Handoff;

final class F45FixtureAuthority implements ReviewHandoffAuthorityGateway
{
    public bool $revoked=false;
    public function isCurrentlyAuthorized(Workflow $workflow,array $candidate):bool
    {
        return !$this->revoked &&
            $candidate['tenantRef']==='tenant-fictional-a' &&
            $workflow->tenantRef==='tenant-fictional-a' &&
            $candidate['workflowRevision']===$workflow->revision &&
            $candidate['artifactDigest']===$workflow->artifactDigest;
    }
}

final class F45MemoryReplay implements ReplayStore
{
    /** @var array<string,bool> */
    public array $seen=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $key=$issuer.':'.$nonce;
        if (isset($this->seen[$key])) return false;
        $this->seen[$key]=true;
        return true;
    }
}

$checks=0;
function f45Assert(bool $value,string $label):void {
    global $checks;
    if (!$value)throw new RuntimeException('FAIL '.$label);
    ++$checks;
    echo 'PASS '.$label."\n";
}
function f45Denied(callable $fn):bool {
    try {$fn();} catch (DomainException|InvalidArgumentException) {return true;}
    return false;
}
function f45Mac(array $envelope,string $key):array {
    $unsigned=array_slice($envelope,0,-1,true);
    $envelope['mac']=hash_hmac('sha256',json_encode($unsigned,
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$key);
    return $envelope;
}

$key='F45-SYNTHETIC-NOT-A-DEPLOYMENT-CREDENTIAL-0000000000000000000001';
$alt='F45-SYNTHETIC-OTHER-TEST-KEY-000000000000000000000000002';
$now=1790000000;
$digest=str_repeat('a',64);
$approved=Workflow::create('tenant-fictional-a','site-fictional-a','workflow-fictional-a')
    ->advance('agent','start_draft',0)
    ->advance('agent','submit_draft',1,$digest)
    ->advance('imagine_reviewer','approve_internal',2,$digest);
$release='release-fictional-45';
$envelope=Handoff::sign($approved,$release,$key,'f45-nonce-opaque-0001',$now,$now+60);
f45Assert($envelope['issuer']==='imagine-sites' &&
    $envelope['audience']==='imagine-portal' &&
    $envelope['candidate']['publishable']===false &&
    $envelope['candidate']['officialClientAcceptance']===false,
    'signed candidate cannot claim publication or official acceptance');
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$approved,$release,$key,$now)),
    'default denial without trusted current authority and durable replay');
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$approved,$release,$key,$now,
    new F45MemoryReplay())),
    'signature plus replay storage still cannot replace current authority gateway');
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$approved,$release,$key,$now,
    null,new F45FixtureAuthority())),
    'signature plus fake authority without explicit replay fence denied');

$auth=new F45FixtureAuthority();
$fence=new F45MemoryReplay();
$result=Handoff::verify($envelope,$approved,$release,$key,$now,$fence,$auth);
f45Assert($result===$envelope['candidate'] && count($fence->seen)===1,
    'exact mock authorized signature accepted once, returns informational proposal');
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$approved,$release,$key,$now,
    $fence,$auth)),'second identical nonce denied by replay fence');

$wrongTenant=Workflow::create('tenant-fictional-b','site-fictional-a','workflow-fictional-a')
    ->advance('agent','start_draft',0)->advance('agent','submit_draft',1,$digest)
    ->advance('imagine_reviewer','approve_internal',2,$digest);
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$wrongTenant,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'valid HMAC cannot cross tenant boundaries');
$wrongSite=Workflow::create('tenant-fictional-a','site-other','workflow-fictional-a')
    ->advance('agent','start_draft',0)->advance('agent','submit_draft',1,$digest)
    ->advance('imagine_reviewer','approve_internal',2,$digest);
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$wrongSite,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'valid HMAC cannot cross site boundaries');
$revised=$approved->advance('imagine_reviewer','return_for_revision',3);
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$revised,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'old approved signature invalidated by later revision');
$otherDigest=Workflow::create('tenant-fictional-a','site-fictional-a','workflow-fictional-a')
    ->advance('agent','start_draft',0)->advance('agent','submit_draft',1,str_repeat('b',64))
    ->advance('imagine_reviewer','approve_internal',2,str_repeat('b',64));
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$otherDigest,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'same version with changed HTML bytes denied');
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$approved,'release-different',$key,$now,
    new F45MemoryReplay(),$auth)),'valid signature cannot authorize different release');
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$approved,$release,$alt,$now,
    new F45MemoryReplay(),$auth)),'different HMAC key denied');

$auth->revoked=true;
$replay=new F45MemoryReplay();
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$approved,$release,$key,$now,
    $replay,$auth)) && $replay->seen===[],
    'revoked authority denied before consuming replay nonce');
$auth->revoked=false;
f45Assert(Handoff::verify($envelope,$approved,$release,$key,$now,$replay,$auth)
    ===$envelope['candidate'],'restored mock authority can consume still-unused test nonce');
$mutations=[
    ['issuer'=>'imagine-portal'],
    ['audience'=>'imagine-sites'],
    ['schemaVersion'=>'sites.synthetic.review-handoff.v2'],
    ['nonce'=>'invalid'],
    ['issuedAt'=>$now+15],
    ['expiresAt'=>$now+121],
];
foreach($mutations as $i=>$patch) {
    $mutated=array_replace($envelope,$patch);
    f45Assert(f45Denied(fn()=>Handoff::verify($mutated,$approved,$release,$key,$now,
        new F45MemoryReplay(),$auth)),'envelope modification denied '.$i);
}
$mutated=$envelope;
$mutated['candidate']['publishable']=true;
f45Assert(f45Denied(fn()=>Handoff::verify(f45Mac($mutated,$key),$approved,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'even MAC-valid forged publishable flag denied');
$mutated=$envelope;
$mutated['candidate']['officialClientAcceptance']=true;
f45Assert(f45Denied(fn()=>Handoff::verify(f45Mac($mutated,$key),$approved,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'even MAC-valid forged official acceptance denied');
$mutated=$envelope;
$mutated['candidate']['workflowRevision']=999;
f45Assert(f45Denied(fn()=>Handoff::verify(f45Mac($mutated,$key),$approved,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'MAC-valid stale/wrong revision denied');
$mutated=$envelope;
$mutated['candidate']['artifactDigest']=str_repeat('b',64);
f45Assert(f45Denied(fn()=>Handoff::verify(f45Mac($mutated,$key),$approved,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'MAC-valid mismatched digest denied');
$mutated=$envelope;
$mutated['candidate']['canSendEmail']=true;
f45Assert(f45Denied(fn()=>Handoff::verify(f45Mac($mutated,$key),$approved,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'extra capability claim refused');
$mutated=['mac'=>$envelope['mac']]+array_diff_key($envelope,['mac'=>true]);
f45Assert(f45Denied(fn()=>Handoff::verify($mutated,$approved,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'reordered envelope fields denied');
$mutated=$envelope;
$mutated['candidate']['workflowRevision']='3';
f45Assert(f45Denied(fn()=>Handoff::verify(f45Mac($mutated,$key),$approved,$release,$key,$now,
    new F45MemoryReplay(),$auth)),'type-confused revision refused');
f45Assert(f45Denied(fn()=>Handoff::verify($envelope,$approved,$release,$key,$now+60,
    new F45MemoryReplay(),$auth)),'expiry boundary denied');
f45Assert(f45Denied(fn()=>Handoff::sign($revised,$release,$key,'f45-failed-signing',$now,$now+30)),
    'cannot sign a non-approved synthetic draft');
f45Assert(f45Denied(fn()=>Handoff::sign($approved,$release,$key,'tiny',$now,$now+30)),
    'weak replay nonce refused');

echo "\n$checks F1-45 signed synthetic review handoff tests passed. ";
echo "No Portal acceptance, real identity, HTTP, data or publication.\n";
