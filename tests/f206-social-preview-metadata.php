<?php

declare(strict_types=1);

/** F1-206: text-only social card metadata, no URL/image/assets or publish. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unsafe lab autoload');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\StaticSiteRenderer as Renderer;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as Content;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as Style;
use Imagine\Sites\Production\SyntheticSectionOrderRevisionPlan as Order;
use Imagine\Sites\Production\SyntheticCompositeVisualRevisionPlan as Visual;
use Imagine\Sites\Production\SyntheticEditorialPatchV1 as Patch;

$count=0;
function check206(bool $yes,string $why):void {
    global $count;
    if(!$yes)throw new RuntimeException('F1-206 '.$why);
    ++$count;
}
function denied206(callable $action):bool {
    try {$action();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$orders=Catalog::sectionOrders();
$examples=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $theme) {
        foreach(['services_process_faq','faq_process_services'] as $orderCode) {
            foreach(['default','forest'] as $paletteCode) {
                $selection=Catalog::choose(
                    $product,$theme,$orderCode,'off',$paletteCode
                );
                $args=[
                    $product,'Marca Fictícia Imagine',$theme,$copy,
                    'tenant-f206','site-f206',
                    'workflow-f206-'.$product.'-'.$theme.'-'.$orderCode.'-'.$paletteCode,
                    'release-f206-'.$product.'-'.$theme.'-'.$orderCode.'-'.$paletteCode,
                    $orders[$orderCode],false,$selection['palette'],
                ];
                $off=Draft::build(...[...$args,false]);
                $on=Draft::build(...[...$args,true]);
                ++$examples;
                check206($off->brief->socialPreviewMetadata===false &&
                    $on->brief->socialPreviewMetadata===true &&
                    !hash_equals($off->artifact->digest,$on->artifact->digest),
                    'social preview opt-in always has new content identity');
                check206(Audit::isSafe($off->artifact->html) &&
                    Audit::isSafe($on->artifact->html) &&
                    QA::inspect($on->artifact)->passedStructuralChecks &&
                    $on->reviewSummary()['publishable']===false &&
                    $on->reviewSummary()['officialClientAcceptance']===false,
                    'social preview remains inert, noindex and nonpublishable');
                $html=$on->artifact->html;
                check206(substr_count($off->artifact->html,'<meta ')===5 &&
                    substr_count($html,'<meta ')===12 &&
                    substr_count($html,'<meta property="og:type" content="website">')===1 &&
                    substr_count($html,'<meta property="og:locale" content="pt_BR">')===1 &&
                    substr_count($html,'<meta name="twitter:card" content="summary">')===1,
                    'one strict seven-tag social card with old five base tags');
                check206(str_contains($html,'<meta property="og:title" content="') &&
                    str_contains($html,'<meta property="og:description" content="') &&
                    str_contains($html,'<meta name="twitter:title" content="') &&
                    str_contains($html,'<meta name="twitter:description" content="') &&
                    !str_contains($html,'og:url') &&
                    !str_contains($html,'og:image') &&
                    !str_contains($html,'twitter:image') &&
                    !str_contains($html,'<link') &&
                    !str_contains($html,'<script') &&
                    !str_contains($html,'<form'),
                    'no remote image, canonical URL, scripts or lead capture');
                check206($html===Draft::build(...[...$args,true])->artifact->html &&
                    $on->artifact->digest===hash('sha256',$html),
                    'social meta output is byte-for-byte deterministic');
            }
        }
    }
}
check206($examples===24,'all product, theme, order and palette samples tested');

$fixture=Catalog::choose('onepage','classic','services_process_faq','off','default');
$source=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,
    'tenant-f206','site-f206','workflow-f206-revision','release-f206-original',
    $fixture['order'],false,null,true
);
$approved=$source->workflow->advance(
    'imagine_reviewer','approve_internal',2,$source->artifact->digest
);
$new=Visual::begin(
    $approved,$source->brief,$source->artifact,'release-f206-original',
    'modern',Catalog::palettes()['plum'],
    $orders['faq_process_services'],true,'release-f206-revised',
    'imagine_reviewer',3
);
check206($new->nextBrief->socialPreviewMetadata===true &&
    $new->draftWorkflow->artifactDigest===null &&
    Audit::isSafe($new->nextArtifact->html),
    'theme/palette/order/nav revision preserves social metadata and resets approval');
$style=Style::begin(
    $approved,$source->brief,$source->artifact,'release-f206-original',
    'warm','release-f206-style','imagine_reviewer',3
);
check206($style->nextBrief->socialPreviewMetadata===true,
    'style-only revision preserves social card');
$order=Order::begin(
    $approved,$source->brief,$source->artifact,'release-f206-original',
    $orders['faq_process_services'],'release-f206-order','imagine_reviewer',3
);
check206($order->nextBrief->socialPreviewMetadata===true,
    'order-only revision preserves social card');
$patch=Patch::propose(
    $approved,$source->brief,$source->artifact,'release-f206-original',
    'release-f206-copy','imagine_reviewer',[
        'schemaVersion'=>'sites.editorial.patch.v1',
        'expectedDigest'=>$source->artifact->digest,
        'expectedRevision'=>3,
        'changes'=>['headline'=>'Outro título estritamente fictício'],
    ]
);
check206($patch->nextBrief->socialPreviewMetadata===true,
    'editing headline retains social meta flag and regenerates content');

$sourceBrief=$source->brief;
$forged=new SiteBrief(
    $sourceBrief->productCode,$sourceBrief->brand,$sourceBrief->headline,
    $sourceBrief->description,$sourceBrief->callToAction,
    $sourceBrief->benefits,$sourceBrief->stylePreset,$sourceBrief->faq,
    $sourceBrief->highlights,$sourceBrief->processSteps,
    $sourceBrief->sectionOrder,$sourceBrief->sectionNavigation,
    $sourceBrief->brandPalette,false
);
check206(denied206(static fn()=>Content::begin(
    $approved,$sourceBrief,$source->artifact,'release-f206-original',
    $forged,'release-f206-content-forgery','imagine_reviewer',3
)), 'copy-only revision cannot disable or forge social preview option');

$html=$source->artifact->html;
foreach([
    'duplicate-robots'=>str_replace(
        '<title>', '<meta name="robots" content="index, follow">'."\n".'<title>',$html
    ),
    'external-og-image'=>str_replace(
        '<title>', '<meta property="og:image" content="https://invalid.test/x.jpg">'."\n".'<title>',$html
    ),
    'altered-social-title'=>str_replace(
        '<meta name="twitter:title" content="',
        '<meta name="twitter:title" content="FORGED-',$html
    ),
    'altered-description'=>str_replace(
        '<meta property="og:description" content="',
        '<meta property="og:description" content="FORGED-',$html
    ),
    'unknown-refresh'=>str_replace(
        '<title>','<meta http-equiv="refresh" content="0">'."\n".'<title>',$html
    ),
] as $case=>$tampered) {
    check206($tampered!==$html && !Audit::isSafe($tampered),
        'fail closed for unauthorized social/robots metadata: '.$case);
}
$unsafe=new SiteBrief(
    'onepage','Marca fictícia',
    'Título com "aspas" & símbolo',
    'Descrição com "aspas" & sinal de teste',
    'Saiba mais',['Benefício'],'classic',[],[],[],
    ['highlights','process','faq'],false,null,true
);
$escaped=(new Renderer())->render($unsafe)->html;
check206(Audit::isSafe($escaped) &&
    str_contains($escaped,'&quot;aspas&quot; &amp;') &&
    !str_contains($escaped,'content="Título com "aspas"'),
    'headline and descriptions are HTML-attribute escaped before social cards');

echo "$count F1-206 optional social card metadata checks passed for ".
    "$examples static combinations; all review/CRM/publication grants denied.\n";
