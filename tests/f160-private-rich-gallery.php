<?php
declare(strict_types=1);

/** F1-160: materialize fictional inert pages for local Chrome CDP only. */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
 || getenv('GITHUB_ACTIONS')!=='true'
 || getenv('SITES_F160_CHROME_LAB')!=='1') {
 fwrite(STDERR,"F1-160 only available in isolated GitHub CI.\n");
 exit(1);
}
spl_autoload_register(static function(string $class):void {
 $prefix='Imagine\\Sites\\';
 if(!str_starts_with($class,$prefix))return;
 $p=substr($class,strlen($prefix));
 if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$p))
   throw new RuntimeException('Unsafe class name');
 require __DIR__.'/../src/'.str_replace('\\','/',$p).'.php';
});
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;
use Imagine\Sites\Production\SyntheticReleaseBundle;
use Imagine\Sites\Domain\ReleaseManifest;

$directory=rtrim(sys_get_temp_dir(),'/').'/sites-f160-rich-gallery';
if(file_exists($directory) || is_link($directory)) {
 throw new RuntimeException('Private gallery target must be new and exclusive');
}
if(!mkdir($directory,0700)) {
 throw new RuntimeException('Cannot create new private gallery directory');
}
$renderer=new StaticSiteRenderer();
$manifest=[
 'schemaVersion'=>'sites.synthetic.rich-gallery.v1',
 'syntheticOnly'=>true,'publishable'=>false,
 'officialClientAcceptance'=>false,'artifacts'=>[]
];
$longQuestion=str_repeat('PerguntaLonga',11).'?';
$longTitle=str_repeat('Solucao',14);
$faq=[
 ['question'=>$longQuestion,
  'answer'=>'Uma demonstração de perguntas sem interação, coleta de dados ou links.'],
 ['question'=>'É possível revisar o conteúdo?',
  'answer'=>'Sim, cada mudança requer novo digest e revisão interna independente.']
];
$highlights=[
 ['title'=>$longTitle,
  'description'=>'Informações objetivas para visualização fictícia em desktop e celular.'],
 ['title'=>'Experiência acessível',
  'description'=>'Prévia estática com boas margens, sem chamadas externas ou scripts.']
];
foreach(['onepage','landing_page'] as $product) {
 foreach(['classic','modern','warm'] as $preset) {
  foreach(['faq','highlights','both'] as $variant) {
   $faqItems=$variant==='highlights'?[]:$faq;
   $cards=$variant==='faq'?[]:$highlights;
   $brief=new SiteBrief(
      $product,'Marca Fictícia Imagine',
      'Uma demonstração de soluções digitais',
      'Prévia interna totalmente sintética com conteúdo descritivo e sem dados de clientes.',
      'Conhecer os detalhes',
      ['Clareza de informações','Organização dos serviços','Estrutura responsiva'],
      $preset,$faqItems,$cards
   );
   $artifact=$renderer->render($brief);
   $release=new ReleaseManifest('tenant-f160','site-f160',
      'release-f160-'.$product.'-'.$preset.'-'.$variant,$artifact->digest);
   $bundle=new SyntheticReleaseBundle($release,$artifact);
   if(!InertPreviewAudit::isSafe($artifact->html)
      || !SyntheticPreviewQaReport::inspect($artifact)->passedStructuralChecks
      || !SyntheticReleaseBundle::verifyFiles($bundle->files,$release)) {
       throw new RuntimeException('Unsafe fictional browser fixture');
   }
   $filename=$product.'-'.$preset.'-'.$variant.'.html';
   $handle=fopen($directory.'/'.$filename,'x');
   if($handle===false)throw new RuntimeException('Fixture creation failed');
   try {
     $bytes=strlen($artifact->html);
     $offset=0;
     while($offset<$bytes) {
       $n=fwrite($handle,substr($artifact->html,$offset));
       if($n===false || $n===0)throw new RuntimeException('Incomplete fixture write');
       $offset+=$n;
     }
     if(!fflush($handle))throw new RuntimeException('Fixture flush failed');
   } finally { fclose($handle); }
   if(!chmod($directory.'/'.$filename,0600)) {
     throw new RuntimeException('Private file mode could not be set');
   }
   $manifest['artifacts'][]=[
     'filename'=>$filename,'product'=>$product,'preset'=>$preset,
     'variant'=>$variant,'sha256'=>$artifact->digest,'bytes'=>$artifact->sizeBytes,
   ];
  }
 }
}
$manifestRaw=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT
 |JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
$handle=fopen($directory.'/gallery-manifest.json','x');
if($handle===false)throw new RuntimeException('Manifest already exists');
try {
 if(fwrite($handle,$manifestRaw)!==strlen($manifestRaw)
   || !fflush($handle))throw new RuntimeException('Manifest write not atomic');
}finally{fclose($handle);}
if(!chmod($directory.'/gallery-manifest.json',0600)) {
 throw new RuntimeException('Manifest private mode failed');
}
echo 'F1-160: 18 private fictional rendered HTML files, digest manifests, '.
     "no form, external network, PII or publish.\n";
