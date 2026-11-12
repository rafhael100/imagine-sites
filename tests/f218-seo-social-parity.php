<?php

declare(strict_types=1);

/** F1-218: one canonical private export proof for SEO AND OG/Twitter card. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $rel=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$rel)) {
        throw new RuntimeException('Unexpected fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$rel).'.php';
});

use Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture as Fixture;
use Imagine\Sites\Production\SyntheticVisualReviewExportParityV1 as Parity;
use Imagine\Sites\Production\SyntheticPrivateSeoSnippetReadbackV1 as SeoReadback;

$count=0;
function check218(bool $ok,string $why):void {
    global $count;
    if(!$ok)throw new RuntimeException('F1-218 '.$why);
    ++$count;
}
function denied218(callable $cb):bool {
    try{$cb();}catch(DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$variants=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach(['default','forest'] as $palette) {
            foreach([false,true] as $social) {
                $fixture=Fixture::build(
                    $copy,$product,$preset,'faq_process_services',
                    'off',$palette,$social
                );
                $draft=$fixture->draft;
                $files=$fixture->privateFiles();
                $report=Parity::inspect($fixture,$files);
                $seo=SeoReadback::inspect($files,$draft->brief);
                ++$variants;
                check218($report->releaseRef===$seo->releaseRef &&
                    $report->artifactDigest===$seo->artifactDigest &&
                    $report->seoSnippet->artifactDigest===$draft->artifact->digest &&
                    $report->seoSnippet->title===$seo->snippet->title &&
                    $report->seoSnippet->description===$seo->snippet->description,
                    'single canonical export proves both SEO and visual identity');
                check218($report->socialEnabled===$social &&
                    ($report->socialCard!==null)===$social &&
                    $report->seoSnippet->title===$draft->brief->headline.' | '.
                        $draft->brief->brand &&
                    $report->seoSnippet->headline===$draft->brief->headline,
                    'SEO snapshot exists independently from optional social sharing');
                check218(!$report->canPublish() &&
                    !$report->canAcceptForClient() &&
                    !$report->canWriteCrm() &&
                    !$report->canSkipReview() &&
                    !$report->canIndexOnSearchEngines() &&
                    !$report->seoSnippet->canPublish() &&
                    !$report->seoSnippet->canIndexOnSearchEngines(),
                    'combined report cannot grant any operating power');
                check218(str_contains($files['index.html'],
                    '<meta name="robots" content="noindex, nofollow">') &&
                    !str_contains($files['index.html'],'rel="canonical"') &&
                    !str_contains($files['index.html'],'og:url'),
                    'review/export proof cannot introduce external indexing URLs');

                $bad=$files;
                $bad['index.html']=str_replace(
                    'noindex, nofollow','index, follow',$bad['index.html']
                );
                check218(denied218(static fn()=>Parity::inspect(
                    $fixture,$bad
                )),'indexing mutation cannot pass whole-export parity');

                $bad=$files;
                $bad['index.html']=str_replace(
                    '<title>','<title>Injected fake title ',
                    $bad['index.html']
                );
                check218(denied218(static fn()=>Parity::inspect(
                    $fixture,$bad
                )),'title tampering cannot pass full export and SEO parity');

                $bad=$files;
                $manifest=json_decode($bad['release-manifest.json'],
                    true,16,JSON_THROW_ON_ERROR);
                $manifest['releaseRef']='release-f218-forged';
                $bad['release-manifest.json']=json_encode(
                    $manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                )."\n";
                check218(denied218(static fn()=>Parity::inspect(
                    $fixture,$bad
                )),'release identity cannot be substituted after export');

                $bad=$files;
                $summary=json_decode($bad['internal-review-draft.json'],
                    true,16,JSON_THROW_ON_ERROR);
                $summary['officialClientAcceptance']=true;
                $bad['internal-review-draft.json']=json_encode(
                    $summary,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
                    JSON_UNESCAPED_UNICODE
                )."\n";
                check218(denied218(static fn()=>Parity::inspect(
                    $fixture,$bad
                )),'fake client acceptance never appears alongside SEO report');
            }
        }
    }
}
check218($variants===24,
    '24 private variants with SEO every time and social on/off independently');

echo "$count F1-218 unified SEO/social review-export parity assertions passed. ".
    "No real index, publication, client acceptance, CRM or external service.\n";
