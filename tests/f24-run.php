<?php

declare(strict_types=1);

/** F1-24 synthetic packaging tests, no web/CDN/file writes. */
spl_autoload_register(static function (string $name): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($name,$prefix)) return;
    $sub=substr($name,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$sub)) {
        throw new RuntimeException('Unsafe test class name.');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$sub).'.php';
});
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\SyntheticReleaseBundle;

$count=0;
function checkF24(bool $ok,string $what):void {
    global $count;
    if(!$ok)throw new RuntimeException('FAIL '.$what);
    ++$count;
    echo 'PASS '.$what."\n";
}
function deniedF24(callable $fn):bool {
    try {$fn();}catch (DomainException|InvalidArgumentException) {return true;}
    return false;
}
$renderer=new StaticSiteRenderer();
$base=new SiteBrief('onepage','Empresa fictícia','Soluções de teste',
    'Conteúdo sintético e sem envio de leads.',
    'Conhecer mais',['Atendimento','Projetos']);
$artifact=$renderer->render($base);
$manifest=new ReleaseManifest('tenant-fake-01','site-fake-01','release-fake-01',$artifact->digest);
$bundle=new SyntheticReleaseBundle($manifest,$artifact);
checkF24(array_keys($bundle->files)===['index.html','release-manifest.json'],
    'fixed artifact bundle filenames');
checkF24(SyntheticReleaseBundle::verifyFiles($bundle->files,$manifest),
    'exact artifact digest, identity, meta and offline flag are verified');
checkF24(strlen($bundle->bundleDigest)===64,
    'bundle has deterministic SHA-256 digest');
checkF24(SyntheticReleaseBundle::verifyExactBundle(
    $bundle->files,$manifest,$bundle->bundleDigest
), 'external pinned complete-bundle digest verifies exactly');
checkF24(!SyntheticReleaseBundle::verifyExactBundle(
    $bundle->files,$manifest,str_repeat('0',64)
), 'wrong independent bundle pin is rejected');
foreach (['', 'not-a-digest', strtoupper($bundle->bundleDigest),
    substr($bundle->bundleDigest,0,63)] as $invalidPin) {
    checkF24(!SyntheticReleaseBundle::verifyExactBundle(
        $bundle->files,$manifest,$invalidPin
    ), 'invalid external digest representation is refused');
}
$extraFile=$bundle->files;
$extraFile['extra.html']='not a release file';
checkF24(!SyntheticReleaseBundle::verifyExactBundle(
    $extraFile,$manifest,$bundle->bundleDigest
), 'unlisted file cannot hide inside a valid digest pin');
$reversed=array_reverse($bundle->files,true);
checkF24(!SyntheticReleaseBundle::verifyExactBundle(
    $reversed,$manifest,$bundle->bundleDigest
), 'file ordering is part of exact private package grammar');
$mutatedManifest=$bundle->files;
$mutatedManifest['release-manifest.json'].="\n";
checkF24(!SyntheticReleaseBundle::verifyExactBundle(
    $mutatedManifest,$manifest,$bundle->bundleDigest
), 'even whitespace appended to manifest invalidates package');

checkF24($bundle->bundleDigest===(new SyntheticReleaseBundle($manifest,$artifact))->bundleDigest,
    'bundle reproducible regardless of wall-clock time');
$meta=json_decode($bundle->files['release-manifest.json'],true,16,JSON_THROW_ON_ERROR);
checkF24($meta['syntheticOnly']===true && $meta['publishable']===false &&
    $meta['officialClientAcceptance']===false,'preview cannot masquerade as official published delivery');

$modified=$bundle->files;
$modified['index.html'].=' ';
checkF24(!SyntheticReleaseBundle::verifyFiles($modified,$manifest),
    'tampered bytes invalidate digest');
$modified=$bundle->files;
$changed=json_decode($modified['release-manifest.json'],true,16,JSON_THROW_ON_ERROR);
$changed['publishable']=true;
$modified['release-manifest.json']=json_encode($changed,JSON_THROW_ON_ERROR);
checkF24(!SyntheticReleaseBundle::verifyFiles($modified,$manifest),
    'editing publishable flag fails verification');
$changed=$bundle->files;
$changed['index.html']='<script>window.alert(1)</script>'.$artifact->html;
checkF24(!SyntheticReleaseBundle::verifyFiles($changed,$manifest),
    'embedded script rejected');
