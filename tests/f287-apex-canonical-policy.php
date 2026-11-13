<?php

declare(strict_types=1);

/** F1-287: static host-policy candidates. No DNS, browser, sockets or data. */
require __DIR__.'/../src/Domain/SyntheticCanonicalOriginPlanV1.php';

use Imagine\Sites\Domain\SyntheticCanonicalOriginPlanV1 as Policy;

$checks = 0;
function check287(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException('F1-287 '.$name);
    ++$checks;
}
function denied287(callable $fn): bool {
    try { $fn(); } catch (InvalidArgumentException) { return true; }
    return false;
}

$apex=Policy::propose('imaginecomunicacao.com.br', 'public_site');
check287($apex['canonicalOrigin']==='https://imaginecomunicacao.com.br' &&
    $apex['alternateOrigins']===['https://www.imaginecomunicacao.com.br'],
    'new public site uses HTTPS apex, www only proposed as alternate');
check287($apex['hostClass']==='public_site' &&
    $apex['approvalState']==='CANDIDATE_UNVERIFIED' &&
    !$apex['mayRedirect'] && !$apex['mayPublish'],
    'apex never grants redirect or publication');

foreach (['lp','campanha'] as $name) {
    $campaign=Policy::propose('cliente.com.br', 'campaign_subdomain', $name);
    check287($campaign['canonicalOrigin']==='https://'.$name.'.cliente.com.br' &&
        $campaign['alternateOrigins']===[] && !$campaign['mayRedirect'],
        'campaign is independent without www: '.$name);
}
foreach (['portal','crm','api','dev','performance','universidade','agents'] as $name) {
    $app=Policy::propose('imaginecomunicacao.com.br','application',$name);
    check287($app['canonicalOrigin']==='https://'.$name.'.imaginecomunicacao.com.br' &&
        $app['alternateOrigins']===[] && !$app['mayPublish'],
        'app isolated on fixed host: '.$name);
}
foreach ([
    'https://www.imaginecomunicacao.com.br',
    'https://imaginecomunicacao.com.br',
    'https://lp.imaginecomunicacao.com.br'
] as $origin) {
    $legacy=Policy::propose('imaginecomunicacao.com.br', 'legacy_preserved', null, $origin);
    check287($legacy['canonicalOrigin']===$origin &&
        $legacy['approvalState']==='LEGACY_HOLD' &&
        $legacy['alternateOrigins']===[] &&
        !$legacy['mayRedirect'] && !$legacy['mayPublish'],
        'historical canonical preserved without migration: '.$origin);
}
// F1-322: do not reclassify platform/campaign hosts as a public apex.
foreach (['www','portal','crm','api','dev','performance','universidade',
    'agents','campanha','lp'] as $reserved) {
    check287(denied287(static fn()=>Policy::propose(
        $reserved.'.imaginecomunicacao.com.br','public_site')),
        'reserved host requires its explicit service class: '.$reserved);
}
foreach (['com.br','net.br','org.br','gov.br','edu.br','mil.br',
    'co.uk','org.uk','ac.uk','gov.uk','net.uk'] as $suffix) {
    check287(denied287(static fn()=>Policy::propose($suffix,'public_site')),
        'public suffix cannot be a customer-owned base domain: '.$suffix);
}
foreach (['cliente.co.uk','cliente.com.br','cliente.org.br'] as $registeredCandidate) {
    $candidate=Policy::propose($registeredCandidate,'public_site');
    check287($candidate['canonicalOrigin']==='https://'.$registeredCandidate &&
        $candidate['alternateOrigins']===['https://www.'.$registeredCandidate] &&
        !$candidate['mayPublish'] && !$candidate['mayRedirect'],
        'valid-looking registered zone remains a non-authoritative proposal');
}
$invalidDomains=[
    '', 'www.imagine.com.br', 'portal.imagine.com.br', 'UPPER.com.br',
    'imagine.com.br.', '.imagine.com.br', 'imagine..com.br',
    'imagine-.com.br', '-imagine.com.br', 'imagine_com.br',
    'https://imagine.com.br', 'imagine.com.br/path', 'user@imagine.com.br',
    'imagine.com.br:443', 'imagine.com.br?x=1', '127.0.0.1',
    '[::1]', 'localhost', "imagine.com.br\nHost: evil.com",
    'imaginé.com.br', 'xn--falso.com.br', 'imagine.c', 'imagine.123'
];
foreach ($invalidDomains as $domain) {
    check287(denied287(static fn()=>Policy::propose($domain,'public_site')),
        'reject unsafe base domain: '.bin2hex($domain));
}
foreach (['unknown','PUBLIC_SITE','redirect','legacy_preserved'] as $class) {
    check287(denied287(static fn()=>Policy::propose('cliente.com.br',$class)),
        'unsupported or unverified host class: '.$class);
}
foreach ([
    ['public_site','www'], ['public_site',null,'https://cliente.com.br'],
    ['campaign_subdomain','www'], ['campaign_subdomain','lp','https://lp.cliente.com.br'],
    ['application','www'], ['application','tenant'], ['application',null],
    ['legacy_preserved','lp','https://lp.cliente.com.br'],
] as $row) {
    check287(denied287(static fn()=>Policy::propose('cliente.com.br',$row[0],$row[1],$row[2]??null)),
        'reject unexpected class arguments: '.json_encode($row));
}
foreach ([
    'http://cliente.com.br', 'https://evil-cliente.com.br',
    'https://cliente.com.br.evil.net', 'https://outro.com.br',
    'https://cliente.com.br/path', 'https://cliente.com.br:443',
    'https://user@cliente.com.br', 'https://www.www.cliente.com.br',
    'https://*.cliente.com.br', "https://cliente.com.br\r\n"
] as $badOrigin) {
    check287(denied287(static fn()=>Policy::propose('cliente.com.br','legacy_preserved',null,$badOrigin)),
        'legacy must be exact HTTPS origin inside domain: '.bin2hex($badOrigin));
}
check287(Policy::propose('cliente.com.br','public_site')===
    Policy::propose('cliente.com.br','public_site'),
    'deterministic policy candidates');
echo "$checks F1-287 apex-first canonical-policy assertions passed; non-operational.\n";
