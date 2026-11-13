<?php

declare(strict_types=1);

/** F1-80: verify full stored decision metadata before any informational handoff. */
if(PHP_SAPI!=='cli' || getenv('SITES_F79_PG_LAB')!=='1') {
    fwrite(STDERR,"F1-80 requires disposable PG18 laboratory mode.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production|Persistence)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe F1-80 fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticInternalReviewDossier as Dossier;
use Imagine\Sites\Production\SyntheticInternalReviewDecision as Decision;
use Imagine\Sites\Persistence\LabPersistedReviewEvidence as Evidence;
use Imagine\Sites\Persistence\LabInternalReviewDecisionStore as Store;

$connect=static fn()=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f79_lab',
    'postgres','f79-lab-ephemeral-fixture-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$owner=$connect();
$a=$connect();$a->exec('SET ROLE sites_f79_writer_a');
$b=$connect();$b->exec('SET ROLE sites_f79_writer_b');
$copy=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Soluções digitais para pequenos negócios',
    'description'=>'Demonstração inteiramente fictícia com serviços, informações de atendimento, benefícios e detalhes para uma prévia de revisão.',
    'callToAction'=>'Conheça os serviços',
    'benefits'=>['Conteúdo claro','Orientação útil','Organização'],
];
$decision=static function(
    string $tenant,string $site,string $workflow,string $release,string $reference,
    string $action='approve_internal'
) use ($copy):Decision {
    $draft=Assembly::build(
        'onepage','Marca de Teste','modern',$copy,
        $tenant,$site,$workflow,$release
    );
    $warningKeys=[];
    if($action==='approve_internal'){
        foreach($draft->editorialReport->checks as $key=>$ok){
            if($ok!==true)$warningKeys[]=$key;
        }
    }
    return Decision::decide(
        $draft,'imagine_reviewer','reviewer-synthetic',$reference,
        $action,2,$draft->artifact->digest,$draft->bundle->bundleDigest,
        $warningKeys,$action==='request_rework'?'copy_revision':null,
        Dossier::fromDraft($draft)->digest
    );
};
$aRecord=$decision('tenant-f79-a','site-f79-a','workflow-f79-a','release-f79-a','decision-f79-a');
$bRecord=$decision('tenant-f79-b','site-f79-b','workflow-f79-b','release-f79-b','decision-f79-b');
$missing=$decision('tenant-f79-a','site-f79-a','workflow-f80-absent','release-f80-absent','decision-f80-absent');
$wrongWorkflow=$decision('tenant-f79-a','site-f79-a','workflow-f80-wrong','release-f79-a','decision-f79-a');

$n=0;
function f80Check(bool $ok,string $what):void {
    global $n;
    if(!$ok)throw new RuntimeException('FAIL '.$what);
    ++$n;echo "PASS $what\n";
}
function f80Denied(callable $call):bool {
    try{$call();}catch(DomainException|PDOException){return true;}
    return false;
}
f80Check(f80Denied(fn()=>Evidence::verify($a,$missing)),
    'read-back refuses a synthetically valid but unpersisted review decision');
f80Check(f80Denied(fn()=>Evidence::verify($owner,$aRecord)),
    'privileged lab schema owner not accepted as reviewer evidence reader');
f80Check(f80Denied(fn()=>Evidence::verify($b,$aRecord)),
    'tenant B cannot read tenant A evidence even with known reference');
f80Check(f80Denied(fn()=>Evidence::verify($a,$bRecord)),
    'tenant A cannot read tenant B evidence');
f80Check(f80Denied(fn()=>Evidence::verify($a,$wrongWorkflow)),
    'same decisionRef with different workflow/digest cannot be substituted');

putenv('SITES_F79_PG_LAB=0');
f80Check(f80Denied(fn()=>Evidence::verify($a,$aRecord)),
    'read-back disabled outside explicit PG18 lab mode');
putenv('SITES_F79_PG_LAB=1');

$readA=Evidence::verify($a,$aRecord);
$readB=Evidence::verify($b,$bRecord);
f80Check($readA['persistedInDisposableLab']===true &&
    $readA['tenantRef']==='tenant-f79-a' &&
    $readA['workflowRef']==='workflow-f79-a' &&
    $readA['decisionRecordDigest']===$aRecord->reviewDigest &&
    $readA['artifactDigest']===$aRecord->record['reviewedArtifactDigest'] &&
    $readA['bundleDigest']===$aRecord->record['reviewedBundleDigest'] &&
    $readA['dossierDigest']===$aRecord->record['reviewedDossierDigest'],
    'all A persisted identity fields and exact review hashes verified');
f80Check($readB['tenantRef']==='tenant-f79-b' &&
    $readB['decisionRecordDigest']===$bRecord->reviewDigest,
    'B evidence verified only through independent B tenant role');
f80Check($readA['portalAuthorityRequired']===true &&
    $readA['officialClientAcceptance']===false &&
    $readA['publishable']===false &&
    !array_key_exists('clientApproval',$readA),
    'verified persisted receipt never grants client approval or publication');

$owner->exec('ALTER TABLE review_f79.internal_decisions DISABLE TRIGGER f79_append_only');
try {
    $stmt=$owner->prepare("UPDATE review_f79.internal_decisions
        SET dossier_digest=:digest WHERE tenant_ref='tenant-f79-a'
        AND decision_ref='decision-f79-a'");
    $stmt->execute([':digest'=>str_repeat('e',64)]);
    f80Check(f80Denied(fn()=>Evidence::verify($a,$aRecord)),
        'DB administrator tampering with dossier hash is detected on read-back');
    $stmt->execute([':digest'=>$aRecord->record['reviewedDossierDigest']]);
} finally {
    $owner->exec('ALTER TABLE review_f79.internal_decisions ENABLE TRIGGER f79_append_only');
}
f80Check(Evidence::verify($a,$aRecord)['decisionRecordDigest']===$aRecord->reviewDigest,
    'restored canonical record can be verified after administrator reverts lab damage');

$wrongRelease=$decision(
    'tenant-f79-a','site-f79-a','workflow-f79-a','release-f80-changed','decision-f79-a'
);
f80Check(f80Denied(fn()=>Evidence::verify($a,$wrongRelease)),
    'mismatched release rejects despite valid synthetic review fields');

$rework=$decision('tenant-f79-a','site-f79-a','workflow-f80-rework',
    'release-f80-rework','decision-f80-rework','request_rework');
Store::append($a,$rework);
$storedRework=Evidence::verify($a,$rework);
f80Check($storedRework['action']==='request_rework' &&
    $storedRework['publishable']===false,
    'rework evidence can be persisted but is never a publish/approval grant');

echo "\n$n F1-80 read-back integrity tests passed; NO operational client approval.\n";
