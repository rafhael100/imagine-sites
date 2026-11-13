<?php

declare(strict_types=1);

/**
 * F1-200: canonical no-approval evidence for 48 layout modifications.
 * Pure PHP on fake pages, no client identity, CRM, DB, HTTP or filesystem write.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unexpected synthetic class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticCombinedLayoutRevisionPlan as Revision;
use Imagine\Sites\Production\SyntheticLayoutChangeReviewEvidenceV1 as Evidence;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;

$assertions=0;
function check200(bool $condition,string $reason):void {
    global $assertions;
    if(!$condition)throw new RuntimeException('F1-200 '.$reason);
    ++$assertions;
}
function deny200(callable $fn):bool {
    try {$fn();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$orders=[
    ['highlights','process','faq'],
    ['highlights','faq','process'],
    ['process','highlights','faq'],
    ['process','faq','highlights'],
    ['faq','highlights','process'],
    ['faq','process','highlights'],
];
$seen=[];
$validCount=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $source=Assembly::build(
            $product,'Marca Fictícia Imagine',$style,$copy,
            'tenant-f200','site-f200','workflow-f200-'.$product.'-'.$style,
            'release-f200-before-'.$product.'-'.$style
        );
        $approved=$source->workflow->advance(
            'imagine_reviewer','approve_internal',2,$source->artifact->digest
        );
        foreach($orders as $number=>$order) {
            foreach($product==='onepage'?['off','on']:['off'] as $mode) {
                if($number===0 && $mode==='off')continue;
                $id='release-f200-after-'.$product.'-'.$style.'-'.$number.'-'.$mode;
                $plan=Revision::begin(
                    $approved,$source->brief,$source->artifact,
                    $source->manifest->releaseRef,$order,$mode==='on',
                    $id,'imagine_reviewer',3
                );
                $evidence=Evidence::fromPlan(
                    $plan,$approved,$source->brief,$source->artifact
                );
                $verified=Evidence::verifyJson(
                    $evidence->json,$plan,$approved,$source->brief,$source->artifact
                );
                check200($verified->json===$evidence->json &&
                    $verified->digest===$evidence->digest &&
                    hash_equals(hash('sha256',$evidence->json),$evidence->digest),
                    'verified canonical JSON hashes exactly');
                check200($evidence->record['schemaVersion']===
                    'sites.synthetic.layout-change-evidence.v1' &&
                    $evidence->record['syntheticOnly']===true &&
                    $evidence->record['reviewerDecisionStatus']==='pending' &&
                    $evidence->record['previousRevision']===3 &&
                    $evidence->record['nextDraftRevision']===4 &&
                    $evidence->record['newInternalReviewRequired']===true &&
                    $evidence->record['portalAuthorityRequired']===true &&
                    $evidence->record['officialClientAcceptance']===false &&
                    $evidence->record['publishable']===false &&
                    $evidence->record['crmDeliveryAuthorized']===false,
                    'change evidence never claims Portal, CRM or reviewer authority');
                check200($evidence->record['previousArtifactDigest']===$source->artifact->digest &&
                    $evidence->record['nextArtifactDigest']===$plan->nextArtifact->digest &&
                    $evidence->record['previousReleaseRef']===$source->manifest->releaseRef &&
                    $evidence->record['nextReleaseRef']===$id &&
                    $evidence->record['previousSectionOrder']===$source->brief->sectionOrder &&
                    $evidence->record['nextSectionOrder']===$order &&
                    $evidence->record['previousNavigation']===false &&
                    $evidence->record['nextNavigation']===($mode==='on'),
                    'version and layout controls remain source-bound');
                check200(!$evidence->canPublish() && !$evidence->canAcceptForClient() &&
                    !$evidence->canWriteCrm() && !$evidence->canReusePreviousReview(),
                    'no evidence can itself authorize release or reuse approval');
                check200($evidence->json===Evidence::fromPlan(
                    $plan,$approved,$source->brief,$source->artifact
                )->json,'same source and plan yields deterministic JSON');
                $seen[]=$evidence->digest;
                ++$validCount;

                $wrong=json_decode($evidence->json,true,16,JSON_THROW_ON_ERROR);
                $wrong['publishable']=true;
                $modified=json_encode($wrong,
                    JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|
                    JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
                check200(deny200(static fn()=>Evidence::verifyJson(
                    $modified,$plan,$approved,$source->brief,$source->artifact
                )),'forged publishable flag denied');
                $wrong=json_decode($evidence->json,true,16,JSON_THROW_ON_ERROR);
                $wrong['unexpectedApproval']='yes';
                $modified=json_encode($wrong,
                    JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|
                    JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
                check200(deny200(static fn()=>Evidence::verifyJson(
                    $modified,$plan,$approved,$source->brief,$source->artifact
                )),'extra untrusted JSON field denied');
                check200(deny200(static fn()=>Evidence::verifyJson(
                    $evidence->json."\n",$plan,$approved,$source->brief,$source->artifact
                )),'noncanonical trailing newline denied');
                check200(deny200(static fn()=>Evidence::verifyJson(
                    $evidence->json,$plan,$source->workflow,
                    $source->brief,$source->artifact
                )),'pending source cannot impersonate reviewed source');
                $tampered=new ReleaseArtifact($source->artifact->html.'<!-- mutated -->');
                check200(deny200(static fn()=>Evidence::fromPlan(
                    $plan,$approved,$source->brief,$tampered
                )),'changed original bytes cannot be accepted on old hash');
            }
        }
    }
}
check200($validCount===48 && count(array_unique($seen))===48,
    '48 changed configurations produce 48 distinct canonical evidence digests');
echo "PASS F1-200: $assertions canonical layout-change evidence and negative proofs.\n";
