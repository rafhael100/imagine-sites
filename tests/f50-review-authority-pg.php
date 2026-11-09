<?php

declare(strict_types=1);

/** F1-50: ephemeral sites_lab PostgreSQL 18, synthetic authority only. */
if(PHP_SAPI!=='cli'||getenv('SITES_F50_AUTH_LAB')!=='1'){
    fwrite(STDERR,"F1-50 requires isolated flagged CLI PG lab.\n");exit(1);
}
spl_autoload_register(static function(string $class):void{
    $base='Imagine\\Sites\\';
    if(!str_starts_with($class,$base))return;
    $name=substr($class,strlen($base));
    if(!preg_match('/\A(?:Application|Security|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name))
        throw new RuntimeException('Unsafe test autoload');
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Application\LabPgCurrentReviewAuthorityGateway as Gateway;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticReviewHandoffEnvelope as Handoff;

final class F50NonceMemory implements ReplayStore
{
    /** @var array<string,bool> */
    public array $seen=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $key=$issuer.':'.$nonce;
        if(isset($this->seen[$key]))return false;
        $this->seen[$key]=true;return true;
    }
}
$checks=0;
function f50Assert(bool $ok,string $label):void{
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    ++$checks;echo "PASS ".$label."\n";
}
function f50Denied(callable $call):bool{
    try{$call();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_lab';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_EMULATE_PREPARES=>false];
$owner=new PDO($dsn,'postgres','fake-f50-only',$options);
$a=new PDO($dsn,'postgres','fake-f50-only',$options);
$b=new PDO($dsn,'postgres','fake-f50-only',$options);
$a->exec('SET ROLE sites_lab_tenant_a');
$b->exec('SET ROLE sites_lab_tenant_b');
$gateA=new Gateway($a);
$gateB=new Gateway($b);
$key='F50-SYNTHETIC-DB-AUTHORITY-DO-NOT-DEPLOY-00000000000';
$now=time();
$c=str_repeat('c',64);$d=str_repeat('d',64);
$wf=Workflow::create('tenant-fake-a','site-fake-a','wf-f50-a')
 ->advance('agent','start_draft',0)
 ->advance('agent','submit_draft',1,$c)
 ->advance('imagine_reviewer','approve_internal',2,$c);
$release='release-f50-a';
$wire=Handoff::sign($wf,$release,$key,'f50-db-current-nonce-0001',$now,$now+60);
$fence=new F50NonceMemory();
f50Assert(Handoff::verify($wire,$wf,$release,$key,$now,$fence,$gateA)
    ===$wire['candidate'],'current authorized tenant A revision/bytes verified through PostgreSQL');
f50Assert(f50Denied(fn()=>Handoff::verify($wire,$wf,$release,$key,$now,$fence,$gateA)),
    'same nonce cannot replay even when current DB authority is valid');

$fenceB=new F50NonceMemory();
f50Assert(f50Denied(fn()=>Handoff::verify($wire,$wf,$release,$key,$now,$fenceB,$gateB))
    && $fenceB->seen===[],
    'other tenant DB role cannot assert tenant A authority or spend nonce');

$wire2=Handoff::sign($wf,$release,$key,'f50-db-revoke-nonce-0002',$now,$now+60);
$owner->exec("UPDATE lab_sites.current_review_authorities
 SET active=false,authority_revision=2 WHERE tenant_id='tenant-fake-a'");
$revokedFence=new F50NonceMemory();
f50Assert(f50Denied(fn()=>Handoff::verify($wire2,$wf,$release,$key,$now,
    $revokedFence,$gateA)) && $revokedFence->seen===[],
    'owner revocation immediately denies still-valid HMAC without consuming nonce');

$restoreOldDenied=false;
try{
 $owner->exec("UPDATE lab_sites.current_review_authorities SET active=true,
   authority_revision=3 WHERE tenant_id='tenant-fake-a'");
}catch(PDOException){$restoreOldDenied=true;}
f50Assert($restoreOldDenied,'cannot reactivate old workflow revision after revocation');

$new=$wf->advance('imagine_reviewer','return_for_revision',3)
 ->advance('agent','submit_draft',4,$d)
 ->advance('imagine_reviewer','approve_internal',5,$d);
$owner->exec("UPDATE lab_sites.current_review_authorities SET active=true,
 workflow_revision=6,release_ref='release-f50-new',artifact_digest=repeat('d',64),
 authority_revision=3 WHERE tenant_id='tenant-fake-a'");
f50Assert(f50Denied(fn()=>Handoff::verify($wire2,$wf,$release,$key,$now,
    $revokedFence,$gateA)) && $revokedFence->seen===[],
    'new approved workflow invalidates all old signed preview attempts');
$wire3=Handoff::sign($new,'release-f50-new',$key,'f50-db-new-nonce-000003',
    $now,$now+60);
f50Assert(Handoff::verify($wire3,$new,'release-f50-new',$key,$now,
    new F50NonceMemory(),$gateA)['artifactDigest']===$d,
    'newer authorized workflow and different digest accepted only in lab');

$lowerDenied=false;
try{$owner->exec("UPDATE lab_sites.current_review_authorities SET workflow_revision=3,
 authority_revision=4 WHERE tenant_id='tenant-fake-a'");}
catch(PDOException){$lowerDenied=true;}
f50Assert($lowerDenied,'owner cannot reduce review revision inside live database');
$sameRevDigestDenied=false;
try{$owner->exec("UPDATE lab_sites.current_review_authorities SET artifact_digest=repeat('c',64),
 authority_revision=4 WHERE tenant_id='tenant-fake-a'");}
catch(PDOException){$sameRevDigestDenied=true;}
f50Assert($sameRevDigestDenied,'changed HTML bytes require increasing workflow revision');

$tenantB=Workflow::create('tenant-fake-b','site-fake-b','wf-f50-b')
 ->advance('agent','start_draft',0)
 ->advance('agent','submit_draft',1,$d)
 ->advance('imagine_reviewer','approve_internal',2,$d);
$wireB=Handoff::sign($tenantB,'release-f50-b',$key,
    'f50-db-tenant-b-0000004',$now,$now+60);
f50Assert(Handoff::verify($wireB,$tenantB,'release-f50-b',$key,$now,
    new F50NonceMemory(),$gateB)['tenantRef']==='tenant-fake-b',
    'tenant B sees only its own separately authorized review');

$mutateDenied=false;
try{$a->exec("UPDATE lab_sites.current_review_authorities SET active=false
 WHERE tenant_id='tenant-fake-a'");}
catch(PDOException){$mutateDenied=true;}
f50Assert($mutateDenied,'tenant runtime has no SQL write access to review authority');

$owner->exec("UPDATE lab_sites.authority_grants SET status='revoked'
 WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a'");
$wire4=Handoff::sign($new,'release-f50-new',$key,'f50-db-grant-revoked-05',
 $now,$now+60);
f50Assert(f50Denied(fn()=>Handoff::verify($wire4,$new,'release-f50-new',$key,$now,
 new F50NonceMemory(),$gateA)),
 'revoked SQL role loses current tenant visibility under RLS');

$a->exec('RESET ROLE');
f50Assert(f50Denied(fn()=>new Gateway($a)),
 'schema owner is forbidden from posing as restricted authority reader');
$a->exec('SET ROLE sites_lab_tenant_a');
putenv('SITES_F50_AUTH_LAB=0');
f50Assert(f50Denied(fn()=>new Gateway($a)),
 'missing explicit CI-only flag blocks adapter construction');
putenv('SITES_F50_AUTH_LAB=1');

echo "\n$checks F1-50 current-review PostgreSQL authority tests PASSED.\n";
echo "This is NOT a Portal entitlement, credential, client approval or publish permission.\n";
