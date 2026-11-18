<?php

declare(strict_types=1);

/** F1-27: entirely offline, synthetic, adversarial static HTML checks. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $sub = substr($class, strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D', $sub)) {
        throw new RuntimeException('Unsafe class path');
    }
    require __DIR__.'/../src/'.str_replace('\\', '/', $sub).'.php';
});

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\SyntheticReleaseBundle;

$checks = 0;
function f27Assert(bool $value, string $reason): void
{
    global $checks;
    if (!$value) throw new RuntimeException('FAIL: '.$reason);
    ++$checks;
    echo 'PASS: '.$reason."\n";
}
function f27Denied(callable $call): bool
{
    try { $call(); } catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}

$renderer = new StaticSiteRenderer();
$brief = new SiteBrief('onepage','Empresa fictícia','Conheça nossas soluções',
    'Um exemplo sem terceiros, dados pessoais ou serviços externos.',
    'Saiba mais', ['Atendimento','Conhecimento','Confiança']);
$original = $renderer->render($brief);
$expected = new ReleaseManifest('tenant-f27','site-f27','release-001',$original->digest);
f27Assert(InertPreviewAudit::isSafe($original->html), 'legitimate onepage passes offline audit');
f27Assert(SyntheticReleaseBundle::verifyFiles(
    (new SyntheticReleaseBundle($expected,$original))->files,$expected),
    'legitimate inert bundle passes integrity validation');

$landing = $renderer->render(new SiteBrief('landing_page',$brief->brand,$brief->headline,
    $brief->description,$brief->callToAction,$brief->benefits));
f27Assert(InertPreviewAudit::isSafe($landing->html),
    'legitimate landing page passes the same audit');

$escaped = $renderer->render(new SiteBrief('onepage','<script>alert(1)</script>',
    'Produto com <tags> de texto', 'Símbolos especiais & corretamente codificados',
    'Clique', ['Texto com "aspas" & <marcação>']));
f27Assert(InertPreviewAudit::isSafe($escaped->html),
    'escaped customer-like copy does not become executable markup');

// Each field reaches a different text context; description also reaches the
// quoted meta value. Audit only the contexts that can have active effects.
$literalCopy = [
    'URL and handler-like words' => 'https://example.invalid onboarding=aberto onclick="x" javascript:x',
    'quoted markup as text' => '<a href="http://example.invalid" onclick="x">Texto & "aspas"</a>',
    'Unicode and line breaks' => "Ação 😀\r\nonfocus='nada' https://example.invalid/?a=1&b=2",
];
foreach (['onepage', 'landing_page'] as $product) {
    foreach (['brand', 'headline', 'description', 'callToAction', 'benefits'] as $field) {
        foreach ($literalCopy as $label => $copy) {
            $values = get_object_vars($brief);
            $values['productCode'] = $product;
            $values[$field] = $field === 'benefits' ? [$copy] : $copy;
            $candidate = $renderer->render(new SiteBrief(...$values));
            f27Assert(InertPreviewAudit::isSafe($candidate->html),
                'literal '.$label.' remains inert in '.$product.' '.$field);
        }
    }
}
$textual = $renderer->render(new SiteBrief('onepage', $brief->brand, $brief->headline,
    'https://example.invalid onboarding=aberto onclick="texto"', $brief->callToAction, $brief->benefits));

$base = $original->html;
$mutations = [
    'external navigation' => str_replace('href="#contato"','href="https://invalid.example/track"',$base),
    'remote stylesheet' => str_replace('</head>','<link rel="stylesheet" href="https://invalid.example/a.css"></head>',$base),
    'meta refresh' => str_replace('</head>','<meta http-equiv="refresh" content="0;url=https://invalid.example"></head>',$base),
    'alternate robots policy' => str_replace('noindex, nofollow','index, follow',$base),
    'additional robots policy' => str_replace('</head>','<meta name="robots" content="index, follow"></head>',$base),
    'weakened CSP' => str_replace("default-src 'none'", "default-src *",$base),
    'live script' => str_replace('</body>','<script>alert(1)</script></body>',$base),
    'live form' => str_replace('</body>','<form action="/leads"><input name="email"></form></body>',$base),
    'inline event handler' => str_replace('<div class="container header-inner">',
        '<div class="container header-inner" onmouseover="alert(1)">',$base),
    'inline remote style' => str_replace('<section class="hero"',
        '<section style="background:url(https://invalid.example/a)" class="hero"',$base),
    'CSS import' => str_replace('</style>','@import url(https://invalid.example/a.css);</style>',$base),
    'CSS remote URL' => str_replace('</style>','body { background: url(https://invalid.example/b); }</style>',$base),
    'unapproved image' => str_replace('</main>','<img src="https://invalid.example/1"></main>',$base),
    'unknown SVG' => str_replace('</main>','<svg onload="alert(1)"></svg></main>',$base),
    'extra heading' => str_replace('</main>','<h1>Other title</h1></main>',$base),
    'unescaped malformed element' => str_replace('</main>','<script src="https://bad.invalid"</main>',$base),
    'HTML comment' => str_replace('</main>','<!-- hidden metadata --></main>',$base),
    'missing disclaimer' => str_replace(
        'Prévia demonstrativa: formulários e envio de dados permanecem desabilitados.',
        'Fora do modo de teste', $base),
    'unexpected product body' => str_replace('data-site-product="onepage"','data-site-product="custom"',$base),
    // Neutralizing text must not hide a real attribute or a second metadata tag.
    'real event beside literal metadata copy' => str_replace('onclick=&quot;texto&quot;">',
        'onclick=&quot;texto&quot;" onfocus="alert(1)">', $textual->html),
    'extra metadata beside literal copy' => str_replace('</head>',
        '<meta http-equiv="refresh" content="0;url=https://invalid.example"></head>', $textual->html),
    'live handler beside escaped text' => str_replace('<div class="container header-inner">',
        '<div class="container header-inner" onclick="alert(1)">', $textual->html),
    'javascript link beside escaped text' => str_replace('href="#contato"',
        'href="javascript:alert(1)"', $textual->html),
    'entity-obfuscated javascript link' => str_replace('href="#contato"',
        'href="java&#x73;cript:alert(1)"', $textual->html),
    'protocol-relative navigation' => str_replace('href="#contato"',
        'href="//invalid.example/track"', $textual->html),
    'remote CSS beside escaped text' => str_replace('</style>',
        'body { background: url(https://invalid.example/a); }</style>', $textual->html),
    'remote CSS string remains restricted' => str_replace('</style>',
        'body { --source: "https://invalid.example/a"; }</style>', $textual->html),
];
foreach ($mutations as $label=>$modified) {
    f27Assert($modified !== $base, 'mutation constructed: '.$label);
    f27Assert(!InertPreviewAudit::isSafe($modified),
        'audit denies: '.$label);
    // Even when an adversary recomputes BOTH HTML digest and manifest, the
    // bundle must reject unsafe content before packaging (not a signature).
    $modifiedArtifact = new ReleaseArtifact($modified);
    $modifiedManifest = new ReleaseManifest(
        'tenant-f27','site-f27','release-tampered',$modifiedArtifact->digest);
    f27Assert(f27Denied(fn()=>new SyntheticReleaseBundle($modifiedManifest,$modifiedArtifact)),
        'recomputed hash cannot bypass inert preview policy: '.$label);
}
echo "\n$checks F1-27 offline preview audit checks passed; no publication, network or production approvals.\n";
