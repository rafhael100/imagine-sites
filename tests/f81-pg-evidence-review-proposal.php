<?php

declare(strict_types=1);

/** F1-81: no informational proposal unless exact accepted decision is persisted. */
if(PHP_SAPI!=='cli' || getenv('SITES_F79_PG_LAB')!=='1') {
    fwrite(STDERR,"F1-81 requires disposable PG18 laboratory flag.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Application|Domain|Production|Persistence)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)){
        throw new RuntimeException('Unsafe F1-81 fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Application\LabEvidenceBoundReviewProposal as Proposal;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticInternalReviewDossier as Dossier;
use Imagine\Sites\Production\SyntheticInternalReviewDecision as Decision;
use Imagine\Sites\Production\SyntheticReviewCandidateGuard as CandidateGuard;
use Imagine\Sites\Persistence\LabInternalReviewDecisionStore as Store;

$connect=static fn()=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f79_lab',
    'postgres','f79-lab-ephemeral-fixture-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$a=$connect();$a->exec('SET ROLE sites_f79_writer_a');
$b=$connect();$b->exec('SET ROLE sites_f79_writer_b');
$owner=$connect();
$copy=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Soluções digitais para pequenos negócios',
    'description'=>'Demonstração inteiramente fictícia com serviços, informações de atendimento, benefícios e detalhes para uma prévia de revisão.',
    'callToAction'=>'Conheça os serviços',
    'benefits'=>['Conteúdo claro','Orientação útil','Organização'],
];
$assemble=static function(string $tenant,string $site,string $workflow,string $release) use ($copy) {
    return Assembly::build('onepage','Marca de Teste','modern',$copy,
        $tenant,$site,$workflow,$release);
};
$decide=static function($draft,string $reference,string $action='approve_internal'){
    $warnings=[];
    if($action==='approve_internal'){
        foreach($draft->editorialReport->checks as $key=>$ok){
            if(!$ok)$warnings[]=$key;
        }
    }
    return Decision::decide(
        $draft,'imagine_reviewer','reviewer-synthetic',$reference,
        $action,2,$draft->artifact->digest,$draft->bundle->bundleDigest,
        $warnings,$action==='request_rework'?'copy_revision':null,
        Dossier::fromDraft($draft)->digest
    );
};
$draftA=$assemble('tenant-f79-a','site-f79-a','workflow-f79-a','release-f79-a');
$draftB=$assemble('tenant-f79-b','site-f79-b','workflow-f79-b','release-f79-b');
$decisionA=$decide($draftA,'decision-f79-a');
$decisionB=$decide($draftB,'decision-f79-b');
$notStored=$assemble('tenant-f79-a','site-f79-a','workflow-f81-unpersisted','release-f81-unpersisted');
$notStoredDecision=$decide($notStored,'decision-f81-unpersisted');
$reworkDraft=$assemble('tenant-f79-a','site-f79-a','workflow-f80-rework','release-f80-rework');
$reworkDecision=$decide($reworkDraft,'decision-f80-rework','request_rework');

$checks=0;
function f81Check(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    ++$checks;echo "PASS $label\n";
}
function f81Denied(callable $call):bool {
    try{$call();}catch(DomainException|PDOException){return true;}
    return false;
}

f81Check(f81Denied(fn()=>Proposal::prepare($a,$notStored,$notStoredDecision)),
    'an unpersisted internally approved draft cannot create evidence-bound proposal');
f81Check(f81Denied(fn()=>Proposal::prepare($b,$draftA,$decisionA)),
    'tenant B cannot use tenant A persisted approval');
f81Check(f81Denied(fn()=>Proposal::prepare($a,$draftB,$decisionB)),
    'tenant A cannot use tenant B persisted approval');
f81Check(f81Denied(fn()=>Proposal::prepare($owner,$draftA,$decisionA)),
    'privileged DB owner does not masquerade as fictional tenant runtime');
f81Check(f81Denied(fn()=>Proposal::prepare($a,$draftB,$decisionA)),
    'different draft tenant/release is denied before evidence read-back');
f81Check(f81Denied(fn()=>Proposal::prepare($a,$reworkDraft,$reworkDecision)),
    'persisted rework decision cannot produce informational Portal proposal');

$proofA=Proposal::prepare($a,$draftA,$decisionA);
$proofB=Proposal::prepare($b,$draftB,$decisionB);
f81Check($proofA['schemaVersion']==='sites.synthetic.evidence-bound-review-proposal.v1' &&
    $proofA['decisionRecordDigest']===$decisionA->reviewDigest &&
    $proofA['dossierDigest']===$decisionA->record['reviewedDossierDigest'] &&
    $proofA['bundleDigest']===$decisionA->record['reviewedBundleDigest'],
    'A review proposal is tied to persisted exact decision, dossier and bundle');
f81Check($proofB['candidate']['tenantRef']==='tenant-f79-b' &&
    $proofB['candidate']['releaseRef']==='release-f79-b',
    'B proposal uses own tenant and release after independent PG role verification');
f81Check(CandidateGuard::matchesLocalSnapshot(
    $proofA['candidate'],$decisionA->nextWorkflow,$draftA->manifest->releaseRef
), 'result matches exact informational internal review candidate');
f81Check($proofA['verifiedInDisposableLab']===true &&
    $proofA['externalPortalAuthorityStillRequired']===true &&
    $proofA['syntheticOnly']===true &&
    $proofA['publishable']===false &&
    $proofA['officialClientAcceptance']===false &&
    $proofA['candidate']['publishable']===false &&
    $proofA['candidate']['officialClientAcceptance']===false &&
    !isset($proofA['clientApproval']) && !isset($proofA['publishUrl']),
    'no persistence success can become customer acceptance or publication authority');

$revised=$decisionA->nextWorkflow->advance(
    'imagine_reviewer','return_for_revision',3
);
f81Check(!CandidateGuard::matchesLocalSnapshot(
    $proofA['candidate'],$revised,$draftA->manifest->releaseRef
), 'a subsequent revision invalidates the former proposal on the new snapshot');

putenv('SITES_F79_PG_LAB=0');
f81Check(f81Denied(fn()=>Proposal::prepare($a,$draftA,$decisionA)),
    'turning off PG18 lab flag prevents informational proposal');
putenv('SITES_F79_PG_LAB=1');

echo "\n$checks F1-81 evidence-bound proposal tests passed. No live Portal, client approval or deploy.\n";
