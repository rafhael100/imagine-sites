<?php

declare(strict_types=1);

/** F1-207: a metadata toggle is NEVER a re-used client approval. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $rel=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$rel))
        throw new RuntimeException('Unsafe fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$rel).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticSocialPreviewRevisionPlan as Revision;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\ReleaseArtifact as Artifact;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;

$checks=0;
function check207(bool $yes,string $why):void {
    global $checks;
    if (!$yes)throw new RuntimeException('F1-207 '.$why);
    ++$checks;
}
function denied207(callable $operation):bool {
    try{$operation();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$changed=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach([false,true] as $enabled) {
            $palette=Catalog::palettes()['ocean'];
            $order=Catalog::sectionOrders()['faq_process_services'];
            $nav=$product==='onepage';
            $base='release-f207-original-'.$product.'-'.$preset.'-'.($enabled?'on':'off');
            $new='release-f207-next-'.$product.'-'.$preset.'-'.($enabled?'off':'on');
            $source=Draft::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f207','site-f207',
                'workflow-f207-source-'.$product.'-'.$preset.'-'.($enabled?'on':'off'),
                $base,$order,$nav,$palette,$enabled
            );
            $approved=$source->workflow->advance(
                'imagine_reviewer','approve_internal',2,$source->artifact->digest
            );
            $plan=Revision::begin(
                $approved,$source->brief,$source->artifact,
                $base,!$enabled,$new,'imagine_reviewer',3
            );
            ++$changed;
            check207($plan->previousEnabled===$enabled &&
                $plan->nextEnabled===!$enabled &&
                $plan->nextBrief->socialPreviewMetadata===!$enabled &&
                $plan->previousDigest===$source->artifact->digest,
                'a typed toggle and previous SHA are pinned');
            check207($plan->nextBrief->brand===$source->brief->brand &&
                $plan->nextBrief->productCode===$product &&
                $plan->nextBrief->stylePreset===$preset &&
                $plan->nextBrief->sectionOrder===$order &&
                $plan->nextBrief->sectionNavigation===$nav &&
                $plan->nextBrief->brandPalette?->cssRules()===$palette->cssRules() &&
                $plan->nextBrief->faq===$source->brief->faq &&
                $plan->nextBrief->highlights===$source->brief->highlights &&
                $plan->nextBrief->processSteps===$source->brief->processSteps,
                'social card toggle cannot modify any editor-owned or customer copy');
            check207($plan->nextArtifact->digest!==$source->artifact->digest &&
                $plan->nextArtifact->digest===hash('sha256',$plan->nextArtifact->html) &&
                Audit::isSafe($plan->nextArtifact->html) &&
                QA::inspect($plan->nextArtifact)->passedStructuralChecks,
                'new revision has exact safe HTML with fresh SHA');
            check207(substr_count($plan->nextArtifact->html,'<meta ')===$enabled?5:12,
                'only requested seven optional social tags differ');
            check207($plan->draftWorkflow->state===Workflow::DRAFTING &&
                $plan->draftWorkflow->revision===4 &&
                $plan->draftWorkflow->artifactDigest===null &&
                $approved->artifactDigest===$source->artifact->digest,
                'old decision stays untouched and next revision needs a new review');
            check207(!$plan->canPublish() &&
                !$plan->canAcceptForClient() &&
                !$plan->canWriteCrm() &&
                !$plan->canReusePreviousApproval(),
                'not even a successful toggle implies customer approval or CRM access');

            $same=Draft::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f207','site-f207',
                'workflow-f207-verify-'.$product.'-'.$preset.'-'.($enabled?'on':'off'),
                $new,$order,$nav,$palette,!$enabled
            );
            check207($same->artifact->html===$plan->nextArtifact->html &&
                $same->artifact->digest===$plan->nextArtifact->digest &&
                $same->reviewSummary()['publishable']===false &&
                $same->reviewSummary()['officialClientAcceptance']===false,
                'fresh private render agrees byte-for-byte with review plan');

            $invoke=static fn(bool $target,string $nextRelease,
                string $actor='imagine_reviewer',int $revision=3,
                ?Artifact $sourceArtifact=null,?Workflow $sourceWorkflow=null)=>
                Revision::begin(
                    $sourceWorkflow??$approved,$source->brief,
                    $sourceArtifact??$source->artifact,
                    $base,$target,$nextRelease,$actor,$revision
                );
            check207(denied207(static fn()=>$invoke($enabled,$new)),
                'no-op must never fabricate a new review');
            check207(denied207(static fn()=>$invoke(!$enabled,$base)),
                'release identity cannot be reused');
            check207(denied207(static fn()=>$invoke(!$enabled,$new,'agent')),
                'AI actor string cannot approve toggle');
            check207(denied207(static fn()=>$invoke(!$enabled,$new,
                'imagine_reviewer',2)), 'stale version denied');
            check207(denied207(static fn()=>$invoke(!$enabled,$new,
                'imagine_reviewer',2,null,$source->workflow)),
                'unreviewed original source denied');
            $tampered=new Artifact($source->artifact->html."\n<!--modified-->");
            check207(denied207(static fn()=>$invoke(!$enabled,$new,
                'imagine_reviewer',3,$tampered)),
                'source HTML bytes cannot be replaced without new digest proof');
        }
    }
}
check207($changed===12,'exactly 12 on/off change proposals across products/themes');
echo "$checks F1-207 social preview toggle review assertions passed.\n";
