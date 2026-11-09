<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^(Domain|Production)\\\\[A-Za-z]+$/D', $name)) {
        throw new RuntimeException('Invalid test class name');
    }
    require __DIR__.'/../src/'.str_replace('\\', '/', $name).'.php';
});

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\ReleasePreviewPolicy;

$count = 0;
function pass(bool $cond, string $name): void {
    global $count;
    if (!$cond) throw new RuntimeException('FAIL: '.$name);
    $count++;
    echo 'PASS: '.$name."\n";
}
function denied(callable $callback): bool {
    try { $callback(); } catch (InvalidArgumentException) { return true; }
    return false;
}
$fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/sites/f1-06-onepage.synthetic.json'),true,512,JSON_THROW_ON_ERROR);
$brief = new SiteBrief(...$fixture);
$renderer = new StaticSiteRenderer();
$a = $renderer->render($brief);
$b = $renderer->render($brief);
pass($a->digest === $b->digest, 'same approved-style brief renders deterministic hash');
pass($a->html === $b->html, 'deterministic exact HTML bytes');
pass($a->digest === hash('sha256',$a->html), 'digest corresponds to HTML bytes');
pass(str_contains($a->html,'lang="pt-BR"'), 'Portuguese language set');
pass(str_contains($a->html,'name="viewport"'), 'responsive viewport');
pass(str_contains($a->html,'<h1 id="hero-title">'), 'single main hero heading');
pass(str_contains($a->html,'aria-labelledby'), 'semantic section labels');
pass(str_contains($a->html,'data-site-product="onepage"'), 'onepage type visible');
pass(str_contains($a->html,'id="sobre"'), 'onepage has about section');
pass(str_contains($a->html,'name="robots" content="noindex, nofollow"'), 'synthetic previews blocked from indexing');
pass(!str_contains($a->html,'<form'), 'form capture not silently enabled');
pass(!str_contains($a->html,'<script'), 'no client JavaScript or analytics injected');
pass(!str_contains($a->html,'http://') && !str_contains($a->html,'https://'), 'no external network references');
$manifest = new ReleaseManifest('tenant-fake-a','site-fake-a','release-001',$a->digest);
pass(ReleasePreviewPolicy::evaluate($manifest,$a)->allowed, 'content-addressed preview accepts same digest');
$malicious = new SiteBrief('onepage','<img src=x onerror=alert(1)>','<script>alert(1)</script>',
    '" onmouseover="alert(1)',"Clique <a>",['<iframe src="https://evil.example">']);
$m = $renderer->render($malicious);
pass(!str_contains($m->html,'<script>') && !str_contains($m->html,'<img src=x'), 'injected tags escaped as text');
pass(str_contains($m->html,'&lt;script&gt;'), 'HTML injection encoded');
pass(str_contains($m->html,'&quot; onmouseover='), 'attribute injection quoted/encoded');
pass(!str_contains($m->html,'https://evil.example"'), 'unsafe iframe not emitted as raw tag');
pass(!ReleasePreviewPolicy::evaluate($manifest,$m)->allowed, 'different content invalidates approved digest');
$landing = $renderer->render(new SiteBrief('landing_page',$brief->brand,$brief->headline,
    $brief->description,$brief->callToAction,$brief->benefits));
pass(str_contains($landing->html,'data-site-product="landing-page"'), 'landing mode distinct');
pass(!str_contains($landing->html,'id="sobre"'), 'landing mode omits about section');
pass($landing->digest !== $a->digest, 'different page structures hash differently');
pass(denied(fn()=>new SiteBrief('blog',$brief->brand,$brief->headline,$brief->description,$brief->callToAction,$brief->benefits)), 'unsupported product denied');
pass(denied(fn()=>new SiteBrief('onepage',' ', $brief->headline,$brief->description,$brief->callToAction,$brief->benefits)), 'empty brand denied');
pass(denied(fn()=>new SiteBrief('onepage',$brief->brand,$brief->headline,$brief->description,$brief->callToAction,[])), 'empty benefits denied');
pass(denied(fn()=>new SiteBrief('onepage',$brief->brand,$brief->headline,$brief->description,$brief->callToAction,array_fill(0,9,'Excesso'))), 'too many benefits denied');
pass($a->sizeBytes < 150000, 'synthetic static page within size limit');
echo "\n$count F1-06 static-render checks passed; no AI, network, publication or customer content.\n";
