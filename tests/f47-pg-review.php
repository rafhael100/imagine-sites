<?php

declare(strict_types=1);

/** F1-47: disposable PostgreSQL only, synthetic current authority and nonce. */
if (PHP_SAPI!=='cli' || getenv('SITES_F47_PG_LAB')!=='1') {
    fwrite(STDERR,"F1-47 requires a flagged CLI PostgreSQL lab.\n");
    exit(1);
}

spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Security|Production|Application)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $relative)) throw new RuntimeException('Unsafe lab autoload path');
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Application\ReviewHandoffAuthorityGateway;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\LabReviewHandoffReplayStore;
use Imagine\Sites\Security\SyntheticReviewHandoffEnvelope as Handoff;

final class F47FixturePortalReviewGateway implements ReviewHandoffAuthorityGateway
{
    public bool $current=true;
    public function isCurrentlyAuthorized(Workflow $workflow,array $candidate):bool
    {
        return $this->current &&
            $workflow->tenantRef==='tenant-f47-fake' &&
            $candidate['tenantRef']===$workflow->tenantRef &&
            $candidate['workflowRevision']===$workflow->revision &&
            $candidate['artifactDigest']===$workflow->artifactDigest;
    }
}

$checks=0;
function f47Check(bool $valid,string $label):void {
    global $checks;
    if (!$valid)throw new RuntimeException('FAIL '.$label);
    ++$checks;
    echo "PASS ".$label."\n";
}
function f47Denied(callable $task):bool {
    try{$task();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_auth_replay_lab';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false];
$owner=new PDO($dsn,'postgres','nonce-lab-fictional-only',$options);
$owner->exec('SET ROLE sites_auth_runtime');
$store=new LabReviewHandoffReplayStore($owner);
$otherConnection=new PDO($dsn,'postgres','nonce-lab-fictional-only',$options);
$otherConnection->exec('SET ROLE sites_auth_runtime');
$otherStore=new LabReviewHandoffReplayStore($otherConnection);
$key='F47-SYNTHETIC-REVIEW-REPLAY-NOT-A-REAL-SECRET-0000000000';
$now=time();
$digest=str_repeat('c',64);
$workflow=Workflow::create('tenant-f47-fake','site-f47-fake','wf-f47-fake')
    ->advance('agent','start_draft',0)
    ->advance('agent','submit_draft',1,$digest)
    ->advance('imagine_reviewer','approve_internal',2,$digest);
$gateway=new F47FixturePortalReviewGateway();

$handoff=Handoff::sign($workflow,'release-f47-fake',$key,
    'f47-review-nonce-one-0001',$now,$now+60);
$actual=Handoff::verify($handoff,$workflow,'release-f47-fake',$key,
    $now,$store,$gateway);
f47Check($actual['publishable']===false &&
    $actual['officialClientAcceptance']===false,
    'exact HMAC+current mock authority consumed the nonce once');
f47Check(f47Denied(fn()=>Handoff::verify($handoff,$workflow,'release-f47-fake',
    $key,$now,$store,$gateway)),
    'reusing same DB connection cannot consume nonce twice');
f47Check(f47Denied(fn()=>Handoff::verify($handoff,$workflow,'release-f47-fake',
    $key,$now,$otherStore,$gateway)),
    'second independent PDO connection sees durable replay conflict');

$revoked=Handoff::sign($workflow,'release-f47-fake',$key,
    'f47-review-revocation-0002',$now,$now+60);
$gateway->current=false;
f47Check(f47Denied(fn()=>Handoff::verify($revoked,$workflow,'release-f47-fake',
    $key,$now,$store,$gateway)),
    'revoked authority denied even for valid signature');
$gateway->current=true;
f47Check(Handoff::verify($revoked,$workflow,'release-f47-fake',
    $key,$now,$otherStore,$gateway)['workflowRevision']===3,
    'revocation refusal did not spend the nonce before valid authorization');

$stale=Handoff::sign($workflow,'release-f47-fake',$key,
    'f47-review-stale-0000003',$now,$now+60);
$reworked=$workflow->advance('imagine_reviewer','return_for_revision',3);
f47Check(f47Denied(fn()=>Handoff::verify($stale,$reworked,'release-f47-fake',
    $key,$now,$store,$gateway)),
    'old approved revision cannot consume new nonce after internal rework');
f47Check(Handoff::verify($stale,$workflow,'release-f47-fake',
    $key,$now,$store,$gateway)['workflowRevision']===3,
    'stale attempt did not consume nonce (only old lab snapshot accepts)');

$tampered=Handoff::sign($workflow,'release-f47-fake',$key,
    'f47-review-tamper-000004',$now,$now+60);
$bad=$tampered;
$bad['candidate']['tenantRef']='tenant-attacker';
f47Check(f47Denied(fn()=>Handoff::verify($bad,$workflow,'release-f47-fake',
    $key,$now,$store,$gateway)),
    'modified unsigned tenant cannot consume the nonce');
f47Check(Handoff::verify($tampered,$workflow,'release-f47-fake',
    $key,$now,$store,$gateway)['tenantRef']==='tenant-f47-fake',
    'original signed tenant still admitted after signature forgery denied');

f47Check(!$store->rememberOnce('imagine-portal',
    'f47-review-untrusted-00005',$now+60),
    'Portal->Sites issuer cannot enter distinct review replay table');
f47Check(!$store->rememberOnce('imagine-sites:review-handoff.v1',
    'f47-review-expired-000006',$now-1),
    'PostgreSQL rejects expired review nonce');
f47Check(!$store->rememberOnce('imagine-sites:review-handoff.v1',
    'f47-review-excess-0000007',$now+600),
    'PostgreSQL rejects overly long nonce TTL');
f47Check(!$store->rememberOnce('imagine-sites:review-handoff.v1',
    '../unsafe',$now+60),
    'unsafe nonce refused by replay store');

$owner->exec('RESET ROLE');
f47Check(f47Denied(fn()=>new LabReviewHandoffReplayStore($owner)),
    'privileged database owner cannot instantiate restricted adapter');
$owner->exec('SET ROLE sites_auth_runtime');
putenv('SITES_F47_PG_LAB=0');
f47Check(f47Denied(fn()=>new LabReviewHandoffReplayStore($owner)),
    'adapter denies missing explicit test flag');
putenv('SITES_F47_PG_LAB=1');

$owner->exec('RESET ROLE');
$count=(int)$owner->query('SELECT count(*) FROM auth_replay.review_nonce_consumptions')->fetchColumn();
f47Check($count===4,'exactly four legitimate review nonces persisted; denied attempts added none');
$otherCount=(int)$owner->query('SELECT count(*) FROM auth_replay.nonce_consumptions')->fetchColumn();
f47Check($otherCount===0,'Portal->Sites replay fence not polluted by review direction');

echo "\n$checks F1-47 disposable two-connection PG review nonce tests passed. ";
echo "No Portal identity/HTTP/approval, no delivery and no production.\n";
