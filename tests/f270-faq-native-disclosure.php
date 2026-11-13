<?php

declare(strict_types=1);

/** F1-270: editor-selected native FAQ disclosure for fictitious offline sites. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unexpected F1-270 class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as PrivateFiles;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as StyleRevision;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as CopyRevision;

function check270(bool $passed,string $message):void {
    global $checks;
    if(!$passed)throw new RuntimeException('F1-270 '.$message);
    ++$checks;
}
function denied270(callable $operation):bool {
    try{$operation();}catch(InvalidArgumentException|DomainException){return true;}
    return false;
}
$checks=0;
$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        foreach(Catalog::palettes() as $paletteName=>$palette) {
            $id="$product-$style-$paletteName";
            $base=[$product,'Marca Fictícia Imagine',$style,$copy,
                'tenant-f270','site-f270'];
            $off=Draft::build(
                ...[...$base,'workflow-f270-'.$id.'-off','release-f270-'.$id.'-off'],
                officialBrandPalette:$palette
            );
            $on=Draft::build(
                ...[...$base,'workflow-f270-'.$id.'-on','release-f270-'.$id.'-on'],
                officialBrandPalette:$palette,
                officialFaqDisclosure:true
            );
            $html=$on->artifact->html;
            check270($off->brief->faqDisclosure===false &&
                $on->brief->faqDisclosure===true &&
                !str_contains($off->artifact->html,'<details>') &&
                substr_count($html,'<details>')===count($on->brief->faq) &&
                substr_count($html,'<summary>')===count($on->brief->faq) &&
                !str_contains($html,'<script') &&
                !str_contains($html,'<form'),
                'opt-in native semantic FAQ: '.$id);
            check270($off->artifact->digest!==$on->artifact->digest &&
                hash_equals(hash('sha256',$html),$on->artifact->digest) &&
                hash_equals($off->artifact->digest,hash('sha256',$off->artifact->html)),
                'different content-addressed bytes and legacy layout preserved: '.$id);
            check270(InertPreviewAudit::isSafe($html) &&
                SyntheticPreviewQaReport::inspect($on->artifact)->passedStructuralChecks &&
                $on->reviewSummary()['publishable']===false &&
                $on->reviewSummary()['officialClientAcceptance']===false,
                'read-only structural QA and private review: '.$id);
            $files=PrivateFiles::fromPendingDraft($on);
            check270(array_keys($files)===[
                'index.html','internal-review-dossier.json',
                'internal-review-draft.json','release-manifest.json'
            ] && $files['index.html']===$html &&
                $on->workflow->state==='internal_review' &&
                $on->workflow->artifactDigest===$on->artifact->digest,
                'canonical four-file review and pending approval: '.$id);
            $mutated=str_replace('<details>','<details open>',$html);
            check270($mutated!==$html &&
                !InertPreviewAudit::isSafe($mutated),
                'open-by-default mutation denied: '.$id);
            $mutated=str_replace('</main>','<summary>Bad placement</summary></main>',$html);
            check270($mutated!==$html &&
                !SyntheticPreviewQaReport::inspect(new ReleaseArtifact($mutated))
                    ->passedStructuralChecks,
                'stray summary outside FAQ rejected: '.$id);
            $mutated=str_replace('<summary>','<summary onclick="x()">',$html);
            check270(!InertPreviewAudit::isSafe($mutated),
                'inline event handler denied: '.$id);
        }
    }
}
$draft=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,'tenant-f270','site-f270',
    'workflow-f270-style','release-f270-style',officialFaqDisclosure:true
);
$approved=$draft->workflow->advance(
    'imagine_reviewer','approve_internal',2,$draft->artifact->digest
);
$revised=StyleRevision::begin(
    $approved,$draft->brief,$draft->artifact,
    'release-f270-style','warm','release-f270-warm','imagine_reviewer',3
);
check270($revised->nextBrief->faqDisclosure &&
    str_contains($revised->nextArtifact->html,'<details>') &&
    $revised->draftWorkflow->artifactDigest===null &&
    $revised->draftWorkflow->state==='drafting',
    'style revision preserves editorial disclosure but invalidates approval');

$before=$draft->brief;
$toggled=new SiteBrief(
    $before->productCode,$before->brand,$before->headline,$before->description,
    $before->callToAction,$before->benefits,$before->stylePreset,$before->faq,
    $before->highlights,$before->processSteps,$before->sectionOrder,
    $before->sectionNavigation,$before->brandPalette,$before->socialPreviewMetadata,
    $before->seoTitle,$before->seoDescription,
    $before->secondaryCallToAction,false
);
check270(denied270(static fn()=>CopyRevision::begin(
    $approved,$before,$draft->artifact,'release-f270-style',
    $toggled,'release-f270-unapproved-change','imagine_reviewer',3
)), 'ordinary copy revision cannot bypass editor-owned FAQ presentation');
$noFaq=new SiteBrief(
    'onepage','Marca Fictícia Imagine','Demonstração de teste',
    'Texto sintético e sem envio de dados.','Conheça mais',
    ['Uma vantagem'],faqDisclosure:false
);
check270(!str_contains((new \Imagine\Sites\Production\StaticSiteRenderer())->render($noFaq)->html,
    '<details>'), 'existing empty FAQ is unchanged');
check270(denied270(static fn()=>new SiteBrief(
    'onepage','Marca Fictícia Imagine','Demonstração de teste',
    'Texto sintético e sem envio de dados.','Conheça mais',
    ['Uma vantagem'],faqDisclosure:true
)), 'cannot opt in without any FAQ');
$tainted=$copy;
$tainted['faqDisclosure']=true;
check270(denied270(static fn()=>Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$tainted,
    'tenant-f270','site-f270','workflow-f270-tainted','release-f270-tainted'
)), 'AI copy cannot control semantic FAQ rendering');

echo "$checks F1-270 FAQ native-disclosure checks passed; no script/PII/deploy.\n";
