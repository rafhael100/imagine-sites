<?php

declare(strict_types=1);

/** F1-51: strict, offline graphic presets only; no user CSS or public sites. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $r=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$r)){
        throw new RuntimeException('Unsafe synthetic test autoload path');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticReleaseBundle;
use Imagine\Sites\Domain\ReleaseManifest;

$n=0;
function f51Check(bool $ok,string $reason):void{
    global $n;
    if(!$ok)throw new RuntimeException('FAIL '.$reason);
    ++$n;echo 'PASS '.$reason."\n";
}
function f51Denied(callable $cb):bool{
    try{$cb();}catch(InvalidArgumentException|DomainException){return true;}
    return false;
}
$renderer=new StaticSiteRenderer();
foreach(['onepage','landing_page'] as $product){
    $result=[];
    foreach(['classic','modern','warm'] as $theme){
        $brief=new SiteBrief($product,'Marca 100% Fictícia',
            'Sites responsivos para demonstração',
            'Exemplo offline sem formulário, pessoa ou cliente real.',
            'Veja os benefícios',['Organização','Clareza','Presença digital'],$theme);
        $artifact=$renderer->render($brief);
        f51Check(InertPreviewAudit::isSafe($artifact->html),
            "safe strict HTML: $product $theme");
        f51Check(hash_equals(hash('sha256',$artifact->html),$artifact->digest),
            "SHA-256 exact bytes: $product $theme");
        f51Check($artifact->html===$renderer->render($brief)->html,
            "deterministic bytes: $product $theme");
        f51Check(str_contains($artifact->html,'name="robots" content="noindex, nofollow"') &&
            !str_contains($artifact->html,'<script') &&
            !str_contains($artifact->html,'<form') &&
            !str_contains($artifact->html,'http://') &&
            !str_contains($artifact->html,'https://'),
            "no public networking or lead collection: $product $theme");
        $manifest=new ReleaseManifest('tenant-f51-fake','site-f51-fake',
            'release-f51-'.$product.'-'.$theme,$artifact->digest);
        $bundle=new SyntheticReleaseBundle($manifest,$artifact);
        f51Check(SyntheticReleaseBundle::verifyFiles($bundle->files,$manifest) &&
            json_decode($bundle->files['release-manifest.json'],true,16,JSON_THROW_ON_ERROR)
                ['publishable']===false,
            "nonpublishable bundle verified: $product $theme");
        f51Check(str_contains($artifact->html,'href="#contato"') &&
            str_contains($artifact->html,'id="contato"'),
            "static CTA remains a local anchor: $product $theme");
        $result[$theme]=$artifact->digest;
        if($theme!=='classic'){
            f51Check(str_contains($artifact->html,'visual-preset: '.$theme) &&
                !str_contains($artifact->html,'@import') &&
                !str_contains($artifact->html,'url('),
                "only fixed inline palette; no custom CSS/assets: $product $theme");
            f51Check(str_contains($artifact->html,'minmax(min(100%,220px),1fr)'),
                "preset narrow-viewport grid is responsive: $product $theme");
        }else{
            f51Check(!str_contains($artifact->html,'visual-preset: '),
                "classic HTML unchanged by default: $product");
        }
    }
    f51Check(count(array_unique($result))===3,
        "three visual presets have distinct exact release digests for $product");
}
$default=new SiteBrief('onepage','Fictícia','Título','Descrição','Ver',['Vantagem']);
$explicit=new SiteBrief('onepage','Fictícia','Título','Descrição','Ver',['Vantagem'],'classic');
f51Check($renderer->render($default)->html===$renderer->render($explicit)->html,
    'classic default and explicit version are byte-for-byte equivalent');
f51Check(f51Denied(fn()=>new SiteBrief('onepage','Marca','Título','Texto','Clique',
    ['Benefício'],'url(https://attacker.test)')),'untrusted CSS cannot be passed as theme');
f51Check(f51Denied(fn()=>new SiteBrief('onepage','Marca','Título','Texto','Clique',
    ['Benefício'],'<script>alert(1)</script>')),'script injection rejected as theme');
$attack=new SiteBrief('landing_page','<script>bad</script>',
    '" onmouseover="bad"','Descrição com <tags> e & símbolos',
    'Saiba & conheça',['<img src=x>'],'warm');
$artifact=$renderer->render($attack);
f51Check(InertPreviewAudit::isSafe($artifact->html) &&
    !str_contains($artifact->html,'<script>') &&
    str_contains($artifact->html,'&lt;script&gt;') &&
    str_contains($artifact->html,'&lt;img src=x&gt;'),
    'injected content escaped even in warm style');
echo "\n$n F1-51 synthetic visual preset checks passed. No public site, HTTP, AI, or client data.\n";
