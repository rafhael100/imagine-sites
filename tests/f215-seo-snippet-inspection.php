<?php

declare(strict_types=1);

/**
 * F1-215: exact, read-only SEO title/description/H1 inspection.
 * Every page is fictional, PRIVATE, noindex and unavailable for publication.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unexpected lab class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticSeoSnippetInspectionV1 as Seo;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\StaticSiteRenderer as Renderer;

$count=0;
function check215(bool $condition,string $label):void {
    global $count;
    if(!$condition)throw new RuntimeException('F1-215 '.$label);
    ++$count;
}
function rejects215(callable $cb):bool {
    try{$cb();}catch(DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$versions=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach(['default','forest'] as $palette) {
            foreach([false,true] as $social) {
                $choice=Catalog::choose(
                    $product,$preset,'services_process_faq','off',$palette
                );
                $draft=Draft::build(
                    $product,'Marca Fictícia Imagine',$preset,$copy,
                    'tenant-f215','site-f215',
                    'workflow-f215-'.$product.'-'.$preset.'-'.$palette.'-'.(int)$social,
                    'release-f215-'.$product.'-'.$preset.'-'.$palette.'-'.(int)$social,
                    $choice['order'],false,$choice['palette'],$social
                );
                ++$versions;
                $report=Seo::inspect($draft->brief,$draft->artifact);
                $expected=$draft->brief->headline.' | '.$draft->brief->brand;
                check215($report->title===$expected &&
                    $report->headline===$draft->brief->headline &&
                    $report->description===$draft->brief->description &&
                    $report->artifactDigest===$draft->artifact->digest,
                    'SEO fields pinned to canonical reviewed HTML');
                check215($report->titleCharacters===mb_strlen($expected,'UTF-8') &&
                    $report->descriptionCharacters===
                        mb_strlen($draft->brief->description,'UTF-8'),
                    'character counts are UTF-8 codepoints, not bytes');
                check215($report->titlePreview===(mb_strlen($expected,'UTF-8')<=60
                    ? $expected : rtrim(mb_substr($expected,0,59,'UTF-8')).'…') &&
                    mb_strlen($report->descriptionPreview,'UTF-8')<=160 &&
                    mb_check_encoding($report->descriptionPreview,'UTF-8'),
                    'snippet previews preserve UTF-8 and have bounded lengths');
                check215(!$report->canPublish() &&
                    !$report->canIndexOnSearchEngines() &&
                    !$report->canWriteSeoEngine() &&
                    !$report->canFetchExternalPages() &&
                    !$report->canApproveForClient(),
                    'snippet reports never grant operational authority');
                check215(str_contains($draft->artifact->html,
                    '<meta name="robots" content="noindex, nofollow">') &&
                    $draft->reviewSummary()['publishable']===false &&
                    $draft->reviewSummary()['officialClientAcceptance']===false,
                    'inspection never overrides the pending noindex preview policy');

                $tampered=new ReleaseArtifact(
                    str_replace('noindex, nofollow','index, follow',
                        $draft->artifact->html)
                );
                check215(rejects215(static fn()=>Seo::inspect(
                    $draft->brief,$tampered
                )),'indexed impostor does not pass canonical HTML verification');

                $tampered=new ReleaseArtifact(str_replace(
                    '<meta name="description"',
                    '<meta name="description" content="wrong" /><meta name="description"',
                    $draft->artifact->html
                ));
                check215(rejects215(static fn()=>Seo::inspect(
                    $draft->brief,$tampered
                )),'duplicate description cannot hide inside a pinned page');

                $tampered=new ReleaseArtifact(
                    str_replace('</head>',
                        '<link rel="canonical" href="https://evil.invalid"></head>',
                        $draft->artifact->html)
                );
                check215(rejects215(static fn()=>Seo::inspect(
                    $draft->brief,$tampered
                )),'arbitrary canonical URL cannot be accepted by lab inspector');

                $wrong=new SiteBrief(
                    $draft->brief->productCode,
                    $draft->brief->brand,
                    'Another strictly fictional page title',
                    $draft->brief->description,
                    $draft->brief->callToAction,
                    $draft->brief->benefits,
                    $draft->brief->stylePreset,
                    $draft->brief->faq,
                    $draft->brief->highlights,
                    $draft->brief->processSteps,
                    $draft->brief->sectionOrder,
                    $draft->brief->sectionNavigation,
                    $draft->brief->brandPalette,
                    $social
                );
                check215(rejects215(static fn()=>Seo::inspect(
                    $wrong,$draft->artifact
                )),'headline mismatch cannot inspect someone else\'s page');

                if($social) {
                    check215(str_contains($draft->artifact->html,'og:title') &&
                        !str_contains($draft->artifact->html,'og:url') &&
                        !str_contains($draft->artifact->html,'og:image'),
                        'OG cards cannot introduce canonical URL or remote image');
                }
            }
        }
    }
}
check215($versions===24,'all 24 product/theme/palette/social combinations verified');

$renderer=new Renderer();
$short=new SiteBrief(
    'onepage','Marca','Oi','Curta.',
    'Conhecer',['Um benefício']);
$shortReport=Seo::inspect($short,$renderer->render($short));
check215($shortReport->advisories===['TITLE_SHORT','DESCRIPTION_SHORT'] &&
    $shortReport->titlePreview==='Oi | Marca' &&
    $shortReport->descriptionPreview==='Curta.',
    'short copy produces truthful editorial advisories, not search rankings');

$long=new SiteBrief(
    'onepage','Marca Fictícia',
    str_repeat('Título demonstrativo grande ',5),
    str_repeat('Descrição de uma página fictícia. ',9),
    'Conhecer',['Um benefício']
);
$longReport=Seo::inspect($long,$renderer->render($long));
check215($longReport->advisories===
    ['TITLE_MAY_TRUNCATE','DESCRIPTION_MAY_TRUNCATE'] &&
    mb_strlen($longReport->titlePreview,'UTF-8')<=60 &&
    mb_strlen($longReport->descriptionPreview,'UTF-8')<=160 &&
    str_ends_with($longReport->titlePreview,'…') &&
    str_ends_with($longReport->descriptionPreview,'…'),
    'long Unicode-friendly metadata is flagged and preview-truncated only');

$accented=new SiteBrief(
    'landing_page','Marca Fictícia',
    str_repeat('Gestão ágil ',6),
    str_repeat('Serviços úteis e responsáveis para negócios locais. ',4),
    'Saiba mais',['Informação de demonstração']);
$accentedReport=Seo::inspect($accented,$renderer->render($accented));
check215($accentedReport->titleCharacters===
    mb_strlen($accentedReport->title,'UTF-8') &&
    mb_check_encoding($accentedReport->titlePreview,'UTF-8'),
    'accented Portuguese titles retain exact UTF-8 preview and length');

echo "$count F1-215 noindex SEO snippet inspections passed; ".
    "no website indexing, live SEO engine, client data, network or deploy.\n";
