<?php

declare(strict_types=1);

/** F1-273: generate two byte-verified fictional native FAQ pages for Chrome. */
if (PHP_SAPI!=='cli' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('SITES_F273_FAQ_CHROME')!=='1') {
    throw new RuntimeException('F1-273 can run only in isolated GitHub Actions.');
}
$root=dirname(__DIR__);
$vendor=$root.'/apps/sites/vendor/autoload.php';
$source=$root.'/fixtures/sites/f1-170-process-copy.synthetic.json';
$temp=getenv('RUNNER_TEMP');
if (!is_string($temp) || $temp==='' || !is_dir($temp) ||
    is_link($temp) || !is_file($vendor) || !is_file($source) ||
    is_link($source) || filesize($source)>8192) {
    throw new RuntimeException('F1-273 fixture path unavailable.');
}
$destination=$temp.'/sites-f273-native-faq.json';
if (file_exists($destination) || is_link($destination)) {
    throw new RuntimeException('F1-273 refuses existing preview file.');
}
require_once $vendor;

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as Qa;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as ReviewFiles;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verify;

$copy=json_decode((string)file_get_contents($source),true,16,JSON_THROW_ON_ERROR);
if (!is_array($copy) || array_is_list($copy) ||
    ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
    count($copy['faq']??[])!==2 ||
    count($copy['highlights']??[])!==2 ||
    count($copy['processSteps']??[])!==3) {
    throw new RuntimeException('F1-273 only supports the pinned fictional v4 copy.');
}
$pages=[];
foreach(['onepage','landing_page'] as $product) {
    $draft=Draft::build(
        $product,'Marca Fictícia Imagine','modern',$copy,
        'tenant-f273-fictional','site-f273-fictional',
        'workflow-f273-'.$product,'release-f273-'.$product,
        officialFaqDisclosure:true
    );
    $files=ReviewFiles::fromPendingDraft($draft);
    $verified=Verify::verifyBytes($files);
    $html=$draft->artifact->html;
    if (!$draft->brief->faqDisclosure ||
        $draft->reviewSummary()['publishable']!==false ||
        $draft->reviewSummary()['officialClientAcceptance']!==false ||
        $draft->workflow->state!=='internal_review' ||
        !Audit::isSafe($html) ||
        !Qa::inspect($draft->artifact)->passedStructuralChecks ||
        ($verified['artifactDigest']??null)!==$draft->artifact->digest ||
        substr_count($html,'<details>')!==2 ||
        substr_count($html,'<summary>')!==2 ||
        str_contains($html,'<script') ||
        str_contains($html,'<form')) {
        throw new RuntimeException('F1-273 refused unsafe or unverified FAQ draft.');
    }
    $pages[$product]=[
        'html'=>$html,
        'sha256'=>hash('sha256',$html),
        'publishable'=>false,
    ];
}
$output=json_encode([
    'schema'=>'sites.synthetic.faq-chrome.v1',
    'syntheticOnly'=>true,
    'pages'=>$pages,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if (!is_string($output) || strlen($output)>330000 ||
    file_put_contents($destination,$output,LOCK_EX)!==strlen($output)) {
    throw new RuntimeException('F1-273 could not create private QA fixture.');
}
chmod($destination,0600);
echo 'PASS F1-273: two byte-pinned, private FAQ previews exported for Chrome QA.'."\n";
