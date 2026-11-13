<?php

declare(strict_types=1);

/**
 * F1-220: separately reviewer-controlled SEO title/description.
 * Never URL, remote crawler, AI permission, published site or real client.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unsafe synthetic class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticSeoSnippetInspectionV1 as Seo;
use Imagine\Sites\Production\SyntheticSocialCardInspectionV1 as Social;
use Imagine\Sites\Production\SyntheticPrivateSeoSnippetReadbackV1 as Readback;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as Package;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as Style;
use Imagine\Sites\Production\SyntheticSectionOrderRevisionPlan as Reorder;
use Imagine\Sites\Production\SyntheticSocialPreviewRevisionPlan as SocialRevision;
use Imagine\Sites\Production\SyntheticCompositeVisualRevisionPlan as Composite;
use Imagine\Sites\Production\SyntheticEditorialPatchV1 as Patch;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as Content;
use Imagine\Sites\Production\StaticSiteRenderer as Renderer;

$checks=0;
function check220(bool $okay,string $label):void {
    global $checks;
    if(!$okay)throw new RuntimeException('F1-220 '.$label);
    ++$checks;
}
function denied220(callable $cb):bool {
    try{$cb();}catch(InvalidArgumentException|DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$seoTitle='Soluções digitais para negócios | Imagine Demonstração';
$seoDescription='Uma descrição editorial diferente do texto principal da página, preparada apenas para revisar a identidade de uma prévia estática.';
$cases=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach([false,true] as $social) {
            $args=[
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f220','site-f220',
                'workflow-f220-'.$product.'-'.$preset.'-'.(int)$social,
                'release-f220-'.$product.'-'.$preset.'-'.(int)$social,
                ['highlights','process','faq'],false,null,$social,
            ];
            $baseline=Draft::build(...$args);
            $explicitDefault=Draft::build(...[...$args,null,null]);
            $custom=Draft::build(...[...$args,$seoTitle,$seoDescription]);
            ++$cases;
            check220($baseline->artifact->html===$explicitDefault->artifact->html &&
                $baseline->artifact->digest===$explicitDefault->artifact->digest,
                'null optional SEO copy remains exactly backward-compatible');
            check220($custom->artifact->digest!==$baseline->artifact->digest &&
                hash('sha256',$custom->artifact->html)===$custom->artifact->digest &&
                $custom->brief->headline===$baseline->brief->headline &&
                $custom->brief->description===$baseline->brief->description,
                'SEO copy edits only meta head bytes without changing hero/H1/intro');
            $report=Seo::inspect($custom->brief,$custom->artifact);
            check220($report->title===$seoTitle &&
                $report->description===$seoDescription &&
                $report->headline===$copy['headline'] &&
                $report->artifactDigest===$custom->artifact->digest &&
                !$report->canIndexOnSearchEngines() &&
                !$report->canPublish(),
                'SEO report binds exact separately reviewed metadata to same noindex HTML');
            check220(str_contains($custom->artifact->html,
                '<meta name="description" content="'.$seoDescription.'">') &&
                str_contains($custom->artifact->html,
                    '<h1 id="hero-title">'.$copy['headline'].'</h1>') &&
                !str_contains($custom->artifact->html,'rel="canonical"') &&
                !str_contains($custom->artifact->html,'og:url'),
                'on-page hero text stays independent and no new URLs appear');

            if($social) {
                $card=Social::inspect($custom->brief,$custom->artifact);
                check220($card->title===$seoTitle &&
                    $card->description===$seoDescription &&
                    $card->artifactDigest===$custom->artifact->digest,
                    'optional OG/Twitter text uses exact same SEO editorial overrides');
            }
            $files=Package::fromPendingDraft($custom);
            $readback=Readback::inspect($files,$custom->brief);
            check220($readback->snippet->title===$seoTitle &&
                $readback->snippet->description===$seoDescription &&
                $readback->artifactDigest===$custom->artifact->digest &&
                !$readback->canIndex(),
                'SEO metadata overrides survive canonical four-file private export');

            $approved=$custom->workflow->advance(
                'imagine_reviewer','approve_internal',2,$custom->artifact->digest
            );
            $style=Style::begin(
                $approved,$custom->brief,$custom->artifact,
                'release-f220-approved',
                $preset==='classic'?'modern':'classic',
                'release-f220-new-theme','imagine_reviewer',3
            );
            check220($style->nextBrief->seoTitle===$seoTitle &&
                $style->nextBrief->seoDescription===$seoDescription &&
                $style->draftWorkflow->artifactDigest===null,
                'changing theme preserves explicitly controlled SEO copy');
            $order=Reorder::begin(
                $approved,$custom->brief,$custom->artifact,
                'release-f220-approved',['faq','process','highlights'],
                'release-f220-new-order','imagine_reviewer',3
            );
            check220($order->nextBrief->seoTitle===$seoTitle &&
                $order->nextBrief->seoDescription===$seoDescription &&
                $order->draftWorkflow->artifactDigest===null,
                'changing order cannot drop or modify independently controlled SEO copy');
            $patch=Patch::propose(
                $approved,$custom->brief,$custom->artifact,
                'release-f220-approved','release-f220-new-copy',
                'imagine_reviewer',[
                    'schemaVersion'=>'sites.editorial.patch.v1',
                    'expectedDigest'=>$custom->artifact->digest,
                    'expectedRevision'=>3,
                    'changes'=>['headline'=>'Novo H1 sem mudança dos metadados SEO'],
                ]
            );
            check220($patch->nextBrief->seoTitle===$seoTitle &&
                $patch->nextBrief->seoDescription===$seoDescription &&
                $patch->draftWorkflow->artifactDigest===null,
                'normal text patch preserves independent SEO fields');

            if($social) {
                $off=SocialRevision::begin(
                    $approved,$custom->brief,$custom->artifact,
                    'release-f220-approved',false,
                    'release-f220-social-off','imagine_reviewer',3
                );
                check220($off->nextBrief->seoTitle===$seoTitle &&
                    $off->nextBrief->seoDescription===$seoDescription &&
                    $off->nextBrief->socialPreviewMetadata===false,
                    'social toggle preserves SEO while removing optional OG/Twitter');
            }
        }
    }
}
check220($cases===12,'12 canonical product/theme/social combinations tested');

$original=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,
    'tenant-f220','site-f220','workflow-f220-composite',
    'release-f220-composite',['highlights','process','faq'],false,null,true,
    $seoTitle,$seoDescription
);
$reviewed=$original->workflow->advance(
    'imagine_reviewer','approve_internal',2,$original->artifact->digest
);
$composite=Composite::begin(
    $reviewed,$original->brief,$original->artifact,
    'release-f220-composite','modern',null,
    ['faq','process','highlights'],false,
    'release-f220-composite-next','imagine_reviewer',3,false
);
check220($composite->nextBrief->seoTitle===$seoTitle &&
    $composite->nextBrief->seoDescription===$seoDescription &&
    $composite->draftWorkflow->artifactDigest===null,
    'multi-dimensional visual change retains SEO overrides with new review');

$changedSeo=new SiteBrief(
    $original->brief->productCode,$original->brief->brand,
    $original->brief->headline,$original->brief->description,
    $original->brief->callToAction,$original->brief->benefits,
    $original->brief->stylePreset,$original->brief->faq,
    $original->brief->highlights,$original->brief->processSteps,
    $original->brief->sectionOrder,$original->brief->sectionNavigation,
    $original->brief->brandPalette,$original->brief->socialPreviewMetadata,
    'Different SEO headline',$seoDescription
);
check220(denied220(static fn()=>Content::begin(
    $reviewed,$original->brief,$original->artifact,
    'release-f220-composite',$changedSeo,'release-f220-hidden-seo',
    'imagine_reviewer',3
)), 'ordinary content revision cannot silently change SEO approval scope');

$invalid=[
    ['https://phishing.invalid',null],
    ['<script>alert(1)</script>',null],
    ['Título com espaço final ',null],
    ["Título\ncom quebra",null],
    [str_repeat('X',161),null],
    [null,'https://example.invalid/description'],
    [null,'<img src=x>'],
    [null,str_repeat('Z',361)],
    [null,"Descrição\rinterrompida"],
];
foreach($invalid as [$title,$description]) {
    check220(denied220(static fn()=>Draft::build(
        'onepage','Marca Fictícia Imagine','classic',$copy,
        'tenant-f220','site-f220','workflow-f220-invalid','release-f220-invalid',
        ['highlights','process','faq'],false,null,false,$title,$description
    )),'invalid SEO copy rejected before producing any reviewable release');
}
$forged=$copy;
$forged['seoTitle']='Texto SEO diretamente controlado por IA';
check220(denied220(static fn()=>Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$forged,
    'tenant-f220','site-f220','workflow-f220-forged','release-f220-forged'
)), 'AI copy contract v4 cannot set independently controlled SEO meta title');

echo "$checks F1-220 independent SEO copy and preservation assertions passed. ".
    "No real indexing, Google, client, CRM or public deployment.\n";
