<?php

declare(strict_types=1);

/** F1-277: optional editor-owned, safe keyboard skip-to-content link. */
spl_autoload_register(static function (string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $part=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unsafe F1-277 class name');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as Style;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as CopyEdit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as Qa;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as Package;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verify;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\ReleaseArtifact;

$count=0;
function ok277(bool $yes,string $test):void {
    global $count;
    if (!$yes) throw new RuntimeException('F1-277 '.$test);
    ++$count;
}
function refused277(callable $op):bool {
    try {$op();} catch(DomainException|InvalidArgumentException) {return true;}
    return false;
}
$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
$link='<a class="skip-content" href="#principal">Pular para o conteúdo</a>';
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        foreach(Catalog::palettes() as $code=>$palette) {
            $id="$product-$style-$code";
            $args=[$product,'Marca Fictícia Imagine',$style,$copy,
                'tenant-f277','site-f277'];
            $plain=Draft::build(
                ...[...$args,'workflow-f277-'.$id.'-plain','release-f277-'.$id.'-plain'],
                officialBrandPalette:$palette
            );
            $enabled=Draft::build(
                ...[...$args,'workflow-f277-'.$id.'-skip','release-f277-'.$id.'-skip'],
                officialBrandPalette:$palette,officialSkipToContentLink:true
            );
            $html=$enabled->artifact->html;
            ok277(!$plain->brief->skipToContentLink &&
                $enabled->brief->skipToContentLink &&
                !str_contains($plain->artifact->html,$link) &&
                substr_count($html,$link)===1 &&
                str_contains($html,">\n  ".$link."\n  <header>") &&
                str_contains($html,'<main id="principal">'),
                'opt-in single first-in-body anchor: '.$id);
            ok277($plain->artifact->digest!==$enabled->artifact->digest &&
                hash_equals(hash('sha256',$html),$enabled->artifact->digest) &&
                $plain->artifact->html===(new StaticSiteRenderer())->render(
                    $plain->brief)->html,
                'legacy HTML unchanged, opt-in new digest: '.$id);
            ok277(Audit::isSafe($html) &&
                Qa::inspect($enabled->artifact)->passedStructuralChecks &&
                $enabled->reviewSummary()['publishable']===false &&
                $enabled->reviewSummary()['officialClientAcceptance']===false &&
                $enabled->workflow->state==='internal_review',
                'safe review only, no publication or acceptance: '.$id);
            $files=Package::fromPendingDraft($enabled);
            $verified=Verify::verifyBytes($files);
            ok277($verified['artifactDigest']===$enabled->artifact->digest &&
                $files['index.html']===$html &&
                count($files)===4,
                'canonical private four-file package: '.$id);
            $clones=[
                'duplicate link'=>str_replace(
                    $link, $link."\n  ".$link, $html),
                'move to footer'=>str_replace(
                    '<footer>', $link.'<footer>',
                    str_replace($link,'',$html)),
                'weaken focus'=>str_replace(
                    '.skip-content:focus-visible { transform: none; outline: 3px solid #2366bc; outline-offset: 3px; }',
                    '.skip-content:focus-visible { transform: none; outline: 0; }',$html),
                'arbitrary target'=>str_replace(
                    'class="skip-content" href="#principal"',
                    'class="skip-content" href="#contato"',$html),
                'event handler'=>str_replace(
                    'class="skip-content" href="#principal"',
                    'class="skip-content" onclick="alert(1)" href="#principal"',$html),
            ];
            foreach($clones as $why=>$other) {
                ok277($other!==$html && !Audit::isSafe($other),
                    'forged link denied ('.$why.'): '.$id);
            }
        }
    }
}
$original=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,
    'tenant-f277','site-f277','workflow-f277-change',
    'release-f277-change',officialSkipToContentLink:true
);
$approved=$original->workflow->advance(
    'imagine_reviewer','approve_internal',2,$original->artifact->digest
);
$style=Style::begin(
    $approved,$original->brief,$original->artifact,
    'release-f277-change','warm','release-f277-style',
    'imagine_reviewer',3
);
ok277($style->nextBrief->skipToContentLink &&
    str_contains($style->nextArtifact->html,$link) &&
    $style->draftWorkflow->artifactDigest===null,
    'style revision retains keyboard option but revokes approval');

$old=$original->brief;
$copyOnly=new SiteBrief(
    $old->productCode,$old->brand,$old->headline,$old->description,
    $old->callToAction,$old->benefits,$old->stylePreset,$old->faq,
    $old->highlights,$old->processSteps,$old->sectionOrder,
    $old->sectionNavigation,$old->brandPalette,$old->socialPreviewMetadata,
    $old->seoTitle,$old->seoDescription,$old->secondaryCallToAction,
    $old->faqDisclosure,false
);
ok277(refused277(static fn()=>CopyEdit::begin(
    $approved,$old,$original->artifact,'release-f277-change',$copyOnly,
    'release-f277-unauthorized','imagine_reviewer',3
)), 'copy edits cannot disable editor-owned keyboard option');
$untrusted=$copy;
$untrusted['skipToContentLink']=true;
ok277(refused277(static fn()=>Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$untrusted,
    'tenant-f277','site-f277','workflow-tainted-f277','release-tainted-f277'
)), 'AI copy cannot opt in to keyboard option');
echo "$count F1-277 inert keyboard-skip checks passed; no clients or deploy.\n";
