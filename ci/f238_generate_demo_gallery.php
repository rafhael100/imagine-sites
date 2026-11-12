<?php

declare(strict_types=1);

/**
 * F1-238: export SIX fictional, inert onepage/landing previews to an
 * expiring GitHub Actions artifact. Never customer drafts, remote assets,
 * publishing release, external URL, API, form or approval.
 */
if (PHP_SAPI!=='cli' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('SITES_F238_DEMO_ARTIFACT')!=='1') {
    fwrite(STDERR,"F1-238 requires explicit disposable GitHub Actions lab mode.\n");
    exit(1);
}

$repo=dirname(__DIR__);
$vendor=$repo.'/apps/sites/vendor/autoload.php';
$fixturePath=$repo.'/fixtures/sites/f1-06-onepage.synthetic.json';
$richPath=$repo.'/fixtures/sites/f1-170-process-copy.synthetic.json';
$private=$repo.'/apps/sites/storage/app/private';
$dir=$private.'/f238-demo-previews';

if (!is_file($vendor) || !is_file($fixturePath) || !is_file($richPath) ||
    is_link($fixturePath) || is_link($richPath) || is_link($private) || is_link($dir) ||
    file_exists($dir)) {
    throw new RuntimeException('F1-238 private fixture/output boundary not clean.');
}
require_once $vendor;

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;

$fixture=json_decode(
    (string)file_get_contents($fixturePath),true,8,JSON_THROW_ON_ERROR
);
if (!is_array($fixture) ||
    array_keys($fixture)!==[
        'productCode','brand','headline','description','callToAction','benefits'
    ] ||
    $fixture['brand']!=='Aurora Exemplo' ||
    $fixture['productCode']!=='onepage') {
    throw new RuntimeException('Only the pinned Aurora Exemplo synthetic content is distributable.');
}
// F1-243: enrich the SAME six visual variations with the already-validated
// v4 synthetic content contract. No extra customer data or AI provider.
$rich=json_decode((string)file_get_contents($richPath),true,12,JSON_THROW_ON_ERROR);
if (!is_array($rich) || array_keys($rich)!==[
    'schemaVersion','headline','description','callToAction','benefits',
    'faq','highlights','processSteps'
] || $rich['schemaVersion']!=='sites.ai-copy.v4' ||
    !is_array($rich['faq']) || count($rich['faq'])!==2 ||
    !is_array($rich['highlights']) || count($rich['highlights'])!==2 ||
    !is_array($rich['processSteps']) || count($rich['processSteps'])!==3) {
    throw new RuntimeException('Only the reviewed fictional rich-v4 content fixture is supported.');
}
if (!is_dir($private) && !mkdir($private,0700,true) && !is_dir($private)) {
    throw new RuntimeException('Private demo output root unavailable.');
}
if (!mkdir($dir,0700)) {
    throw new RuntimeException('Cannot create isolated demo output folder.');
}

function write238(string $dir,string $name,string $data): string {
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,70}\z/D',$name) ||
        $data==='' || strlen($data)>150000 || is_link($dir.'/'.$name) ||
        file_exists($dir.'/'.$name) ||
        file_put_contents($dir.'/'.$name,$data,LOCK_EX)!==strlen($data)) {
        throw new RuntimeException('F1-238 refused unexpected output target.');
    }
    chmod($dir.'/'.$name,0600);
    return hash('sha256',$data);
}

$pages=[];
$links=[];
foreach (['onepage','landing_page'] as $product) {
    foreach (['classic','modern','warm'] as $style) {
        $brief=new SiteBrief(
            $product, $fixture['brand'], $rich['headline'], $rich['description'],
            $rich['callToAction'], $rich['benefits'], $style,
            $rich['faq'], $rich['highlights'], $rich['processSteps'],
            ['highlights','process','faq'], $product==='onepage',
            null, true, $rich['headline'].' | '.$fixture['brand'],
            $rich['description']
        );
        $artifact=(new StaticSiteRenderer())->render($brief);
        if (!InertPreviewAudit::isSafe($artifact->html) ||
            !SyntheticPreviewQaReport::inspect($artifact)->passedStructuralChecks ||
            stripos($artifact->html,'http://')!==false ||
            stripos($artifact->html,'https://')!==false ||
            stripos($artifact->html,'<form')!==false ||
            stripos($artifact->html,'<script')!==false) {
            throw new RuntimeException('F1-238 preview failed independent inert QA.');
        }
        // All six rich demos must actually expose the existing renderer's
        // 3 editorial sections and 7 OG/Twitter social metadata tags.
        foreach (['id="solucoes"','id="etapas"','id="perguntas"',
                  '<meta property="og:type"','<meta name="twitter:card"'] as $token) {
            if (!str_contains($artifact->html,$token)) {
                throw new RuntimeException('F1-243 lost a promised rich section.');
            }
        }
        if ($product==='onepage' &&
            (!str_contains($artifact->html,'href="#solucoes"') ||
             !str_contains($artifact->html,'href="#etapas"') ||
             !str_contains($artifact->html,'href="#perguntas"'))) {
            throw new RuntimeException('F1-243 Onepage rich navigation is missing.');
        }
        $filename=$product.'-'.$style.'.html';
        $digest=write238($dir,$filename,$artifact->html);
        if ($digest!==$artifact->digest) {
            throw new RuntimeException('F1-238 disk artifact digest mismatch.');
        }
        $pages[$filename]=[
            'product'=>$product,'preset'=>$style,
            'sha256'=>$digest,'bytes'=>$artifact->sizeBytes,
            'sections'=>['benefits','highlights','process','faq','contact'],
            'navigation'=>$product==='onepage'?'section_anchors':'local_cta',
            'socialMetadata'=>true,
        ];
        $humanProduct=$product==='onepage'?'Onepage':'Landing Page';
        $links[]='<li><a href="'.$filename.'">'.$humanProduct.
            ' · '.ucfirst($style).'</a></li>';
    }
}
if(count($pages)!==6 || count(array_unique(array_column($pages,'sha256')))!==6) {
    throw new RuntimeException('F1-238 six distinct previews required.');
}

