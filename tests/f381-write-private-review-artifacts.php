<?php

declare(strict_types=1);

// Runs on the GitHub CI host, never as an HTTP server. Consumes the private
// Laravel JSON stdout and emits only wholly-fictional static HTML previews.
if (PHP_SAPI!=='cli' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F381_REVIEW_LAB')!=='1') {
    fwrite(STDERR,"F1-381 requires private GitHub CI fixture.\n");
    exit(1);
}
$input=(string)getenv('SITES_F381_BUNDLE_FILE');
$directory=(string)getenv('SITES_F381_REVIEW_DIR');
if ($input==='' || $directory==='' ||
    !is_file($input) || is_link($input) ||
    !is_dir($directory) || is_link($directory)) {
    throw new RuntimeException('F1-381: private runner paths unavailable.');
}
$data=json_decode((string)file_get_contents($input),true,16,JSON_THROW_ON_ERROR);
if (!is_array($data) ||
    ($data['schemaVersion']??null)!=='sites.ci.review-bundle.v1' ||
    ($data['releaseGate']??null)!=='NO_GO' ||
    ($data['publishable']??null)!==false ||
    ($data['officialClientAcceptance']??null)!==false ||
    ($data['clientIdentitiesIncluded']??null)!==false ||
    ($data['fictionalCopyOnly']??null)!==true ||
    count($data['items']??[])!==2) {
    throw new RuntimeException('F1-381: invalid or non-fictitious review bundle.');
}
$manifest=[
    'schemaVersion'=>$data['schemaVersion'],
    'fictionalOnly'=>true,
    'noCustomerData'=>true,
    'authorizesPublishing'=>false,
    'clientApproved'=>false,
    'releaseGate'=>'NO_GO',
    'items'=>[],
];
$seen=[];
foreach($data['items'] as $item) {
    $product=$item['product']??null;
    $name=$item['filename']??null;
    if (!in_array($product,['onepage','landing_page'],true) ||
        $name!==($product==='onepage'?'preview-onepage.html':'preview-landing-page.html') ||
        isset($seen[$product]) ||
        ($item['publishable']??null)!==false ||
        ($item['clientApproved']??null)!==false ||
        !is_string($item['htmlBase64']??null) ||
        !is_string($item['sha256']??null) ||
        !preg_match('/\A[a-f0-9]{64}\z/D',$item['sha256'])) {
        throw new RuntimeException('F1-381: invalid product or unsafe preview metadata.');
    }
    $html=base64_decode($item['htmlBase64'],true);
    if (!is_string($html) ||
        !str_starts_with($html,'<!doctype html>') ||
        !str_contains($html,'<meta name="robots" content="noindex, nofollow">') ||
        str_contains(strtolower($html),'<script') ||
        str_contains(strtolower($html),'<form') ||
        str_contains($html,'https://') ||
        str_contains($html,'http://') ||
        strlen($html)>150000 ||
        !hash_equals($item['sha256'],hash('sha256',$html)) ||
        ($item['sizeBytes']??null)!==strlen($html)) {
        throw new RuntimeException('F1-381: HTML safety/integrity assertion failed.');
    }
    if (file_put_contents($directory.'/'.$name,$html,LOCK_EX)!==strlen($html)) {
        throw new RuntimeException('F1-381: cannot write reviewed fixture bytes.');
    }
    $seen[$product]=true;
    $manifest['items'][]=[
        'product'=>$product,
        'file'=>$name,
        'sha256'=>$item['sha256'],
        'sizeBytes'=>strlen($html),
        'status'=>'fictional_review_only',
    ];
}
if (!isset($seen['onepage'],$seen['landing_page'])) {
    throw new RuntimeException('F1-381: Onepage and Landing both required.');
}
$index=<<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; frame-src 'self'; base-uri 'none'; form-action 'none'; script-src 'none'">
<title>Imagine Sites | Prévia de revisão técnica</title>
<style>
:root{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#24272c;background:#f7f8fa}
*{box-sizing:border-box}body{margin:0}a{color:inherit}a:focus-visible{outline:3px solid #f9941d;outline-offset:3px}
header{border-bottom:1px solid #e4e5e8;background:#fff;padding:22px max(24px,calc((100vw - 1180px)/2))}
.brand{color:#e67e09;font-weight:900;letter-spacing:-.03em;font-size:23px}
.brand span{font-weight:500;color:#515967;margin-left:8px}
main{max-width:1180px;margin:auto;padding:48px 24px 70px}
.eyebrow{font-size:12px;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#dc7b0e}
h1{font-size:clamp(30px,4vw,48px);line-height:1.15;letter-spacing:-.045em;max-width:800px;margin:12px 0 12px}
.intro{max-width:760px;color:#56606e;line-height:1.65}
.warning{margin:24px 0 30px;padding:13px 16px;background:#fff6e8;border-left:4px solid #f9941d;color:#8a561b;font-size:14px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:22px}
.card{background:#fff;border:1px solid #e9eaed;border-radius:18px;overflow:hidden;box-shadow:0 12px 35px #1b2b4210}
.card-content{padding:20px 24px 24px}
.card-header{display:flex;justify-content:space-between;align-items:center;gap:12px}
h2{font-size:21px;margin:0}.status{font-size:12px;font-weight:800;color:#875014;background:#fff1da;padding:6px 10px;border-radius:20px}
.card p{color:#5e6874;line-height:1.5}.preview{height:360px;border:0;border-bottom:1px solid #eceef1;width:100%;background:#fff}
.open{display:inline-flex;background:#f9941d;color:#1a1c20;padding:12px 16px;border-radius:9px;text-decoration:none;font-weight:800}
footer{font-size:13px;color:#758091;margin-top:30px}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}
</style>
</head>
<body>
<header><div class="brand">Imagine <span>Sites</span></div></header>
<main>
<div class="eyebrow">Revisão técnica • ambiente fictício</div>
<h1>As primeiras prévias dos projetos admitidos</h1>
<p class="intro">Demonstrações geradas pelo motor HTML do Imagine Sites a partir de dois projetos de teste previamente admitidos. Layouts seguros, responsivos e sem publicação.</p>
<div class="warning">Estes arquivos não são sites de clientes. Conteúdo fictício, sem captação de leads, contratação ou aprovação. Publicação bloqueada.</div>
<div class="grid">
<article class="card">
<iframe class="preview" title="Prévia Onepage" src="preview-onepage.html" sandbox=""></iframe>
<div class="card-content"><div class="card-header"><h2>Onepage</h2><span class="status">Em revisão</span></div>
<p>Apresentação institucional em uma página, com navegação e benefícios.</p>
<a class="open" href="preview-onepage.html">Abrir a prévia</a></div>
</article>
<article class="card">
<iframe class="preview" title="Prévia Landing Page" src="preview-landing-page.html" sandbox=""></iframe>
<div class="card-content"><div class="card-header"><h2>Landing Page</h2><span class="status">Em revisão</span></div>
<p>Página de campanha orientada a uma ação, com conteúdo exclusivamente fictício.</p>
<a class="open" href="preview-landing-page.html">Abrir a prévia</a></div>
</article>
</div>
<footer>Imagine Sites • arquivo gerado exclusivamente em GitHub Actions CI • sem dados reais e sem liberação de produção.</footer>
</main>
</body>
</html>
HTML;
if (file_put_contents($directory.'/index.html',$index,LOCK_EX)!==strlen($index)) {
    throw new RuntimeException('F1-381: cannot write local review index.');
}
$manifestJson=json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
if (file_put_contents($directory.'/manifest.json',$manifestJson,LOCK_EX)!==strlen($manifestJson)) {
    throw new RuntimeException('F1-381: cannot write hash manifest.');
}
echo "F1-381 PASS: two private, fictitious previews and integrity manifest; release NO-GO.\n";
