<?php

declare(strict_types=1);

/** F1-221: SEO meta edits need fresh SHA and review, not client acceptance. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $rel=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$rel))
        throw new RuntimeException('Unsafe test class');
    require __DIR__.'/../src/'.str_replace('\\','/',$rel).'.php';
});

use Imagine\Sites\Production\SyntheticSeoCopyRevisionPlan as SeoPlan;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticSeoSnippetInspectionV1 as Seo;
use Imagine\Sites\Production\SyntheticSocialCardInspectionV1 as Social;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\ReleaseArtifact as Artifact;

$checks=0;
function check221(bool $okay,string $case):void {
    global $checks;
    if(!$okay)throw new RuntimeException('F1-221 '.$case);
    ++$checks;
}
function denied221(callable $cb):bool {
    try{$cb();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$title='Estratégias digitais para negócios | Imagine Demonstração';
$description='Texto sintético exclusivamente editorial, diferente do conteúdo visível do hero, sempre com revisão independente.';
$cases=0;
foreach(['onepage','landing_page'] as $product){
    foreach(['classic','modern','warm'] as $preset){
        foreach([false,true] as $social){
            $source=Draft::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f221','site-f221',
                'workflow-f221-'.$product.'-'.$preset.'-'.(int)$social,
                'release-f221-old-'.$product.'-'.$preset.'-'.(int)$social,
                ['highlights','process','faq'],false,null,$social
            );
            $approved=$source->workflow->advance(
                'imagine_reviewer','approve_internal',2,$source->artifact->digest
            );
            $newRef='release-f221-new-'.$product.'-'.$preset.'-'.(int)$social;
            $make=static fn(?string $newTitle,?string $newDescription,
                string $release, string $role='imagine_reviewer',
                int $revision=3,?Artifact $old=null,?Workflow $workflow=null)=>
                SeoPlan::begin(
                    $workflow??$approved,$source->brief,
                    $old??$source->artifact,
                    $source->manifest->releaseRef,
                    $newTitle,$newDescription,$release,$role,$revision
                );

            $plan=$make($title,$description,$newRef);
            ++$cases;
            check221($plan->changedFields===['seoTitle','seoDescription'] &&
                $plan->nextBrief->seoTitle===$title &&
                $plan->nextBrief->seoDescription===$description &&
                $plan->nextBrief->productCode===$product &&
                $plan->nextBrief->stylePreset===$preset &&
                $plan->nextBrief->headline===$source->brief->headline &&
                $plan->nextBrief->description===$source->brief->description &&
                $plan->nextBrief->faq===$source->brief->faq &&
                $plan->nextBrief->highlights===$source->brief->highlights &&
                $plan->nextBrief->processSteps===$source->brief->processSteps &&
                $plan->nextBrief->socialPreviewMetadata===$social,
                'SEO-only operation preserves all page body and official choices');
            check221($plan->nextArtifact->digest===hash('sha256',$plan->nextArtifact->html) &&
                $plan->nextArtifact->digest!==$source->artifact->digest &&
                $plan->previousDigest===$source->artifact->digest &&
                $approved->artifactDigest===$source->artifact->digest,
                'new SEO head bytes get new digest, original approved bytes immutable');
            $report=Seo::inspect($plan->nextBrief,$plan->nextArtifact);
            check221($report->title===$title &&
                $report->description===$description &&
                $report->headline===$source->brief->headline &&
                !$report->canIndexOnSearchEngines(),
                'SEO report uses new exact title/description, unchanged H1, noindex');
            check221($plan->draftWorkflow->state===Workflow::DRAFTING &&
                $plan->draftWorkflow->artifactDigest===null &&
                $plan->draftWorkflow->revision===4 &&
                $approved->revision===3,
                'new SEO copy reopens review and cannot inherit old approval');
            check221(!$plan->canPublish() && !$plan->canIndex() &&
                !$plan->canWriteSeoEngine() &&
                !$plan->canApproveForClient() &&
                !$plan->canReusePriorReview(),
                'proposed SEO changes never imply indexing, CRM or customer acceptance');
            if($social){
                $card=Social::inspect($plan->nextBrief,$plan->nextArtifact);
                check221($card->title===$title &&
                    $card->description===$description &&
                    !$card->canShareExternally(),
                    'social card stays tied to changed SEO without sharing');
            }
            $onlyTitle=$make($title,null,$newRef.'-only-title');
            check221($onlyTitle->changedFields===['seoTitle'] &&
                $onlyTitle->nextBrief->seoDescription===null,
                'title-only SEO update requires review independently');
            $onlyDescription=$make(null,$description,$newRef.'-only-description');
            check221($onlyDescription->changedFields===['seoDescription'] &&
                $onlyDescription->nextBrief->seoTitle===null,
                'description-only SEO update requires review independently');

            check221(denied221(static fn()=>$make(null,null,$newRef.'-noop')),
                'identical SEO metadata cannot create a fake new release');
            check221(denied221(static fn()=>$make($title,$description,
                $source->manifest->releaseRef)),
                'prior release reference cannot be reused');
            check221(denied221(static fn()=>$make($title,$description,
                $newRef.'-agent','agent')),
                'untrusted agent role cannot propose an approved SEO revision');
            check221(denied221(static fn()=>$make($title,$description,
                $newRef.'-stale','imagine_reviewer',2)),
                'stale approved revision is rejected');
            check221(denied221(static fn()=>$make($title,$description,
                $newRef.'-pending','imagine_reviewer',2,null,$source->workflow)),
                'unapproved source snapshot cannot be reused');
            check221(denied221(static fn()=>$make($title,$description,
                $newRef.'-bad-original','imagine_reviewer',3,
                new Artifact($source->artifact->html.'<!-- modified -->'))),
                'tampered source HTML cannot produce an approved SEO change');
            check221(denied221(static fn()=>$make(
                'https://untrusted.invalid',$description,$newRef.'-url'
            )),'URL-bearing SEO title denied before any new draft');
            check221(denied221(static fn()=>$make(
                '<script>alert(1)</script>',$description,$newRef.'-markup'
            )),'active-looking title denied even though output would be escaped');
        }
    }
}
check221($cases===12,'12 independent approved source combinations revised');

$original=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,
    'tenant-f221','site-f221','workflow-f221-clear','release-f221-clear',
    ['highlights','process','faq'],false,null,true,$title,$description
);
$approved=$original->workflow->advance(
    'imagine_reviewer','approve_internal',2,$original->artifact->digest
);
$cleared=SeoPlan::begin(
    $approved,$original->brief,$original->artifact,
    $original->manifest->releaseRef,null,null,
    'release-f221-cleared','imagine_reviewer',3
);
check221($cleared->changedFields===['seoTitle','seoDescription'] &&
    $cleared->nextBrief->seoTitle===null &&
    $cleared->nextBrief->seoDescription===null &&
    Seo::inspect($cleared->nextBrief,$cleared->nextArtifact)->title===
        $original->brief->headline.' | '.$original->brief->brand,
    'clearing overrides is an explicit reviewed change back to automatic metadata');

$auto=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,
    'tenant-f221','site-f221','workflow-f221-automatic','release-f221-automatic'
);
$autoApproved=$auto->workflow->advance(
    'imagine_reviewer','approve_internal',2,$auto->artifact->digest
);
check221(denied221(static fn()=>SeoPlan::begin(
    $autoApproved,$auto->brief,$auto->artifact,
    $auto->manifest->releaseRef,
    $auto->brief->headline.' | '.$auto->brief->brand,
    $auto->brief->description,
    'release-f221-same-html','imagine_reviewer',3
)), 'field-state change with identical rendered metadata cannot fake a new review');

echo "$checks F1-221 reviewed SEO copy revisions passed; ".
    "no indexing, client approval, Google, CRM or deploy.\n";