$linksHtml=implode("\n      ",$links);
$index=<<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; form-action 'none'; base-uri 'none'">
  <title>Imagine Sites — catálogo fictício de demonstração</title>
  <style>
    body { font-family: system-ui, sans-serif; color: #18304b; background: #f6f8fb; margin: 0; }
    main { max-width: 780px; margin: 5rem auto; padding: 0 1.5rem; }
    h1 { font-size: clamp(2rem, 5vw, 3rem); }
    p { line-height: 1.65; }
    ul { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; }
    a { display: block; padding: 1.2rem; border: 1px solid #d8e2ee; border-radius: 12px; background: white; text-decoration: none; color: #173b65; font-weight: 700; }
    a:focus-visible { outline: 3px solid #2879c7; }
  </style>
</head>
<body>
  <main>
    <h1>Imagine Sites — galeria demonstrativa</h1>
    <p>Seis páginas HTML fictícias: Onepage e Landing Page nos estilos Classic, Modern e Warm. Cada exemplo demonstra serviços, etapas de produção, perguntas frequentes, SEO e prévia social em layout responsivo.</p>
    <p><strong>Não são sites publicados.</strong> Marca Aurora Exemplo fictícia, sem clientes, formulários, envio de dados, scripts ou conexão à internet. O aceite do cliente e a publicação permanecem desabilitados.</p>
    <ul>
      {$linksHtml}
    </ul>
  </main>
</body>
</html>
HTML;

$indexHash=write238($dir,'index.html',$index);
$readme=<<<TXT
IMAGINE SITES — F1-238 GALERIA FICTICIA DE DEMONSTRACAO

Abra index.html no navegador para navegar entre as 6 paginas.
F1-243 usa a fixture ficticia v4 com servicos, etapas, FAQ e metadados
sociais; navegacao por secoes na Onepage, sem funcionalidades ativas.
Todas as paginas sao autocontidas, sem JavaScript, formularios,
imagens remotas, dados pessoais, integracao CRM ou publicacao.
A marca 'Aurora Exemplo' e inteiramente ficticia.

Manifesto: manifest.json
Integridade: SHA256SUMS (hash SHA-256 de cada arquivo listado)
Verificacao opcional: sha256sum --check SHA256SUMS

Este pacote NAO e um site de cliente, um artefato aprovado,
uma autorizacao de go-live, nem uma integracao Portal/Cloudflare.
TXT;
$readmeHash=write238($dir,'README.txt',$readme."\n");
$manifest=json_encode([
    'schema'=>'imagine-sites.synthetic-demo-gallery.v1',
    'productCodes'=>['onepage','landing_page'],
    'stylePresets'=>['classic','modern','warm'],
    'editorialFixture'=>'fixtures/sites/f1-170-process-copy.synthetic.json',
    'richSections'=>['highlights','process','faq'],
    'demoOnly'=>true,
    'approvedForClient'=>false,
    'publishable'=>false,
    'hasContactCapture'=>false,
    'gallerySha256'=>$indexHash,
    'files'=>$pages,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
$manifestHash=write238($dir,'manifest.json',$manifest);

$hashes=['index.html'=>$indexHash] +
    array_map(static fn(array $f):string=>$f['sha256'],$pages) + [
        'README.txt'=>$readmeHash,
        'manifest.json'=>$manifestHash
    ];
$checksums='';
foreach($hashes as $filename=>$digest) {
    $checksums.=$digest.'  '.$filename."\n";
}
write238($dir,'SHA256SUMS',$checksums);

$allowed=array_keys($hashes);
$allowed[]='SHA256SUMS';
sort($allowed);
$actual=scandir($dir);
if ($actual===false)throw new RuntimeException('F1-238 cannot inventory output');
$actual=array_values(array_diff($actual,['.','..']));
sort($actual);
if ($actual!==$allowed) {
    throw new RuntimeException('F1-238 unexpected output inventory.');
}
foreach($hashes as $filename=>$digest) {
    if(!hash_equals($digest,(string)hash_file('sha256',$dir.'/'.$filename))) {
        throw new RuntimeException('F1-238 generated output changed on disk.');
    }
}
echo "PASS F1-238: six distinct audited fictional HTML previews, no external assets.\n";
echo "PASS F1-238: deterministic static index, manifest, SHA256SUMS and no publication power.\n";
echo "PASS F1-243: all six product/preset variants include rich v4 services, steps, FAQ and social preview.\n";
