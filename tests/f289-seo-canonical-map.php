<?php

declare(strict_types=1);

/** F1-289 offline canonical + sitemap preview; no public HTTP or SEO crawling. */
require __DIR__.'/../src/Domain/SyntheticCanonicalOriginPlanV1.php';
require __DIR__.'/../src/Domain/SyntheticSeoCanonicalMapV1.php';

use Imagine\Sites\Domain\SyntheticCanonicalOriginPlanV1 as Policy;
use Imagine\Sites\Domain\SyntheticSeoCanonicalMapV1 as Seo;

$count=0;
function check289(bool $ok,string $case):void {
    global $count;
    if (!$ok) throw new RuntimeException('F1-289 '.$case);
    ++$count;
}
function denied289(callable $action):bool {
    try {$action();} catch (InvalidArgumentException) {return true;}
    return false;
}
$apex=Policy::propose('cliente.com.br','public_site');
$paths=['/', '/servicos/', '/equipe/', '/produto_azul/', '/sobre-nos/'];
$plan=Seo::draft($apex,$paths);
check289($plan['canonicalUrls']===array_map(
    static fn(string $p):string=>'https://cliente.com.br'.$p,$paths
), 'apex-first without www in every canonical URL');
check289(str_contains($plan['sitemapXmlCandidate'],
    '<url><loc>https://cliente.com.br/servicos/</loc></url>') &&
    substr_count($plan['sitemapXmlCandidate'],'<url><loc>')===count($paths) &&
    !str_contains($plan['sitemapXmlCandidate'],'https://www.cliente.com.br/'),
    'offline XML candidate uses only selected HTTPS apex');
check289(!$plan['indexable'] && !$plan['mayServe'] &&
    !str_contains($plan['sitemapXmlCandidate'], '<lastmod>'),
    'no publication, no guessed crawl freshness or index permission');
check289($plan===Seo::draft($apex,$paths), 'deterministic sitemap bytes');
foreach ([
    Policy::propose('cliente.com.br','campaign_subdomain','lp'),
    Policy::propose('cliente.com.br','legacy_preserved',null,'https://www.cliente.com.br')
] as $site) {
    $output=Seo::draft($site,['/','/contato/']);
    check289($output['canonicalUrls'] === [
        $site['canonicalOrigin'].'/', $site['canonicalOrigin'].'/contato/'
    ] && !$output['mayServe'],
    'independent campaign or historical www kept without host migration');
}
foreach ([
    Policy::propose('cliente.com.br','application','portal'),
    Policy::propose('cliente.com.br','application','api')
] as $site) {
    check289(denied289(static fn()=>Seo::draft($site,['/'])),
        'do not emit application URLs in public site sitemap');
}
foreach ([
    ['mayPublish'=>true], ['mayRedirect'=>true],
    ['approvalState'=>'PRODUCTION'], ['hostClass'=>'application'],
    ['approvalState'=>'LEGACY_HOLD'],
    ['canonicalOrigin'=>'https://www.cliente.com.br'],
    ['canonicalOrigin'=>'http://cliente.com.br'],
    ['canonicalOrigin'=>'https://cliente.com.br:443'],
    ['canonicalOrigin'=>'https://cliente.com.br/?a=1'],
    ['canonicalOrigin'=>'https://cliente.com.br.evil.net'],
    ['canonicalOrigin'=>'https://xn--falso.com.br'],
    ['alternateOrigins'=>[]],
    ['alternateOrigins'=>['https://other.com.br']]
] as $tamper) {
    check289(denied289(static fn()=>Seo::draft(array_replace($apex,$tamper),['/'])),
        'refuse altered origin approval or cross-domain configuration');
}
foreach ([
    [], ['/servicos/'], ['/', '/'], ['/', '/servicos/', '/servicos/'],
    ['/', '//example.com'], ['/', '/abc'], ['/', '/a//b/'],
    ['/', '/./'], ['/', '/../'], ['/', '/a/../'], ['/', '/.env/'],
    ['/', '/%2e%2e/'], ['/', '/produto?chave=abc'], ['/', '/x#ancora'],
    ['/', '/a<b>/'], ['/', '/a&b/'], ['/', "/a\r\n/"],
    ['/', '/ação/'], ['/', '/a b/'], ['/', 'https://evil.com/'],
    ['/', '/a@evil/'], ['/', '/a:443/']
] as $bad) {
    check289(denied289(static fn()=>Seo::draft($apex,$bad)),
        'deny unsafe sitemap path set: '.bin2hex(json_encode($bad)));
}
check289(denied289(static fn()=>Seo::draft($apex,array_fill(0,101,'/'))),
    'sitemap page count bounded');
check289(denied289(static fn()=>Seo::draft($apex,['/','/'.str_repeat('a',512).'/'])),
    'sitemap per-path size bounded');
echo "$count F1-289 canonical SEO offline mapping assertions passed; no live sitemap.\n";
