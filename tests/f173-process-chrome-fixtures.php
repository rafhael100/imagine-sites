<?php

declare(strict_types=1);

/** F1-173: private browser fixtures, no client data or publication. */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F173_CHROME_LAB')!=='1') {
    fwrite(STDERR,"F1-173 is isolated CI only.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unsafe Chrome fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;
use Imagine\Sites\Production\SyntheticReleaseBundle;
use Imagine\Sites\Domain\ReleaseManifest;

$directory=rtrim(sys_get_temp_dir(),'/').'/sites-f173-process-gallery';
if(file_exists($directory)||is_link($directory)||!mkdir($directory,0700)) {
    throw new RuntimeException('Private process fixture path must be new');
}
$renderer=new StaticSiteRenderer();
$manifest=[
 'schemaVersion'=>'sites.synthetic.process-chrome-gallery.v1',
 'syntheticOnly'=>true,
 'publishable'=>false,
 'officialClientAcceptance'=>false,
 'artifacts'=>[],
];
$longTitle=str_repeat('LongaEtapa',9);
$steps=[
  ['title'=>$longTitle,
   'description'=>'Etapa de demonstração com título intencionalmente longo para validar quebra em celulares.'],
  ['title'=>'Construção de uma prévia',
   'description'=>'O material fictício é organizado em uma página sem scripts nem captação de dados.'],
  ['title'=>'Revisão por uma pessoa',
   'description'=>'A liberação exige decisão independente e não acontece neste laboratório.'],
];
$faq=[['question'=>'A página já aceita leads?',
       'answer'=>'Não. O formulário e qualquer operação de captação permanecem bloqueados.']];
$highlights=[['title'=>'Informações organizadas',
              'description'=>'Uma seção descritiva sem links externos ou comandos de produção.']];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach(['process','complete'] as $variant) {
            $brief=new SiteBrief(
                $product,'Marca Fictícia Imagine',
                'Uma apresentação de serviços digitais',
                'Prévia estática interna para avaliar etapas e conteúdo de uma página em múltiplos tamanhos.',
                'Conheça os benefícios',
                ['Uma página objetiva','Fluxo de revisão controlado'],
                $preset,
                $variant==='complete'?$faq:[],
                $variant==='complete'?$highlights:[],
                $steps,
            );
            $artifact=$renderer->render($brief);
            $release=new ReleaseManifest(
                'tenant-f173','site-f173',
                'release-f173-'.$product.'-'.$preset.'-'.$variant,
                $artifact->digest
            );
            $bundle=new SyntheticReleaseBundle($release,$artifact);
            if (!InertPreviewAudit::isSafe($artifact->html) ||
                !SyntheticPreviewQaReport::inspect($artifact)->passedStructuralChecks ||
                !SyntheticReleaseBundle::verifyFiles($bundle->files,$release)) {
                throw new RuntimeException('Unsafe static process browser fixture');
            }
            $name=$product.'-'.$preset.'-'.$variant.'.html';
            $file=fopen($directory.'/'.$name,'x');
            if($file===false)throw new RuntimeException('Exclusive write failed');
            try {
                $offset=0;$bytes=strlen($artifact->html);
                while($offset<$bytes) {
                    $written=fwrite($file,substr($artifact->html,$offset));
                    if($written===false||$written===0)
                        throw new RuntimeException('Incomplete process file write');
                    $offset+=$written;
                }
                if(!fflush($file))throw new RuntimeException('File flush failed');
            }finally{fclose($file);}
            chmod($directory.'/'.$name,0600);
            $manifest['artifacts'][]=[
                'filename'=>$name,'product'=>$product,
                'preset'=>$preset,'variant'=>$variant,
                'sha256'=>$artifact->digest,'bytes'=>$artifact->sizeBytes,
            ];
        }
    }
}
$raw=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
$file=fopen($directory.'/gallery-manifest.json','x');
if($file===false)throw new RuntimeException('Manifest write denied');
try {
    if(fwrite($file,$raw)!==strlen($raw)||!fflush($file))
        throw new RuntimeException('Manifest write incomplete');
}finally{fclose($file);}
chmod($directory.'/gallery-manifest.json',0600);
echo 'F1-173: generated 12 fictional private process HTML documents with SHA-256. '.
    "No PII, CRM, live routes or deploy.\n";
