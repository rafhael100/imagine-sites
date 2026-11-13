<?php

declare(strict_types=1);

/** F1-77: a dossier is a pending checklist, never a reviewer decision. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('Unsafe F1-77 class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticInternalReviewDossier as Dossier;

$checks=0;
function f77Check(bool $passed,string $name):void {
    global $checks;
    if(!$passed)throw new RuntimeException('FAIL '.$name);
    ++$checks; echo "PASS $name\n";
}
$good=[
 'schemaVersion'=>'sites.ai-copy.v1',
 'headline'=>'Crie uma presença digital clara e profissional',
 'description'=>'Uma apresentação inteiramente fictícia que explica os serviços, os benefícios e a proposta de atendimento para testes privados.',
 'callToAction'=>'Conheça as soluções',
 'benefits'=>['Clareza','Agilidade','Confiança'],
];
$weak=array_replace($good,[
    'headline'=>'Oi','description'=>'Texto curto.','callToAction'=>'Ir','benefits'=>['Um ponto'],
]);
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        foreach(['complete'=>$good,'weak'=>$weak] as $variant=>$copy) {
            $source=Assembly::build(
                $product,'Marca Fictícia',$style,$copy,
                'tenant-f77','site-f77',"flow-f77-$product-$style-$variant",
                "release-f77-$product-$style-$variant"
            );
            $d=Dossier::fromDraft($source);
            $again=Dossier::fromDraft($source);
            $record=$d->record;
            f77Check($d->json===$again->json &&
                $d->digest===$again->digest &&
                hash('sha256',$d->json)===$d->digest,
                "$product/$style/$variant: dossier is deterministic");
            f77Check($record['artifactDigest']===$source->artifact->digest &&
                $record['bundleDigest']===$source->bundle->bundleDigest &&
                $record['releaseRef']===$source->manifest->releaseRef &&
                $record['workflowRevision']===2,
                "$product/$style/$variant: hashes and version bound");
            f77Check($record['reviewerDecisionStatus']==='pending' &&
                $record['explicitInternalReviewRequired']===true &&
                $record['portalAuthorityRequired']===true &&
                $record['syntheticOnly']===true &&
                $record['officialClientAcceptance']===false &&
                $record['publishable']===false,
                "$product/$style/$variant: approval never implied");
            $pending=[];
            foreach($source->editorialReport->checks as $key=>$pass) {
                if(!$pass) $pending[]=$key;
            }
            f77Check($record['qaChecks']===$source->structuralReport->checks &&
                $record['editorialChecks']===$source->editorialReport->checks &&
                $record['requiredReviewerAcknowledgments']===$pending &&
                $record['editorialSuggestions']===$source->editorialReport->suggestions,
                "$product/$style/$variant: reviewer gets exact QA/advice checklist");
            f77Check(($variant==='weak')===($pending!==[]) &&
                !array_key_exists('clientAcceptance',$record) &&
                !array_key_exists('approvalSignature',$record),
                "$product/$style/$variant: findings reported without real authority");
        }
    }
}
echo "\n$checks F1-77 review dossier checks passed. No real decisions or publication.\n";