$wrongTenant=new ReleaseManifest('tenant-another','site-fake-01','release-fake-01',$artifact->digest);
checkF24(!SyntheticReleaseBundle::verifyFiles($bundle->files,$wrongTenant),
    'cross-tenant package cannot be relabelled');
checkF24(deniedF24(fn()=>new ReleaseManifest('../tenant','site-fake-01','release-fake-01',$artifact->digest)),
    'path traversal in tenant ref denied');
checkF24(deniedF24(fn()=>new ReleaseManifest('tenant-fake-01',"site\ninject",'release-fake-01',$artifact->digest)),
    'newline injection in site ref denied');
checkF24(deniedF24(fn()=>new ReleaseManifest('tenant-fake-01','site-fake-01','../release',$artifact->digest)),
    'path traversal in release ref denied');

$badArtifact=new ReleaseArtifact($artifact->html.'<form action="/capture"></form>');
$badManifest=new ReleaseManifest('tenant-fake-01','site-fake-01','release-malicious',$badArtifact->digest);
checkF24(deniedF24(fn()=>new SyntheticReleaseBundle($badManifest,$badArtifact)),
    'bundle builder refuses live HTML form even with matching digest');

$fakeJs=new ReleaseArtifact($artifact->html.'<script>alert(1)</script>');
$fakeManifest=new ReleaseManifest('tenant-fake-01','site-fake-01','release-js',$fakeJs->digest);
checkF24(deniedF24(fn()=>new SyntheticReleaseBundle($fakeManifest,$fakeJs)),
    'bundle builder refuses script even with matching digest');

$fakeNoIndex=new ReleaseArtifact(str_replace('noindex, nofollow','index, follow',$artifact->html));
$fakeNoIndexManifest=new ReleaseManifest('tenant-fake-01','site-fake-01','release-index',$fakeNoIndex->digest);
checkF24(deniedF24(fn()=>new SyntheticReleaseBundle($fakeNoIndexManifest,$fakeNoIndex)),
    'preview noindex cannot be removed for this bundle');
$wrongDigest=new ReleaseManifest('tenant-fake-01','site-fake-01','release-fake-01',str_repeat('0',64));
checkF24(deniedF24(fn()=>new SyntheticReleaseBundle($wrongDigest,$artifact)),
    'artifact mismatch is denied before bundle build');

$escapedBrief=new SiteBrief(
    'landing_page','<svg onload=alert(1)>','" onfocus="alert(1)',
    '<script>alert(1)</script>','Clique',
    ['literal-inert-text'] // no URI scheme or execution
);
$escapedArtifact=$renderer->render($escapedBrief);
checkF24(!str_contains($escapedArtifact->html,'<svg onload=') &&
    !str_contains($escapedArtifact->html,'<script>alert(1)</script>'),
    'untrusted text is HTML-escaped in renderer');
checkF24(deniedF24(fn()=>new SiteBrief('landing_page','Fictícia',
    'Título','Descrição','Clique',['javascript:alert(1)'])),
    'unsafe javascript URL is refused before rendering');
$modifiedHtml=str_replace('literal-inert-text','javascript:alert(1)',
    $escapedArtifact->html);
checkF24($modifiedHtml!==$escapedArtifact->html,
    'adversarial package mutation changes exact HTML bytes');
$modifiedArtifact=new ReleaseArtifact($modifiedHtml);
checkF24(deniedF24(fn()=>new SyntheticReleaseBundle(
    new ReleaseManifest('tenant-fake-01','site-fake-01','release-esc',$modifiedArtifact->digest),
    $modifiedArtifact
)), 'even recomputed digest cannot hide javascript: inside package');

$landing=$renderer->render(new SiteBrief('landing_page','Testes','Anúncio exemplo',
    'Página fictícia de conversão sem forms','Contato',['Exemplo']));
$landingRef=new ReleaseManifest('tenant-fake-01','site-fake-01','release-landing',$landing->digest);
checkF24(SyntheticReleaseBundle::verifyFiles((new SyntheticReleaseBundle($landingRef,$landing))->files,$landingRef),
    'landing-page fixture bundles with exact digest too');

echo "\n$count F1-24 inert content-addressed bundle checks passed. NO publishable artifact.\n";
