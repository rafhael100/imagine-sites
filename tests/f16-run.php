<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $relative=substr($class,strlen($prefix));
    if (!preg_match('/^(Domain|Production|Publication)\\\\[A-Za-z]+$/D',$relative)) {
        throw new RuntimeException('Invalid lab class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Publication\SyntheticOriginRegistry;
use Imagine\Sites\Publication\SyntheticPublishCoordinator;

$count=0;
function checkF16(bool $yes,string $message): void {
    global $count;
    if (!$yes) throw new RuntimeException('FAIL '.$message);
    ++$count;
    echo 'PASS '.$message."\n";
}
function blockedF16(callable $fn): bool {
    try {$fn();} catch (LogicException|InvalidArgumentException) {return true;}
    return false;
}
$brief=new SiteBrief('onepage','Empresa fictícia','Título fictício seguro',
    'Conteúdo sintético para testes de versão e publicação.',
    'Ver mais',['Responsivo','Conteúdo de teste']);
$renderer=new StaticSiteRenderer();
$artifact=$renderer->render($brief);
$manifest=new ReleaseManifest('tenant-fake-a','site-fake-a','release-final-001',$artifact->digest);
$allow=new PolicyDecision(true,[]);
$deny=new PolicyDecision(false,['missing_acceptance']);
$prior=str_repeat('9',64);
$coordinator=new SyntheticPublishCoordinator();
$origin=new SyntheticOriginRegistry();

checkF16(blockedF16(fn()=>$coordinator->acknowledge($manifest,$deny,'fake-job-1',$prior)),
    'preflight DENY cannot start publication mock');

$ack=$coordinator->acknowledge($manifest,$allow,'fake-job-2',$prior);
checkF16($ack->status()==='acknowledged','simulated provider ACK is not publication');
checkF16($ack->observedDigest()===null,'ACK has no independently observed artifact');
checkF16($ack->previouslyObservedDigest===$prior,'last known release retained before verification');
$coordinator->verify($ack,$origin,true);
checkF16($ack->status()==='inconclusive','missing origin content leads to inconclusive');
checkF16($ack->previouslyObservedDigest===$prior,'origin missing must never trigger auto rollback');

$origin->seed($manifest,$artifact);
$success=$coordinator->acknowledge($manifest,$allow,'fake-job-3',$prior);
$coordinator->verify($success,$origin,true);
checkF16($success->status()==='verified','matching independently read mock-origin bytes verified');
checkF16($success->observedDigest()===$artifact->digest,'byte hash matches version exactly');
checkF16($success->proofRef()!==null,'synthetic origin observation reference kept');
checkF16($success->previouslyObservedDigest===$prior,'known previous release stays separate');

$mutated=new SiteBrief('onepage','Empresa fictícia','TÍTULO ALTERADO',
    'Conteúdo sintético para testes de versão e publicação.',
    'Ver mais',['Responsivo','Conteúdo de teste']);
$otherArtifact=$renderer->render($mutated);
$origin->seed($manifest,$otherArtifact);
$changed=$coordinator->acknowledge($manifest,$allow,'fake-job-4',$prior);
$coordinator->verify($changed,$origin,true);
checkF16($changed->status()==='inconclusive','changed origin bytes cannot be marked verified');
checkF16($changed->observedDigest()===null,'changed origin has no accepted verified digest');

$wrongRelease=new ReleaseManifest('tenant-fake-a','site-fake-a','release-wrong',$artifact->digest);
$origin->seed($wrongRelease,$artifact);
$wrong=$coordinator->acknowledge($manifest,$allow,'fake-job-5',$prior);
$coordinator->verify($wrong,$origin,true);
checkF16($wrong->status()==='inconclusive','wrong live release ID denied even with same bytes');
$origin->seed($manifest,$artifact);
$revoked=$coordinator->acknowledge($manifest,$allow,'fake-job-6',$prior);
$coordinator->verify($revoked,$origin,false);
checkF16($revoked->status()==='inconclusive','revoked authorization blocks even matching release');
checkF16($revoked->previouslyObservedDigest===$prior,'revocation does not auto rollback old version');

$foreignOrigin=new SyntheticOriginRegistry();
$foreignManifest=new ReleaseManifest('tenant-fake-b','site-fake-a','release-final-001',$artifact->digest);
$foreignOrigin->seed($foreignManifest,$artifact);
$crossTenant=$coordinator->acknowledge($manifest,$allow,'fake-job-7',$prior);
$coordinator->verify($crossTenant,$foreignOrigin,true);
checkF16($crossTenant->status()==='inconclusive','different tenant origin never supplies proof');

checkF16(blockedF16(fn()=>$coordinator->verify($success,$origin,true)),
    'late origin proof cannot rewrite completed publication');
checkF16($success->status()==='verified','verified operation is immutable after replay');
echo "\n$count F1-16 synthetic provider/origin checks passed; no Cloudflare network or production publication.\n";
