<?php

declare(strict_types=1);

/** F1-192: strict private, fictional brand palette Chrome fixtures. */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F192_CHROME_LAB')!=='1') {
    fwrite(STDERR,"F1-192 only runs inside disposable GitHub Actions.\n");
    exit(1);
}

spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name))
        throw new RuntimeException('Unexpected fixture classname');
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SyntheticBrandPaletteV1 as Palette;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\InertPreviewAudit as Inert;
use Imagine\Sites\Production\SyntheticReleaseBundle as Bundle;

$fixture=__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json';
if (!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192)
    throw new RuntimeException('Fixed synthetic v4 fixture denied');
$copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
if(($copy['schemaVersion']??null)!=='sites.ai-copy.v4') {
    throw new RuntimeException('Unexpected synthetic structured-copy contract');
}

$palettes=[
    'ocean'=>Palette::fromHex('#064e5b','#075a6b','#ffffff','#075a6b'),
    'plum'=>Palette::fromHex('#50204d','#673060','#ffe9fa','#50204d'),
    'forest'=>Palette::fromHex('#134a36','#1d5a43','#f4fff5','#134a36'),
];
$root=sys_get_temp_dir().'/sites-f192-palette-gallery';
if (file_exists($root) || is_link($root) || !mkdir($root,0700))
    throw new RuntimeException('Private fixture destination must be fresh');
$manifest=[
    'schemaVersion'=>'sites.synthetic.brand-palette-chrome.v1',
    'syntheticOnly'=>true,
    'publishable'=>false,
    'officialClientAcceptance'=>false,
    'artifacts'=>[],
];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach($palettes as $code=>$palette) {
            $draft=Draft::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f192','site-f192',
                'workflow-f192-'.$product.'-'.$preset.'-'.$code,
                'release-f192-'.$product.'-'.$preset.'-'.$code,
                ['highlights','process','faq'],false,$palette
            );
            if ($draft->brief->brandPalette!==$palette ||
                !$draft->structuralReport->passedStructuralChecks ||
                !Inert::isSafe($draft->artifact->html) ||
                !Bundle::verifyFiles($draft->bundle->files,$draft->manifest) ||
                $draft->reviewSummary()['publishable']!==false ||
                $draft->reviewSummary()['officialClientAcceptance']!==false) {
                throw new RuntimeException('Unsafe unreviewed brand browser fixture');
            }
            $filename=$product.'-'.$preset.'-'.$code.'.html';
            $path=$root.'/'.$filename;
            $stream=fopen($path,'x');
            if($stream===false)throw new RuntimeException('Fixture file is not exclusive');
            try {
                $offset=0;
                $bytes=$draft->artifact->html;
                while($offset<strlen($bytes)) {
                    $n=fwrite($stream,substr($bytes,$offset));
                    if($n===false || $n===0)throw new RuntimeException('Short HTML write');
                    $offset+=$n;
                }
                if(!fflush($stream))throw new RuntimeException('Flush failed');
            }finally{fclose($stream);}
            if(!chmod($path,0600))throw new RuntimeException('Private file mode denied');
            $manifest['artifacts'][]=[
                'filename'=>$filename,'product'=>$product,
                'preset'=>$preset,'palette'=>$code,
                'sha256'=>$draft->artifact->digest,
                'bytes'=>$draft->artifact->sizeBytes,
                'heroStart'=>$palette->heroStart,
                'heroEnd'=>$palette->heroEnd,
                'buttonBackground'=>$palette->buttonBackground,
                'buttonText'=>$palette->buttonText,
            ];
        }
    }
}
if (count($manifest['artifacts'])!==18)throw new RuntimeException('Missing palette previews');
$json=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
$f=fopen($root.'/gallery-manifest.json','x');
if($f===false)throw new RuntimeException('Private manifest create denied');
try {
    if(fwrite($f,$json)!==strlen($json)||!fflush($f))
        throw new RuntimeException('Private manifest write incomplete');
}finally{fclose($f);}
chmod($root.'/gallery-manifest.json',0600);
echo "F1-192: 18 fictional branded pages with SHA-256 for private Chromium.\n";
