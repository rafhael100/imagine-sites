<?php

declare(strict_types=1);

/** F1-52: fixed, inert theme gallery in private TEMP only. Not an endpoint. */
if (PHP_SAPI!=='cli' || getenv('SITES_F52_GALLERY')!=='1' ||
    getenv('APP_ENV')!=='testing') {
    fwrite(STDERR,"F1-52 is disabled without explicit CLI/testing flags.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)){
        throw new RuntimeException('Unsafe fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticReleaseBundle;
use Imagine\Sites\Domain\ReleaseManifest;

$root=rtrim(sys_get_temp_dir(),'/').'/sites-f52-gallery';
if (is_link($root) || file_exists($root) || is_dir($root)) {
    throw new RuntimeException('Private gallery target already exists. Never overwrite.');
}
$renderer=new StaticSiteRenderer();
$files=[];
$manifest=[
    'schemaVersion'=>'sites.synthetic.styles.v1',
    'syntheticOnly'=>true,
    'publishable'=>false,
    'officialClientAcceptance'=>false,
    'artifacts'=>[],
];
foreach(['onepage','landing_page'] as $product){
    foreach(['classic','modern','warm'] as $preset){
        $brief=new SiteBrief($product,'Marca Fictícia Imagine',
            'Tecnologia que facilita seu dia a dia',
            'Demonstração de layouts de sites sem dados pessoais, clientes ou publicação.',
            'Conheça nossas soluções',
            ['Experiência organizada','Conteúdo objetivo','Estrutura acessível'],
            $preset);
        $artifact=$renderer->render($brief);
        $release=new ReleaseManifest('tenant-f52-demo','site-f52-demo',
            'release-f52-'.$product.'-'.$preset,$artifact->digest);
        $bundle=new SyntheticReleaseBundle($release,$artifact);
        if (!InertPreviewAudit::isSafe($artifact->html) ||
            !SyntheticReleaseBundle::verifyFiles($bundle->files,$release)) {
            throw new RuntimeException('Fixture failed strict inert HTML check.');
        }
        $name=$product.'-'.$preset.'.html';
        $files[$name]=$artifact->html;
        $manifest['artifacts'][]=[
            'filename'=>$name,
            'product'=>$product,
            'preset'=>$preset,
            'sha256'=>$artifact->digest,
            'bytes'=>$artifact->sizeBytes,
        ];
    }
}
$files['gallery-manifest.json']=json_encode($manifest,
    JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
if (!mkdir($root,0700)) throw new RuntimeException('No private gallery directory');
@chmod($root,0700);
foreach($files as $name=>$data){
    $stream=fopen($root.'/'.$name,'x');
    if($stream===false)throw new RuntimeException('Gallery file not creatable');
    try{
        $count=0;
        while($count<strlen($data)){
            $n=fwrite($stream,substr($data,$count));
            if($n===false||$n===0)throw new RuntimeException('Partial gallery file');
            $count+=$n;
        }
    }finally{fclose($stream);}
    @chmod($root.'/'.$name,0600);
}
echo "F1-52 PRIVATE OFFLINE STYLE GALLERY. No client, approval or publish.\n";
echo "Six nonpublishable HTML previews and an exact SHA-256 manifest created.\n";
