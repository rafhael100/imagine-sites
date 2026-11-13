<?php

declare(strict_types=1);

/** F1-79: disposable PG18 tenant/RLS/append-only experiment, not real Portal. */
if(PHP_SAPI!=='cli' || getenv('SITES_F79_PG_LAB')!=='1'){
    fwrite(STDERR,"F1-79 restricted PG18 CLI laboratory flag required.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void{
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production|Persistence)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe F1-79 autoload');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticInternalReviewDossier as Dossier;
use Imagine\Sites\Production\SyntheticInternalReviewDecision as Decision;
use Imagine\Sites\Persistence\LabInternalReviewDecisionStore as Store;

$connect=static fn()=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f79_lab',
    'postgres','f79-lab-ephemeral-fixture-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$owner=$connect();$a=$connect();$b=$connect();$a2=$connect();
$a->exec('SET ROLE sites_f79_writer_a');
$a2->exec('SET ROLE sites_f79_writer_a');
$b->exec('SET ROLE sites_f79_writer_b');
$copy=[
 'schemaVersion'=>'sites.ai-copy.v1',
 'headline'=>'Soluções digitais para pequenos negócios',
 'description'=>'Demonstração inteiramente fictícia com serviços, informações de atendimento, benefícios e detalhes para uma prévia de revisão.',
 'callToAction'=>'Conheça os serviços',
 'benefits'=>['Conteúdo claro','Orientação útil','Organização'],
];
$make=static function(string $tenant,string $site,string $flow,string $release,string $decisionRef) use ($copy):array {
    $assembly=Assembly::build('onepage','Marca de Teste','modern',$copy,
        $tenant,$site,$flow,$release);
    $pending=[];
    foreach($assembly->editorialReport->checks as $key=>$ok)if(!$ok)$pending[]=$key;
    $decision=Decision::decide(
        $assembly,'imagine_reviewer','reviewer-synthetic',$decisionRef,
        'approve_internal',2,$assembly->artifact->digest,
        $assembly->bundle->bundleDigest,$pending,null,
        Dossier::fromDraft($assembly)->digest
    );
    return [$assembly,$decision];
};
[$draftA,$dA]=$make('tenant-f79-a','site-f79-a','workflow-f79-a','release-f79-a','decision-f79-a');
[$draftB,$dB]=$make('tenant-f79-b','site-f79-b','workflow-f79-b','release-f79-b','decision-f79-b');

$checks=0;
function f79Check(bool $ok,string $msg):void{
    global $checks; if(!$ok)throw new RuntimeException('FAIL '.$msg);
    ++$checks; echo "PASS $msg\n";
}
function f79Denied(callable $call):bool{
    try{$call();}catch(DomainException|PDOException){return true;}
    return false;
}
$count=static fn(PDO $db):int=>(int)$db->query(
    'SELECT count(*) FROM review_f79.internal_decisions'
)->fetchColumn();

f79Check($count($owner)===0,'empty PG18 ledger before append');
f79Check(f79Denied(fn()=>Store::append($owner,$dA)),
    'privileged DB owner cannot impersonate runtime restricted role');

putenv('SITES_F79_PG_LAB=0');
f79Check(f79Denied(fn()=>Store::append($a,$dA)),
    'store disabled without explicit lab flag');
putenv('SITES_F79_PG_LAB=1');

Store::append($a,$dA);
f79Check($count($owner)===1 && $count($a)===1 && $count($b)===0,
    'RLS hides A decision from independent B tenant');
f79Check(f79Denied(fn()=>Store::append($a,$dA)) && $count($owner)===1,
    'duplicate decisionRef cannot be appended again');
f79Check(f79Denied(fn()=>Store::append($b,$dA)) && $count($owner)===1,
    'tenant B role cannot append a valid A decision');

Store::append($b,$dB);
f79Check($count($owner)===2 && $count($a)===1 && $count($b)===1,
    'independent B decision stored under B role');

$rows=$owner->query('SELECT tenant_ref,artifact_digest,bundle_digest,dossier_digest,decision_record_digest,
  synthetic_only,publishable,official_client_acceptance,portal_authority_required
  FROM review_f79.internal_decisions ORDER BY tenant_ref')->fetchAll(PDO::FETCH_ASSOC);
f79Check($rows[0]['tenant_ref']==='tenant-f79-a' &&
    $rows[0]['artifact_digest']===$dA->record['reviewedArtifactDigest'] &&
    $rows[0]['bundle_digest']===$dA->record['reviewedBundleDigest'] &&
    $rows[0]['dossier_digest']===$dA->record['reviewedDossierDigest'] &&
    $rows[0]['decision_record_digest']===$dA->reviewDigest,
    'stored A evidence binds exact HTML, bundle, dossier and decision digest');
f79Check($rows[1]['tenant_ref']==='tenant-f79-b' &&
    $rows[1]['decision_record_digest']===$dB->reviewDigest,
    'stored B decision keeps its own digest and tenant');
foreach($rows as $row){
    f79Check($row['synthetic_only']===true||$row['synthetic_only']==='t',
        'DB insists lab-only evidence');
    f79Check($row['publishable']===false||$row['publishable']==='f',
        'DB hard-rejects publication flag');
    f79Check($row['official_client_acceptance']===false||
        $row['official_client_acceptance']==='f',
        'DB hard-rejects client acceptance flag');
    f79Check($row['portal_authority_required']===true||
        $row['portal_authority_required']==='t',
        'DB retains separate Portal authority gate');
}

$wrongTenantInsert="INSERT INTO review_f79.internal_decisions(
    tenant_ref,site_ref,workflow_ref,release_ref,reviewed_workflow_revision,
    artifact_digest,bundle_digest,dossier_digest,decision_ref,decision_action,decision_record_digest
  ) SELECT 'tenant-f79-b',site_ref,workflow_ref,release_ref,reviewed_workflow_revision,
    artifact_digest,bundle_digest,dossier_digest,'f79-cross-forgery',decision_action,decision_record_digest
    FROM review_f79.internal_decisions WHERE tenant_ref='tenant-f79-a' LIMIT 1";
f79Check(f79Denied(fn()=>$a->exec($wrongTenantInsert)) && $count($owner)===2,
    'PostgreSQL RLS denies raw SQL cross-tenant insert beyond app checks');
f79Check(f79Denied(fn()=>$a->exec(
    "UPDATE review_f79.internal_decisions SET release_ref='changed' WHERE tenant_ref='tenant-f79-a'"
)),'restricted role cannot modify append-only evidence');
f79Check(f79Denied(fn()=>$a->exec(
    "DELETE FROM review_f79.internal_decisions WHERE tenant_ref='tenant-f79-a'"
)),'restricted role cannot delete review history');
f79Check(f79Denied(fn()=>$owner->exec(
    "UPDATE review_f79.internal_decisions SET release_ref='changed' WHERE tenant_ref='tenant-f79-a'"
)),'even lab owner update rejected by append-only trigger');

$rework=Decision::decide(
    $draftA,'imagine_reviewer','reviewer-synthetic','decision-f79-rework',
    'request_rework',2,$draftA->artifact->digest,
    $draftA->bundle->bundleDigest,[],'copy_revision',
    Dossier::fromDraft($draftA)->digest
);
f79Check(f79Denied(fn()=>Store::append($a,$rework)) &&
    $count($owner)===2,'conflicting decision for same workflow revision denied');

[$raceDraft,$raceDecision]=$make('tenant-f79-a','site-f79-a','workflow-f79-race',
    'release-f79-race','decision-f79-race');
$a->beginTransaction();
Store::append($a,$raceDecision);
$a2->exec("SET lock_timeout='150ms'");
$blocked=f79Denied(fn()=>Store::append($a2,$raceDecision));
$a->commit();
$a2->exec("SET lock_timeout='0'");
f79Check($blocked,'concurrent same decision cannot pass uncommitted uniqueness lock');
f79Check(f79Denied(fn()=>Store::append($a2,$raceDecision)),
    'after commit duplicate decision still forbidden');
f79Check($count($owner)===3 && $count($a)===2 && $count($b)===1,
    'final tenant counts and unique append-only decision ledger are stable');

echo "\n$checks F1-79 PG18/RLS ledger assertions passed. Never real IAM or customer acceptance.\n";
