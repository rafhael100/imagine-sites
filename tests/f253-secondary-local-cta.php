<?php

declare(strict_types=1);

/**
 * F1-253: explicit editor-controlled secondary local CTA in both products.
 * These examples are fictional, no contact collection, URLs or publication.
 */
spl_autoload_register(static function(string $class): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unexpected F1-253 fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan;
use Imagine\Sites\Production\SyntheticSectionOrderRevisionPlan;
use Imagine\Sites\Production\SyntheticEditorialPatchV1;
use Imagine\Sites\Production\SyntheticContentRevisionPlan;
use Imagine\Sites\Production\SyntheticReleaseBundle;

$checks=0;
function ok253(bool $condition,string $label):void {
    global $checks;
    if (!$condition) throw new RuntimeException('F1-253 '.$label);
    ++$checks;
}
function denied253(callable $operation):bool {
    try {$operation();}
    catch (DomainException|InvalidArgumentException) {return true;}
    return false;
}

$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
foreach (['onepage','landing_page'] as $product) {
    foreach (['classic','modern','warm'] as $style) {
        $id=$product.'-'.$style;
        $arguments=[
            $product,'Marca Fictícia Imagine',$style,$copy,
            'tenant-f253','site-f253',
            'workflow-f253-'.$id,'release-f253-'.$id,
        ];
        $default=SyntheticStructuredDraftAssembly::build(...$arguments);
        $enabled=SyntheticStructuredDraftAssembly::build(
            ...$arguments,officialSecondaryCallToAction:true
        );
        $html=$enabled->artifact->html;
        $link='<a class="btn secondary-cta" href="#contato">'.
            htmlspecialchars(
                $enabled->brief->callToAction,
                ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8'
            ).'</a>';
        ok253(!$default->brief->secondaryCallToAction &&
            $enabled->brief->secondaryCallToAction &&
            !str_contains($default->artifact->html,'secondary-cta') &&
            str_contains($html,$link) &&
            substr_count($html,'class="btn secondary-cta"')===1,
            'CTA only when explicitly opted in: '.$id);
        ok253(!hash_equals($default->artifact->digest,$enabled->artifact->digest) &&
            hash_equals(hash('sha256',$html),$enabled->artifact->digest),
            'toggle is a distinct content-addressed revision: '.$id);
        ok253(InertPreviewAudit::isSafe($html) &&
            SyntheticPreviewQaReport::inspect($enabled->artifact)->passedStructuralChecks &&
            SyntheticReleaseBundle::verifyFiles(
                $enabled->bundle->files,$enabled->manifest
            ),'safe local HTML, semantic audit and bundle: '.$id);
        ok253(str_contains($html,'background: #173e69; color: #fff;') &&
            str_contains($html,'min-height: 44px') &&
            substr_count($html,'href="#contato"')===2 &&
            !str_contains($html,'<form') &&
            !str_contains($html,'http://') &&
            !str_contains($html,'https://'),
            'contrast, touch target and two local conversion links: '.$id);
        ok253($enabled->workflow->state==='internal_review' &&
            $enabled->reviewSummary()['publishable']===false &&
            $enabled->reviewSummary()['officialClientAcceptance']===false &&
            $enabled->reviewSummary()['portalAuthorityRequired']===true,
            'editorial CTA cannot bypass human or Portal approval: '.$id);

        $approved=$enabled->workflow->advance(
            'imagine_reviewer','approve_internal',2,$enabled->artifact->digest
        );
        $styleNext=$style==='classic'?'modern':'classic';
        $stylePlan=SyntheticStyleRevisionPlan::begin(
            $approved,$enabled->brief,$enabled->artifact,
            'release-original-'.$id,$styleNext,
            'release-style-'.$id,'imagine_reviewer',3
        );
        ok253($stylePlan->nextBrief->secondaryCallToAction &&
            $stylePlan->draftWorkflow->artifactDigest===null &&
            InertPreviewAudit::isSafe($stylePlan->nextArtifact->html),
            'style revision preserves CTA but resets review: '.$id);
        $orderPlan=SyntheticSectionOrderRevisionPlan::begin(
            $approved,$enabled->brief,$enabled->artifact,
            'release-original-'.$id,['faq','process','highlights'],
            'release-order-'.$id,'imagine_reviewer',3
        );
        ok253($orderPlan->nextBrief->secondaryCallToAction &&
            $orderPlan->draftWorkflow->artifactDigest===null &&
            InertPreviewAudit::isSafe($orderPlan->nextArtifact->html),
            'section-order revision preserves CTA and resets review: '.$id);
        $patch=SyntheticEditorialPatchV1::propose(
            $approved,$enabled->brief,$enabled->artifact,
            'release-original-'.$id,'release-patch-'.$id,'imagine_reviewer',[
                'schemaVersion'=>'sites.editorial.patch.v1',
                'expectedDigest'=>$enabled->artifact->digest,
                'expectedRevision'=>3,
                'changes'=>['headline'=>'Nova apresentação fictícia da solução'],
            ]
        );
        ok253($patch->nextBrief->secondaryCallToAction &&
            $patch->draftWorkflow->artifactDigest===null &&
            InertPreviewAudit::isSafe($patch->nextArtifact->html),
            'copy patch preserves CTA without inheriting approval: '.$id);
        $copyOnly=new SiteBrief(
            $enabled->brief->productCode,$enabled->brief->brand,
            'Nova manchete controlada',$enabled->brief->description,
            $enabled->brief->callToAction,$enabled->brief->benefits,
            $enabled->brief->stylePreset,$enabled->brief->faq,
            $enabled->brief->highlights,$enabled->brief->processSteps,
            $enabled->brief->sectionOrder,$enabled->brief->sectionNavigation,
            $enabled->brief->brandPalette,$enabled->brief->socialPreviewMetadata,
            $enabled->brief->seoTitle,$enabled->brief->seoDescription,
            false
        );
        ok253(denied253(static fn()=>SyntheticContentRevisionPlan::begin(
            $approved,$enabled->brief,$enabled->artifact,
            'release-original-'.$id,$copyOnly,
            'release-illicit-'.$id,'imagine_reviewer',3
        )),'normal copy edit cannot disable editor-owned conversion CTA: '.$id);
    }
}
$tainted=$copy;
$tainted['secondaryCallToAction']=true;
ok253(denied253(static fn()=>SyntheticStructuredDraftAssembly::build(
    'onepage','Marca Fictícia Imagine','classic',$tainted,
    'tenant-f253','site-f253','workflow-tainted','release-tainted'
)),'untrusted AI copy cannot opt in to conversion CTA');
echo "$checks F1-253 offline secondary CTA assertions passed; no public sites or data.\n";
