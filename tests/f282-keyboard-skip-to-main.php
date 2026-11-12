<?php

declare(strict_types=1);

/** F1-282: two products x three presets x four safe palettes, no public site. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $path=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$path)) {
        throw new RuntimeException('Unexpected F1-282 class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
});
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as PrivateFiles;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as StyleRevision;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as ContentRevision;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\ReleaseArtifact;

$checks=0;
function check282(bool $ok,string $what):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-282 '.$what);
    ++$checks;
}
function denied282(callable $cb):bool {
    try{$cb();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
$link='<a class="skip-to-main" href="#principal">Pular para o conteúdo</a>';
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        foreach(Catalog::palettes() as $paletteName=>$palette){
            $id="$product-$style-$paletteName";
            $args=[$product,'Marca Fictícia Imagine',$style,$copy,
                'tenant-f282','site-f282'];
            $off=Draft::build(
                ...[...$args,'workflow-f282-'.$id.'-off','release-f282-'.$id.'-off'],
                officialBrandPalette:$palette
            );
            $on=Draft::build(
                ...[...$args,'workflow-f282-'.$id.'-on','release-f282-'.$id.'-on'],
                officialBrandPalette:$palette,officialSkipToMain:true
            );
            $html=$on->artifact->html;
            $position=strpos($html,$link);
            check282($off->brief->skipToMain===false &&
                $on->brief->skipToMain===true &&
                !str_contains($off->artifact->html,'skip-to-main') &&
                $position!==false &&
                $position<strpos($html,'<header>') &&
                substr_count($html,$link)===1 &&
                substr_count($html,'href="#principal"')===1,
                'first-tab keyboard bypass opt-in only: '.$id);
            check282($on->artifact->digest!==$off->artifact->digest &&
                hash_equals(hash('sha256',$html),$on->artifact->digest) &&
                hash_equals($off->artifact->digest,hash('sha256',$off->artifact->html)),
                'new content-addressed bytes with unchanged default: '.$id);
            check282(Audit::isSafe($html) &&
                QA::inspect($on->artifact)->passedStructuralChecks &&
                $on->reviewSummary()['publishable']===false &&
                $on->reviewSummary()['officialClientAcceptance']===false &&
                count(PrivateFiles::fromPendingDraft($on))===4,
                'inert, QA-valid, private review package: '.$id);
            check282(str_contains($html,'.skip-to-main:focus-visible') &&
                str_contains($html,'transform: translateY(0);') &&
                str_contains($html,'min-height: 44px;') &&
                !str_contains($html,'<form') &&
                !str_contains($html,'<script') &&
                !str_contains($html,'https://'),
                'visible focus, 44px target, no active elements: '.$id);
            $tampered=str_replace('href="#principal"','href="https://evil.invalid"',$html);
            check282($tampered!==$html && !Audit::isSafe($tampered),
                'externally redirected skip link denied: '.$id);
            $unnamed=str_replace($link,
                '<a class="skip-to-main" href="#principal"></a>',$html);
            check282($unnamed!==$html &&
                !QA::inspect(new ReleaseArtifact($unnamed))->passedStructuralChecks,
                'unnamed keyboard link fails semantic QA: '.$id);
        }
    }
}
$source=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,
    'tenant-f282','site-f282','workflow-f282-reviewed','release-f282-reviewed',
    officialSkipToMain:true
);
$approved=$source->workflow->advance(
    'imagine_reviewer','approve_internal',2,$source->artifact->digest
);
$style=StyleRevision::begin($approved,$source->brief,$source->artifact,
    'release-f282-reviewed','warm','release-f282-changed',
    'imagine_reviewer',3);
check282($style->nextBrief->skipToMain &&
    str_contains($style->nextArtifact->html,$link) &&
    $style->draftWorkflow->artifactDigest===null,
    'styling keeps keyboard bypass but resets review');
$b=$source->brief;
$toggle=new SiteBrief(
    $b->productCode,$b->brand,$b->headline,$b->description,
    $b->callToAction,$b->benefits,$b->stylePreset,$b->faq,
    $b->highlights,$b->processSteps,$b->sectionOrder,$b->sectionNavigation,
    $b->brandPalette,$b->socialPreviewMetadata,$b->seoTitle,
    $b->seoDescription,$b->secondaryCallToAction,$b->faqDisclosure,false
);
check282(denied282(static fn()=>ContentRevision::begin(
    $approved,$b,$source->artifact,'release-f282-reviewed',
    $toggle,'release-f282-illicit','imagine_reviewer',3
)), 'copy editor cannot toggle reviewer-owned skip link');
$tainted=$copy;
$tainted['skipToMain']=true;
check282(denied282(static fn()=>Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$tainted,
    'tenant-f282','site-f282','workflow-f282-tainted','release-f282-tainted'
)), 'untrusted AI cannot control keyboard nav');
echo "$checks F1-282 keyboard bypass HTML assertions passed. No public site.\n";
