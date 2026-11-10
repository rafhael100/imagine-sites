<?php

declare(strict_types=1);

/** F1-49: actual disposable PG18 RLS read + F1-47 replay, never Portal API. */
if (PHP_SAPI!=='cli' || getenv('SITES_F49_PG_LAB')!=='1') {
    fwrite(STDERR,"F1-49 requires flagged CLI/PostgreSQL laboratory.\n");
    exit(1);
}
spl_autoload_register(static function (string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Application|Production|Security)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)){
        throw new RuntimeException('Invalid lab class name');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});
use Imagine\Sites\Application\LabPostgresCurrentPortalReviewSource as Source;
use Imagine\Sites\Application\SnapshotBoundReviewAuthorityGateway as Gate;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\LabReviewHandoffReplayStore as Nonce;
use Imagine\Sites\Security\SyntheticReviewHandoffEnvelope as Handoff;

$checks=0;
function f49Assert(bool $ok,string $label):void {
    global $checks;
    if (!$ok)throw new RuntimeException('FAIL '.$label);
    ++$checks;
    echo 'PASS '.$label."\n";
}
function f49Denied(callable $call):bool {
    try {$call();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false];
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_auth_replay_lab';
$owner=new PDO($dsn,'postgres','nonce-lab-fictional-only',$options);
$readerA=new PDO($dsn,'postgres','nonce-lab-fictional-only',$options);
$readerB=new PDO($dsn,'postgres','nonce-lab-fictional-only',$options);
$nonceDb=new PDO($dsn,'postgres','nonce-lab-fictional-only',$options);
$readerA->exec('SET ROLE sites_review_tenant_a');
$readerB->exec('SET ROLE sites_review_tenant_b');
$nonceDb->exec('SET ROLE sites_auth_runtime');
$sourceA=new Source($readerA);
$sourceB=new Source($readerB);
$gatewayA=new Gate($sourceA);
$nonce=new Nonce($nonceDb);
$now=time();
$key='F49-FAKE-SITES-PORTAL-REVIEW-SOURCE-KEY-00000000000000000';
$digest=str_repeat('c',64);
$workflow=Workflow::create('tenant-f49-a','site-f49-a','workflow-f49-a')
    ->advance('agent','start_draft',0)
    ->advance('agent','submit_draft',1,$digest)
    ->advance('imagine_reviewer','approve_internal',2,$digest);
$release='release-f49-a';
$wire=Handoff::sign($workflow,$release,$key,'f49-one-valid-nonce-001',$now,$now+60);

f49Assert($sourceA->findCurrentFor('tenant-f49-a','site-f49-a')?->authorizationRevision===7,
    'role A sees its independent, current PG authority snapshot');
f49Assert($sourceA->findCurrentFor('tenant-f49-b','site-f49-b')===null &&
          $sourceB->findCurrentFor('tenant-f49-a','site-f49-a')===null,
    'RLS denies cross-tenant current review snapshot reads');
f49Assert($sourceB->findCurrentFor('tenant-f49-b','site-f49-b')?->status==='reviewable',
    'role B reads only its own fictional organization');

$ok=Handoff::verify($wire,$workflow,$release,$key,$now,$nonce,$gatewayA);
f49Assert($ok['artifactDigest']===$digest && $ok['publishable']===false,
    'signed candidate, independently looked-up PG rights and nonce permit ONLY proposal');
f49Assert(f49Denied(fn()=>Handoff::verify($wire,$workflow,$release,$key,
    $now,$nonce,$gatewayA)),
    'replay denied after first successful lab proposal');

$revoke=Handoff::sign($workflow,$release,$key,
    'f49-revoked-before-consume',$now,$now+60);
$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET status='revoked', authorization_revision=8,
 checked_at=clock_timestamp(),valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref='tenant-f49-a'");
f49Assert(f49Denied(fn()=>Handoff::verify($revoke,$workflow,$release,$key,
    $now,$nonce,$gatewayA)),
    'owner-side PG revocation denies new proposal even with valid MAC');

$stale=Handoff::sign($workflow,$release,$key,'f49-stale-revision-test',$now,$now+60);
$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET status='reviewable', workflow_revision=4,authorization_revision=9,
 checked_at=clock_timestamp(),valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref='tenant-f49-a'");
f49Assert(f49Denied(fn()=>Handoff::verify($stale,$workflow,$release,$key,
    $now,$nonce,$gatewayA)),
    'new review revision cannot be substituted with old signed version');

$wrongDigest=Handoff::sign($workflow,$release,$key,
    'f49-digest-mismatch-test',$now,$now+60);
$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET workflow_revision=3,artifact_digest=repeat('d',64),authorization_revision=10,
 checked_at=clock_timestamp(),valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref='tenant-f49-a'");
f49Assert(f49Denied(fn()=>Handoff::verify($wrongDigest,$workflow,$release,$key,
    $now,$nonce,$gatewayA)),
    'current authoritative digest mismatch prevents review handoff');

$rights=$readerA->query(
    "SELECT count(*) FROM auth_replay.current_portal_review_authority"
)->fetchColumn();
f49Assert((int)$rights===1,'SELECT policy returns only one organization');

$denied=false;
try{
    $readerA->exec("UPDATE auth_replay.current_portal_review_authority
      SET status='reviewable' WHERE tenant_ref='tenant-f49-a'");
}catch(PDOException){$denied=true;}
f49Assert($denied,'reader role cannot override authoritative status');

$owner->exec("UPDATE auth_replay.review_role_grants SET status='revoked'
 WHERE tenant_ref='tenant-f49-a' AND role_name='sites_review_tenant_a'");
f49Assert($sourceA->findCurrentFor('tenant-f49-a','site-f49-a')===null,
    'revocation of SQL role grant hides all current authority rows');
f49Assert(f49Denied(fn()=>new Source($owner)),
    'privileged DB owner cannot impersonate trusted restricted reader');

putenv('SITES_F49_PG_LAB=0');
f49Assert(f49Denied(fn()=>new Source($readerB)),
    'current snapshot source requires an explicit CLI flag');
putenv('SITES_F49_PG_LAB=1');

$used=(int)$owner->query('SELECT count(*) FROM auth_replay.review_nonce_consumptions')
    ->fetchColumn();
f49Assert($used===1,'only one successful authorized nonce was stored');
echo "\n$checks F1-49 PG18 independent source/RLS/revocation checks passed. ";
echo "No real service identity, client approval, publication or Portal network.\n";
