<?php

declare(strict_types=1);

/** F1-179 — real Chromium QA on all six fixed DOM arrangements. */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F177_CHROME_LAB')!=='1') {
    fwrite(STDERR,"F1-179 runs only in isolated GitHub Actions PHP CLI.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $rel=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$rel))
        throw new RuntimeException('Unsafe F1-179 fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$rel).'.php';
});

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;
use Imagine\Sites\Production\SyntheticReleaseBundle;

$source=__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json';
if (!is_file($source) || is_link($source) || filesize($source)>8192)
    throw new RuntimeException('Fixed synthetic fixture unavailable');
$copy=json_decode((string)file_get_contents($source),true,16,JSON_THROW_ON_ERROR);
if (($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
    count($copy['faq']??[])!==2 ||
    count($copy['highlights']??[])!==2 ||
    count($copy['processSteps']??[])!==3) {
    throw new RuntimeException('Unexpected v4 process fixture');
}
$orders=[
    'services_process_faq'=>['highlights','process','faq'],
    'services_faq_process'=>['highlights','faq','process'],
    'process_services_faq'=>['process','highlights','faq'],
    'process_faq_services'=>['process','faq','highlights'],
    'faq_services_process'=>['faq','highlights','process'],
    'faq_process_services'=>['faq','process','highlights'],
];
$directory=sys_get_temp_dir().'/sites-f179-order-gallery';
if(file_exists($directory) || is_link($directory) ||
    !mkdir($directory,0700)) {
    throw new RuntimeException('Private fixed destination is not fresh');
}
$manifest=[
    'schemaVersion'=>'sites.synthetic.anchor-chrome-gallery.v1',
    'syntheticOnly'=>true,
    'publishable'=>false,
    'officialClientAcceptance'=>false,
    'artifacts'=>[],
];
foreach(['onepage'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach($orders as $orderCode=>$order) {
            $draft=Assembly::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f179','site-f179',
                'workflow-f179-'.$product.'-'.$preset.'-'.$orderCode,
                'release-f179-'.$product.'-'.$preset.'-'.$orderCode,
                $order,true
            );
            if ($draft->brief->sectionOrder!==$order ||
                !$draft->brief->sectionNavigation ||
                !str_contains($draft->artifact->html,'<a href="#solucoes">') ||
                !str_contains($draft->artifact->html,'<a href="#etapas">') ||
                !str_contains($draft->artifact->html,'<a href="#perguntas">') ||
                !$draft->structuralReport->passedStructuralChecks ||
                !InertPreviewAudit::isSafe($draft->artifact->html) ||
                !SyntheticReleaseBundle::verifyFiles(
                    $draft->bundle->files,$draft->manifest) ||
                $draft->reviewSummary()['publishable']!==false ||
                $draft->reviewSummary()['officialClientAcceptance']!==false) {
                throw new RuntimeException('Noncanonical section-order fixture');
            }
            $name=$product.'-'.$preset.'-'.$orderCode.'.html';
            $path=$directory.'/'.$name;
            $fp=fopen($path,'x');
            if($fp===false)throw new RuntimeException('Cannot exclusively create HTML');
            try {
                $bytes=$draft->artifact->html;$offset=0;
                while($offset<strlen($bytes)) {
                    $written=fwrite($fp,substr($bytes,$offset));
                    if($written===false||$written===0)
                        throw new RuntimeException('Incomplete private fixture write');
                    $offset+=$written;
                }
                if(!fflush($fp))throw new RuntimeException('Flush failed');
            } finally {fclose($fp);}
            if(!chmod($path,0600))throw new RuntimeException('Private mode required');
            $manifest['artifacts'][]=[
                'filename'=>$name,
                'order'=>$order,
                'sha256'=>$draft->artifact->digest,
                'bytes'=>$draft->artifact->sizeBytes,
                'product'=>$product,
                'anchorNavigation'=>true,
                'preset'=>$preset,
            ];
        }
    }
}
if(count($manifest['artifacts'])!==18)throw new RuntimeException('Missing permutations');
$json=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
$fp=fopen($directory.'/gallery-manifest.json','x');
if($fp===false)throw new RuntimeException('Cannot write private manifest');
try {
    if(fwrite($fp,$json)!==strlen($json)||!fflush($fp))
        throw new RuntimeException('Partial private manifest');
}finally{fclose($fp);}
chmod($directory.'/gallery-manifest.json',0600);
echo "F1-179: 36 review-only content-addressed section orders written privately.\n";
