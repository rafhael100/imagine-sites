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

// The expected destination is an independent operator/project fixture,
// never reconstructed from the mutable origin proposal being inspected.
function review288(array $proposal,string $source,string $target,
    string $method='GET',string $pinnedDestinationOrigin='https://cliente.com.br'):array {
    return Redirect::inspect($proposal,$source,$target,
        $pinnedDestinationOrigin,$method);
}

$site=Policy::propose('cliente.com.br','public_site');
$source='https://www.cliente.com.br';
foreach (['/','/servicos/','/sobre-nos','/produto_azul-2.html','/a~b/c-d'] as $path) {
    foreach(['GET','HEAD'] as $method) {
        $preview=review288($site,$source,$path,$method);
        check288($preview['reason']==='OFFLINE_REDIRECT_REVIEW_CANDIDATE' &&
            $preview['candidateLocation']==='https://cliente.com.br'.$path &&
            $preview['statusCandidate']===308 &&
            !$preview['mayExecute'] && !$preview['queryDiscarded'],
            'safe path in offline what-if only: '.$path.' '.$method);
    }
}
$utm=review288($site,$source,'/campanha/?utm_source=demo&email=private%40invalid');
check288($utm['candidateLocation']==='https://cliente.com.br/campanha/' &&
    $utm['queryDiscarded'] && !$utm['mayExecute'] &&
    !str_contains((string)$utm['candidateLocation'], 'email'),
    'strip all query parameters rather than leaking PII in Location');
foreach ([$source,'https://outro.com.br','https://cliente.com.br.evil.net',
          'http://www.cliente.com.br','https://www.cliente.com.br:443',
          'https://www.www.cliente.com.br','https://www.cliente.com.br/'] as $from) {
    if ($from===$source) continue;
    $review=review288($site,$from,'/ok');
    check288($review['reason']==='DENIED_ORIGIN_NOT_IN_CONFIG' &&
        $review['candidateLocation']===null && !$review['mayExecute'],
        'unknown source origin rejected: '.$from);
}
// F1-324: changing BOTH canonical host and its www alias used to look
// internally consistent. A pinned destination must still reject it.
$forged=array_replace($site,[
    'canonicalOrigin'=>'https://invasor.com.br',
    'alternateOrigins'=>['https://www.invasor.com.br'],
]);
$rogue=review288($forged,'https://www.invasor.com.br','/contato/');
check288($rogue['reason']==='DENIED_DESTINATION_NOT_PINNED' &&
    $rogue['candidateLocation']===null && !$rogue['mayExecute'],
    'forged target+alias cannot escape independently pinned project domain');
$wrongPin=review288($site,$source,'/contato/','GET',
    'https://www.cliente.com.br');
check288($wrongPin['reason']==='DENIED_DESTINATION_NOT_PINNED' &&
    $wrongPin['candidateLocation']===null && !$wrongPin['mayExecute'],
    'www cannot replace fixed public apex as redirect destination');
$missingPin=review288($site,$source,'/contato/','GET','');
check288($missingPin['reason']==='DENIED_DESTINATION_NOT_PINNED' &&
    !$missingPin['mayExecute'], 'missing pinned destination fails closed');

$already=review288($site,'https://cliente.com.br','/servicos/');
check288($already['reason']==='ALREADY_CANONICAL_NO_REDIRECT' &&
    $already['candidateLocation']===null &&
    $already['statusCandidate']===null && !$already['mayExecute'],
    'apex must not redirect to self');

foreach ([
    Policy::propose('cliente.com.br','campaign_subdomain','lp'),
    Policy::propose('cliente.com.br','application','portal'),
    Policy::propose('cliente.com.br','legacy_preserved',null,'https://www.cliente.com.br')
] as $p) {
    $review=review288($p,$source,'/contato');
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
    $review=review288(array_replace($site,$override),$source,'/');
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
    $review=review288($tampered,$source,'/ok');
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
    check288(denied288(static fn()=>review288($site,$source,$target)),
        'unsafe request target rejected: '.bin2hex($target));
}
foreach (['POST','PUT','PATCH','DELETE','OPTIONS','TRACE','GET '] as $method) {
    check288(denied288(static fn()=>review288($site,$source,'/ok',$method)),
        'only GET or HEAD evaluated: '.$method);
}
$veryLong='/'.str_repeat('a',2048);
check288(denied288(static fn()=>review288($site,$source,$veryLong)),
    'oversized request target rejected');
$twice=review288($site,$source,'/ofertas/?foo=1');
check288($twice===review288($site,$source,'/ofertas/?foo=1'),
    'deterministic output without side effects');
echo "$checks F1-288 safe offline redirect review assertions passed; no 30x served.\n";
