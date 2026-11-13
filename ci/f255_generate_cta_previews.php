<?php

declare(strict_types=1);

/**
 * F1-255: generate 12 privately reviewed, strictly synthetic CTA variants.
 * Never a customer export or a deployable release.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('SITES_F255_CTA_SCREENSHOT')!=='1') {
    throw new RuntimeException('F1-255 requires explicit disposable CI mode.');
}
$repo=dirname(__DIR__);
$vendor=$repo.'/apps/sites/vendor/autoload.php';
$copyFile=$repo.'/fixtures/sites/f1-170-process-copy.synthetic.json';
$private=$repo.'/apps/sites/storage/app/private';
$dir=$private.'/f255-cta-previews';
if (!is_file($vendor) || !is_file($copyFile) ||
    is_link($vendor) || is_link($copyFile) ||
    is_link($private) || is_link($dir) || file_exists($dir)) {
    throw new RuntimeException('F1-255 invalid private fixture/output boundary.');
}
require_once $vendor;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\InertPreviewAudit as Audit;

$copy=json_decode((string)file_get_contents($copyFile),
    true,16,JSON_THROW_ON_ERROR);
if (!is_array($copy) || array_keys($copy)!==[
    'schemaVersion','headline','description','callToAction','benefits',
    'faq','highlights','processSteps'
] || $copy['schemaVersion']!=='sites.ai-copy.v4' ||
    count($copy['faq'])!==2 || count($copy['highlights'])!==2 ||
    count($copy['processSteps'])!==3) {
    throw new RuntimeException('F1-255 only accepts the pinned fictional v4 copy.');
}
if (!is_dir($private) && !mkdir($private,0700,true) && !is_dir($private)) {
    throw new RuntimeException('F1-255 private storage root unavailable.');
}
if (!mkdir($dir,0700)) {
    throw new RuntimeException('F1-255 cannot initialize private output.');
}
function save255(string $dir,string $name,string $bytes):string {
    if (!preg_match('/\A[a-z0-9][a-z0-9._-]{0,90}\z/D',$name) ||
        $bytes==='' || strlen($bytes)>150000 ||
        is_link($dir.'/'.$name) || file_exists($dir.'/'.$name) ||
        file_put_contents($dir.'/'.$name,$bytes,LOCK_EX)!==strlen($bytes)) {
        throw new RuntimeException('F1-255 unsafe private output file.');
    }
    chmod($dir.'/'.$name,0600);
    return hash('sha256',$bytes);
}

$files=[];
foreach (['onepage','landing_page'] as $product) {
    foreach (['classic','modern','warm'] as $style) {
        foreach (['off','on'] as $mode) {
            $name=$product.'-'.$style.'-'.$mode.'.html';
            $draft=Draft::build(
                $product,'Aurora Exemplo',$style,$copy,
                'tenant-f255-fixture','site-f255-fixture',
                'workflow-f255-'.$product.'-'.$style.'-'.$mode,
                'release-f255-'.$product.'-'.$style.'-'.$mode,
                ['highlights','process','faq'],$product==='onepage',
                null,true,$copy['headline'].' | Aurora Exemplo',
                $copy['description'],$mode==='on'
            );
            $html=$draft->artifact->html;
            $summary=$draft->reviewSummary();
            if ($draft->brief->secondaryCallToAction!==($mode==='on') ||
                !$draft->structuralReport->passedStructuralChecks ||
                !Audit::isSafe($html) ||
                $summary['workflowState']!=='internal_review' ||
                $summary['internalReviewRequired']!==true ||
                $summary['portalAuthorityRequired']!==true ||
                $summary['publishable']!==false ||
                $summary['officialClientAcceptance']!==false ||
                substr_count($html,'class="btn secondary-cta"')!==($mode==='on'?1:0) ||
                substr_count($html,'href="#contato"')!==($mode==='on'?2:1) ||
                stripos($html,'<form')!==false ||
                stripos($html,'<script')!==false ||
                stripos($html,'https://')!==false ||
                stripos($html,'http://')!==false) {
                throw new RuntimeException('F1-255 unsafe CTA draft: '.$name);
            }
            $sha=save255($dir,$name,$html);
            if (!hash_equals($sha,$draft->artifact->digest)) {
                throw new RuntimeException('F1-255 on-disk bytes changed.');
            }
            $files[$name]=[
                'product'=>$product,'preset'=>$style,'mode'=>$mode,
                'bytes'=>strlen($html),'sha256'=>$sha,
                'publishable'=>false,'officialClientAcceptance'=>false,
                'draftState'=>'internal_review',
            ];
        }
    }
}
if (count($files)!==12 ||
    count(array_unique(array_column($files,'sha256')))!==12) {
    throw new RuntimeException('F1-255 requires 12 distinct preview bytes.');
}
$manifest=json_encode([
    'schema'=>'imagine-sites.synthetic-cta-gallery.v1',
    'source'=>'fixtures/sites/f1-170-process-copy.synthetic.json',
    'demoOnly'=>true,'approvedForClient'=>false,
    'publishable'=>false,'hasContactCapture'=>false,
    'files'=>$files,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
save255($dir,'manifest.json',$manifest);
$expected=array_keys($files);$expected[]='manifest.json';sort($expected);
$actual=array_values(array_diff(scandir($dir)?:[],['.','..']));sort($actual);
if ($expected!==$actual) {
    throw new RuntimeException('F1-255 private output inventory differs.');
}
echo "PASS F1-255: 12 distinct inert CTA on/off pages, exact SHA-256, pending review.\n";
