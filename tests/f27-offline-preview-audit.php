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
    'CSS-like escaped words' => 'u\\72l("x") @\\69mport C:\\x image-set("x" 1x) -webkit-image-set("x" 1x)',
    'unreviewed CSS words' => 'image("x") SRC("x") src(var(--x)) calc(1px) @font-face',
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

// Preserve the current golden and remove only the three known CSS additions
// in memory to reconstruct the exact historical #517/#518 bytes. This is
// a pinned test vector, never an artifact migration or runtime rewrite.
$golden = file_get_contents(__DIR__.'/../contracts/portal-sites/candidates/brief-production-v1/examples/rendered.synthetic.html');
f27Assert(is_string($golden) && strlen($golden) === 5223 &&
    hash('sha256', $golden) === 'ab999fc8c61d4f22ab250c6d5d86442a0ceca24659d1f4173db3580e4694740e' &&
    InertPreviewAudit::isSafe($golden), 'current golden passes with its original bytes and digest');
$historical = str_replace([
    'line-height: 1.6; overflow-wrap: anywhere;',
    'letter-spacing: -.02em; min-width: 0;',
    'text-decoration: none; max-width: 100%;',
], [
    'line-height: 1.6;', 'letter-spacing: -.02em;', 'text-decoration: none;',
], $golden, $historicalChanges);
f27Assert($historicalChanges === 3 && strlen($historical) === 5167 &&
    hash('sha256', $historical) === '12549ec46a09e83ea76353a43a48723daa99aa30c6bbdd461923f1a800543d15' &&
    InertPreviewAudit::isSafe($historical), 'historical golden passes with its original bytes and digest');

$cssPositives = [
    'media at-keyword and nested permitted functions in uppercase' =>
        '@MEDIA(prefers-reduced-motion: reduce) { .benefits ul { grid-template-columns: REPEAT(auto-fit,MINMAX(220px,1fr)); }'.
        ' .hero { background: LINEAR-GRADIENT(120deg,RGBA(1,2,3,.2),RGBA(4,5,6,.3)); padding-block: CLAMP(64px,9vw,136px); } }',
    'quoted strings and comments do not create function or at-rule tokens' =>
        'body { --note: "image() SRC() @font-face"; --other: \'src(var(--asset)) /* literal */\'; }'.
        ' /* image("/probe") src("/probe") @namespace " unfinished quote */',
    'separate identifiers and parentheses are not function tokens' =>
        '@media screen and (min-width: 1px) { body { --label: image /**/ ("/probe"); } }',
];
$cssDenials = [
    'image relative URL string' => 'body { background-image: image("/inert-audit-probe"); }',
    'src relative URL string' => 'body { background-image: src("/inert-audit-probe"); }',
    'src with variable substitution' => 'body { --asset: "/inert-audit-probe"; background-image: src(var(--asset)); }',
    'uppercase SRC relative URL string' => 'body { background-image: SRC("/inert-audit-probe"); }',
    // This is a vocabulary check, not a claim that the outer grammar would
    // make the nested image a resource in a browser.
    'unknown call nested inside permitted functions' => 'body { width: clamp(1px,minmax(1px,image("/probe")),2px); }',
    'unreviewed calc function' => 'body { width: calc(1px + 2px); }',
    'unreviewed var function' => 'body { --width: 1px; width: var(--width); }',
    'unreviewed rgb function' => 'body { color: rgb(1,2,3); }',
    'unreviewed bare-string at-rule' => '@namespace "urn:inert-audit-probe";',
    'unreviewed uppercase conditional at-rule' => '@SUPPORTS (display: grid) { body { display: grid; } }',
    'unclosed double-quoted CSS string' => 'body { --note: "unfinished',
    'unclosed single-quoted CSS string' => "body { --note: 'unfinished",
    'unclosed CSS comment' => '/* unfinished',
    'newline in a CSS string' => "body { --note: \"line one\nline two\"; }",
    'NUL in a CSS name' => "body { --note: rgba\0(1,2,3,.2); }",
];
foreach (['onepage' => $original->html, 'landing_page' => $landing->html] as $product => $source) {
    foreach ($cssPositives as $label => $rule) {
        $candidate = str_replace('</style>', $rule.'</style>', $source, $changes);
        f27Assert($changes === 1 && InertPreviewAudit::isSafe($candidate), $product.' CSS accepts '.$label);
    }
    foreach ($cssDenials as $label => $rule) {
        $candidate = str_replace('</style>', $rule.'</style>', $source, $changes);
        f27Assert($changes === 1 && $candidate !== $source, $product.' CSS mutation constructed: '.$label);
        f27Assert(!InertPreviewAudit::isSafe($candidate), $product.' CSS vocabulary denies: '.$label);
        $artifact = new ReleaseArtifact($candidate);
        $manifest = new ReleaseManifest('tenant-f27', 'site-f27', 'release-css-dialect', $artifact->digest);
        f27Assert(f27Denied(fn () => new SyntheticReleaseBundle($manifest, $artifact)),
            $product.' recomputed bundle digest cannot authorize: '.$label);
    }
}

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
    // These relative targets cannot be caught by a literal http(s) check.
    // CSS consumes the escapes before classifying the function or at-rule.
    'CSS hexadecimal URL function' => str_replace('</style>',
        'body { background-image: u\\72l("/inert-audit-probe"); }</style>', $base),
    'CSS six-digit URL escape without whitespace' => str_replace('</style>',
        'body { background-image: u\\000072l("/inert-audit-probe"); }</style>', $base),
    'CSS padded URL escape' => str_replace('</style>',
        'body { background-image: \\000075 rl("/inert-audit-probe"); }</style>', $base),
    'CSS uppercase URL with escape whitespace' => str_replace('</style>',
        'body { background-image: U\\52 L("/inert-audit-probe"); }</style>', $base),
    'CSS simple URL escape' => str_replace('</style>',
        'body { background-image: \\url("/inert-audit-probe"); }</style>', $base),
    'CSS hexadecimal import keyword' => str_replace('<style>',
        '<style>@\\69mport "/inert-audit-probe.css";', $base),
    'CSS simple import escape' => str_replace('<style>',
        '<style>@\\import "/inert-audit-probe.css";', $base),
    // String arguments are image URLs even without the url() spelling.
    'CSS image-set relative string' => str_replace('</style>',
        'body { background-image: image-set("/inert-audit-probe" 1x); }</style>', $base),
    'CSS uppercase prefixed image-set relative string' => str_replace('</style>',
        'body { background-image: -WEBKIT-IMAGE-SET("/inert-audit-probe" 1x); }</style>', $base),
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
