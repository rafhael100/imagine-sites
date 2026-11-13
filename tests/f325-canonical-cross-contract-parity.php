<?php

declare(strict_types=1);

/**
 * F1-325: cross-contract review of apex-first policy, SEO and redirect.
 * No HTTP server, DNS, real credentials, customer URLs or publication.
 */
require __DIR__.'/../src/Domain/SyntheticCanonicalOriginPlanV1.php';
require __DIR__.'/../src/Domain/SyntheticSeoCanonicalMapV1.php';
require __DIR__.'/../src/Domain/SyntheticCanonicalRedirectReviewV1.php';

use Imagine\Sites\Domain\SyntheticCanonicalOriginPlanV1 as Origins;
use Imagine\Sites\Domain\SyntheticSeoCanonicalMapV1 as Seo;
use Imagine\Sites\Domain\SyntheticCanonicalRedirectReviewV1 as Redirect;

$checks = 0;
function assert325(bool $actual, string $case): void {
    global $checks;
    if (!$actual) throw new RuntimeException('F1-325: '.$case);
    ++$checks;
}
function denied325(callable $cb): bool {
    try { $cb(); } catch (InvalidArgumentException) { return true; }
    return false;
}

foreach (['cliente.com.br', 'empresa.net.br', 'cliente.co.uk'] as $zone) {
    $proposal=Origins::propose($zone,'public_site');
    $apex='https://'.$zone;
    $www='https://www.'.$zone;
    $paths=['/','/servicos/','/sobre-nos/'];
    $seo=Seo::draft($proposal,$paths,$apex);
    assert325($seo['canonicalUrls']===array_map(
        static fn(string $path):string=>$apex.$path,$paths
    ) && !$seo['indexable'] && !$seo['mayServe'],
        'SEO paths pinned to HTTPS apex, never externally publishable: '.$zone);
    foreach ($paths as $path) {
        $redirect=Redirect::inspect($proposal,$www,$path.'?utm_source=fictional',
            $apex,'GET');
        assert325($redirect['reason']==='OFFLINE_REDIRECT_REVIEW_CANDIDATE' &&
            $redirect['candidateLocation']===$apex.$path &&
            in_array($redirect['candidateLocation'],$seo['canonicalUrls'],true) &&
            $redirect['statusCandidate']===308 &&
            $redirect['queryDiscarded'] && !$redirect['mayExecute'],
            'www path redirects only to a sitemap canonical, strips query: '.$zone.$path);
        $already=Redirect::inspect($proposal,$apex,$path,$apex,'HEAD');
        assert325($already['reason']==='ALREADY_CANONICAL_NO_REDIRECT' &&
            $already['candidateLocation']===null && !$already['mayExecute'],
            'apex cannot loop to www or to itself: '.$zone.$path);
    }

    // The operator origin has to be independent of the plan being inspected.
    // Forging both canonical and www aliases must not redirect or produce SEO.
    $forged=array_replace($proposal,[
        'canonicalOrigin'=>'https://invasor.com.br',
        'alternateOrigins'=>['https://www.invasor.com.br'],
    ]);
    assert325(denied325(static fn()=>Seo::draft($forged,['/'],$apex)),
        'forged SEO host and alias denied under pinned domain: '.$zone);
    $redirect=Redirect::inspect($forged,'https://www.invasor.com.br','/',$apex);
    assert325($redirect['reason']==='DENIED_DESTINATION_NOT_PINNED' &&
        $redirect['candidateLocation']===null && !$redirect['mayExecute'],
        'forged redirect host and alias denied under pinned domain: '.$zone);
    $wrongSource=Redirect::inspect($proposal,'https://www.invasor.com.br',
        '/',$apex);
    assert325($wrongSource['reason']==='DENIED_ORIGIN_NOT_IN_CONFIG' &&
        $wrongSource['candidateLocation']===null,
        'forged request source is not configured alternate: '.$zone);
}

foreach (['lp','campanha'] as $subdomain) {
    $proposal=Origins::propose('cliente.com.br','campaign_subdomain',$subdomain);
    $origin=$proposal['canonicalOrigin'];
    $seo=Seo::draft($proposal,['/','/oferta/'],$origin);
    assert325($seo['canonicalUrls']===[
        $origin.'/', $origin.'/oferta/'
    ] && !$seo['mayServe'],
        'campaign has its own canonical host and no www alias: '.$subdomain);
    $r=Redirect::inspect($proposal,'https://www.cliente.com.br',
        '/oferta/',$origin);
    assert325($r['reason']==='HOLD_UNAPPROVED_OR_NONPUBLIC_POLICY' &&
        $r['candidateLocation']===null && !$r['mayExecute'],
        'campaign never inherits public apex redirect policy: '.$subdomain);
}

$legacy=Origins::propose('cliente.com.br','legacy_preserved',null,
    'https://www.cliente.com.br');
$seo=Seo::draft($legacy,['/'],$legacy['canonicalOrigin']);
assert325($seo['canonicalUrls']===['https://www.cliente.com.br/'] &&
    !$seo['indexable'] && !$seo['mayServe'],
    'legacy www remains a hold, without implicit domain migration');
$r=Redirect::inspect($legacy,'https://www.cliente.com.br','/',
    'https://www.cliente.com.br');
assert325($r['reason']==='HOLD_UNAPPROVED_OR_NONPUBLIC_POLICY' &&
    !$r['mayExecute'],
    'legacy public name cannot be converted to 308 without human review');

echo "$checks F1-325 canonical origin, SEO and redirect parity checks passed; all NO-GO.\n";
