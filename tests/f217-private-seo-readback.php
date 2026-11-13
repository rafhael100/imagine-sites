<?php

declare(strict_types=1);

/** F1-217: exact text SEO snippet from verified private four-file release. */
spl_autoload_register(static function (string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unexpected synthetic class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture as Fixture;
use Imagine\Sites\Production\SyntheticPrivateSeoSnippetReadbackV1 as Readback;
use Imagine\Sites\Production\SyntheticSeoSnippetInspectionV1 as Snippet;
use Imagine\Sites\Production\SiteBrief;

$count=0;
function check217(bool $condition,string $label):void {
    global $count;
    if(!$condition)throw new RuntimeException('F1-217 '.$label);
    ++$count;
}
function deny217(callable $callback):bool {
    try{$callback();}catch(DomainException){return true;}
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
            foreach(['off','on'] as $social) {
                $fixture=Fixture::build(
                    $copy,$product,$preset,'services_process_faq',
                    'off',$palette,$social==='on'
                );
                $draft=$fixture->draft;
                $files=$fixture->privateFiles();
                $report=Readback::inspect($files,$draft->brief);
                ++$versions;
                check217($report->artifactDigest===$draft->artifact->digest &&
                    $report->releaseRef===$draft->manifest->releaseRef &&
                    $report->snippet instanceof Snippet &&
                    $report->snippet->artifactDigest===$draft->artifact->digest,
                    'read-back report uses exact immutable private HTML identity');
                check217($report->snippet->title===
                    $draft->brief->headline.' | '.$draft->brief->brand &&
                    $report->snippet->description===$draft->brief->description &&
                    $report->snippet->headline===$draft->brief->headline,
                    'SEO title, description and H1 match approved synthetic content');
                check217(!$report->canIndex() &&
                    !$report->canPublish() &&
                    !$report->canSendToSeoEngine() &&
                    !$report->canSharePublicly() &&
                    !$report->canApproveForClient(),
                    'four-file SEO report never grants indexing or operations');
                check217($files['index.html']===$draft->artifact->html &&
                    str_contains($files['index.html'],'noindex, nofollow') &&
                    !str_contains($files['index.html'],'rel="canonical"') &&
                    !str_contains($files['index.html'],'og:image') &&
                    !str_contains($files['index.html'],'og:url'),
                    'stored bytes remain offline noindex with no remote social media');

                $bad=$files;
                $bad['index.html']=str_replace('noindex, nofollow','index, follow',
                    $files['index.html']);
                check217(deny217(static fn()=>Readback::inspect(
                    $bad,$draft->brief
                )),'robots change invalidates canonical private export');

                $bad=$files;
                $bad['index.html'].='<!-- editorial alteration -->';
                check217(deny217(static fn()=>Readback::inspect(
                    $bad,$draft->brief
                )),'appending HTML after export invalidates SHA-256');

                $bad=$files;
                $summary=json_decode($bad['internal-review-draft.json'],
                    true,16,JSON_THROW_ON_ERROR);
                $summary['publishable']=true;
                $bad['internal-review-draft.json']=json_encode(
                    $summary,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
                    JSON_UNESCAPED_UNICODE
                )."\n";
                check217(deny217(static fn()=>Readback::inspect(
                    $bad,$draft->brief
                )),'noindex advisory cannot coexist with published=true');

                $bad=$files;
                $dossier=json_decode($bad['internal-review-dossier.json'],
                    true,16,JSON_THROW_ON_ERROR);
                $dossier['reviewerDecisionStatus']='approved';
                $bad['internal-review-dossier.json']=json_encode(
                    $dossier,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                )."\n";
                check217(deny217(static fn()=>Readback::inspect(
                    $bad,$draft->brief
                )),'forged reviewer decision never results in accepted SEO snippet');

                $other=new SiteBrief(
                    $draft->brief->productCode,
                    $draft->brief->brand,
                    'An unrelated synthetic headline',
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
                    $draft->brief->socialPreviewMetadata
                );
                check217(deny217(static fn()=>Readback::inspect(
                    $files,$other
                )),'SEO readback cannot be reused for a different content snapshot');
            }
        }
    }
}
check217($versions===24,'24 fixed product/style/palette/social exports inspected');

echo "$count F1-217 canonical SEO private-file readback assertions passed. ".
    "No indexing, public URL, external SEO provider, CRM, client or deploy.\n";
