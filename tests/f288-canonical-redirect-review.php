<?php

declare(strict_types=1);

/** F1-288 offline-only HTTPS redirect review: no outbound I/O or real routes. */
require __DIR__.'/../src/Domain/SyntheticCanonicalOriginPlanV1.php';
require __DIR__.'/../src/Domain/SyntheticCanonicalRedirectReviewV1.php';

use Imagine\Sites\Domain\SyntheticCanonicalOriginPlanV1 as Policy;
use Imagine\Sites\Domain\SyntheticCanonicalRedirectReviewV1 as Redirect;

$checks=0;
function check288(bool $ok, string $name):void {
    global $checks;
    if (!$ok) throw new RuntimeException('F1-288 '.$name);
    ++$checks;
}
function denied288(callable $fn):bool {
    try {$fn();}catch(InvalidArgumentException){return true;}
    return false;
}

$site=Policy::propose('cliente.com.br','public_site');
$source='https://www.cliente.com.br';
foreach (['/','/servicos/','/sobre-nos','/produto_azul-2.html','/a~b/c-d'] as $path) {
    foreach(['GET','HEAD'] as $method) {
        $preview=Redirect::inspect($site,$source,$path,$method);
        check288($preview['reason']==='OFFLINE_REDIRECT_REVIEW_CANDIDATE' &&
            $preview['candidateLocation']==='https://cliente.com.br'.$path &&
            $preview['statusCandidate']===308 &&
            !$preview['mayExecute'] && !$preview['queryDiscarded'],
            'safe path in offline what-if only: '.$path.' '.$method);
    }
}
$utm=Redirect::inspect($site,$source,'/campanha/?utm_source=demo&email=private%40invalid');
check288($utm['candidateLocation']==='https://cliente.com.br/campanha/' &&
    $utm['queryDiscarded'] && !$utm['mayExecute'] &&
    !str_contains((string)$utm['candidateLocation'], 'email'),
    'strip all query parameters rather than leaking PII in Location');
foreach ([$source,'https://outro.com.br','https://cliente.com.br.evil.net',
          'http://www.cliente.com.br','https://www.cliente.com.br:443',
          'https://www.www.cliente.com.br','https://www.cliente.com.br/'] as $from) {
    if ($from===$source) continue;
    $review=Redirect::inspect($site,$from,'/ok');
    check288($review['reason']==='DENIED_ORIGIN_NOT_IN_CONFIG' &&
        $review['candidateLocation']===null && !$review['mayExecute'],
        'unknown source origin rejected: '.$from);
}
$already=Redirect::inspect($site,'https://cliente.com.br','/servicos/');
check288($already['reason']==='ALREADY_CANONICAL_NO_REDIRECT' &&
    $already['candidateLocation']===null &&
    $already['statusCandidate']===null && !$already['mayExecute'],
    'apex must not redirect to self');

foreach ([
    Policy::propose('cliente.com.br','campaign_subdomain','lp'),
    Policy::propose('cliente.com.br','application','portal'),
    Policy::propose('cliente.com.br','legacy_preserved',null,'https://www.cliente.com.br')
] as $p) {
    $review=Redirect::inspect($p,$source,'/contato');
    check288($review['reason']==='HOLD_UNAPPROVED_OR_NONPUBLIC_POLICY' &&
        $review['candidateLocation']===null && !$review['mayExecute'],
        'non-public or legacy configuration remains HOLD');
}
foreach ([
    ['mayRedirect'=>true],
    ['mayPublish'=>true],
    ['approvalState'=>'PRODUCTION'],
    ['hostClass'=>'application']
] as $override) {
    $review=Redirect::inspect(array_replace($site,$override),$source,'/');
    check288($review['reason']==='HOLD_UNAPPROVED_OR_NONPUBLIC_POLICY' &&
        !$review['mayExecute'], 'cannot turn a candidate into authority by flags');
}
foreach ([
    ['canonicalOrigin'=>'https://other.com.br'],
    ['canonicalOrigin'=>'https://www.cliente.com.br'],
    ['canonicalOrigin'=>'http://cliente.com.br'],
    ['canonicalOrigin'=>'https://cliente.com.br:443'],
    ['canonicalOrigin'=>'https://cliente.com.br.evil.net'],
    ['alternateOrigins'=>['https://outro.com.br']],
    ['alternateOrigins'=>['https://www.cliente.com.br','https://other.com.br']],
    ['alternateOrigins'=>[]],
    ['alternateOrigins'=>'https://www.cliente.com.br']
] as $override) {
    $tampered=array_replace($site,$override);
    $review=Redirect::inspect($tampered,$source,'/ok');
    check288(!$review['mayExecute'] &&
        $review['candidateLocation']===null,
        'reject invalid/tampered origin proposal');
}
foreach ([
    '','abc','//evil.com','///evil.com','/a//b','/../admin',
    '/./admin','/a/../admin','/.git','/.well-known',
    '/a/.hidden','/a/%2e%2e','/a/%252e',
    '/a/%2f%2fevil.com','/a%3fb','/a%40b','/a?x=1#fragment',
    "https://attacker.example/",'http://attacker.example/',
    '/a\\b', "/a\r\nLocation: https://evil.com", '/á',
    '/a b','/a;evil','/a:443','/a@evil'
] as $target) {
    check288(denied288(static fn()=>Redirect::inspect($site,$source,$target)),
        'unsafe request target rejected: '.bin2hex($target));
}
foreach (['POST','PUT','PATCH','DELETE','OPTIONS','TRACE','GET '] as $method) {
    check288(denied288(static fn()=>Redirect::inspect($site,$source,'/ok',$method)),
        'only GET or HEAD evaluated: '.$method);
}
$veryLong='/'.str_repeat('a',2048);
check288(denied288(static fn()=>Redirect::inspect($site,$source,$veryLong)),
    'oversized request target rejected');
$twice=Redirect::inspect($site,$source,'/ofertas/?foo=1');
check288($twice===Redirect::inspect($site,$source,'/ofertas/?foo=1'),
    'deterministic output without side effects');
echo "$checks F1-288 safe offline redirect review assertions passed; no 30x served.\n";
