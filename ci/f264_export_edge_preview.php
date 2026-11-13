<?php

declare(strict_types=1);

/**
 * F1-264: generate exactly one real renderer output for edge CI tests.
 * No customers, remote calls, public URLs, uploads or deploy rights.
 */
if (PHP_SAPI!=='cli' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F264_EDGE_PREVIEW')!=='1') {
    throw new RuntimeException('F1-264 requires disposable CI-only scope.');
}
$root=dirname(__DIR__);
$temp=getenv('RUNNER_TEMP');
if (!is_string($temp) || $temp==='' || !is_dir($temp) || is_link($temp)) {
    throw new RuntimeException('F1-264 runner temp unavailable.');
}
$out=$temp.'/sites-f264-preview-fixture.json';
$vendor=$root.'/apps/sites/vendor/autoload.php';
$copyFile=$root.'/fixtures/sites/f1-170-process-copy.synthetic.json';
if (file_exists($out) || is_link($out) ||
    !is_file($copyFile) || is_link($copyFile) || !is_file($vendor)) {
    throw new RuntimeException('F1-264 unexpected source or output file.');
}
require_once $vendor;

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as Qa;

$copy=json_decode(
    (string)file_get_contents($copyFile),true,16,JSON_THROW_ON_ERROR
);
$draft=Draft::build(
    'onepage','Marca Fictícia Imagine','modern',$copy,
    'tenant-f264-fictional','site-f264-fictional',
    'workflow-f264-fictional','release-f264-fictional',
    ['highlights','process','faq'],true,null,false,
    null,null,true
);
$artifact=$draft->artifact;
$summary=$draft->reviewSummary();
if ($summary['workflowState']!=='internal_review' ||
    $summary['internalReviewRequired']!==true ||
    $summary['portalAuthorityRequired']!==true ||
    $summary['officialClientAcceptance']!==false ||
    $summary['publishable']!==false ||
    !$draft->structuralReport->passedStructuralChecks ||
    !Audit::isSafe($artifact->html) ||
    !Qa::inspect($artifact)->passedStructuralChecks ||
    !hash_equals(hash('sha256',$artifact->html),$artifact->digest)) {
    throw new RuntimeException('F1-264 requires a real, pending, inert draft.');
}
$fixture=[
    'synthetic'=>true,
    'reviewState'=>'internal_review',
    'publishable'=>false,
    'clientAccepted'=>false,
    'html'=>$artifact->html,
    'digest'=>$artifact->digest,
];
$json=json_encode(
    $fixture,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
);
if (!is_string($json) || strlen($json)>180000 ||
    file_put_contents($out,$json,LOCK_EX)!==strlen($json)) {
    throw new RuntimeException('F1-264 private fixture creation failed.');
}
chmod($out,0600);
echo "PASS F1-264: real PHP renderer output prepared privately for Node edge tests.\n";
