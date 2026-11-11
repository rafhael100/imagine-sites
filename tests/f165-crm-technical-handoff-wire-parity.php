<?php

declare(strict_types=1);

/**
 * F1-165: actual CRM F3-46 PHP source checks our versioned wire + public
 * fictional HMAC. This never sends a lead or grants a CRM operation.
 */
spl_autoload_register(static function(string $class):void {
    foreach([
        'Imagine\\Sites\\Integration\\'=>__DIR__.'/../src/Integration/',
        'App\\Core\\Leads\\'=>__DIR__.'/../crm-current/app/Core/Leads/',
    ] as $prefix=>$dir) {
        if (!str_starts_with($class,$prefix))continue;
        $basename=substr($class,strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$basename)) {
            throw new RuntimeException('Unsafe cross-repo fixture classname');
        }
        require_once $dir.$basename.'.php';
        return;
    }
});

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\SyntheticCrmTechnicalHandoffV1 as Handoff;
use App\Core\Leads\PortalServiceHandoffInspector as ActualCrmInspector;
use App\Core\Leads\PortalTechnicalEnvelopeDecoder as ActualDecoder;

$count=0;
function check165(bool $ok,string $reason):void {
    global $count;
    if(!$ok)throw new RuntimeException('F1-165 '.$reason);
    ++$count;
}
function deny165(callable $callback):bool {
    try{$callback();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
function zeroAuthority165(object $a):bool {
    return !$a->canAuthenticateService() && !$a->canAcceptLead() &&
      !$a->canPersistLead() && !$a->canAcknowledgeVisitor() &&
      !$a->canRetryUnknownOutcome() && !$a->canEnableRoute() &&
      !$a->canDeliverToCrm();
}
putenv('APP_ENV=testing');
putenv('SITES_F165_SYNTHETIC_AUTH=1');
$draft=new Draft(
 '11111111-1111-4111-8111-111111111111',
 '22222222-2222-4222-8222-222222222222',
 '33333333-3333-4333-8333-333333333333',
 '44444444-4444-4444-8444-444444444444',
 '55555555-5555-4555-8555-555555555555',
 '66666666-6666-4666-8666-666666666666',
);
$expected=$draft->metadata();
$pinned=[
 'organization_reference'=>$draft->organizationReference,
 'binding_reference'=>$draft->bindingReference,
 'site_reference'=>$draft->siteReference,
 'form_reference'=>$draft->formReference,
 'destination_reference'=>$draft->destinationReference,
];
$now=2000000000;
$issued=$now-15;
$expires=$now+120;
$nonce='77777777-7777-4777-8777-777777777777';
$make=static fn(string $op,int $issuedAt=$issued,int $expiresAt=$expires,
    string $once=$nonce):Handoff=>
    Handoff::draft($draft,$op,$issuedAt,$expiresAt,$now,$once);

$crm=new ActualCrmInspector();
$decoder=new ActualDecoder();
$built=[];
foreach(['deliver','lookup'] as $op){
    $wire=$make($op);
    check165($wire->operation===$op,
        'wire operation is local, never controlled by request JSON');
    check165($decoder->decode($wire->metadataJson)===$expected,
        'actual CRM strict technical decoder matches our exact eight fields');
    $auth=$wire->authentication;
    check165(array_keys($auth)===['issued_at','expires_at','nonce','mac'] &&
        $auth['issued_at']===$issued && $auth['expires_at']===$expires &&
        $auth['nonce']===$nonce &&
        preg_match('/\A[a-f0-9]{64}\z/D',$auth['mac'])===1,
        'synthetic auth is canonical with short lifetime and nonce');
    $assessment=$crm->inspect(
        $expected,$pinned,$auth,$now,Handoff::FIXTURE_KEY,$op
    );
    check165($assessment->reasonCode==='LAB_REQUEST_MAC_MATCH_UNTRUSTED' &&
        $assessment->isSyntacticallyConsistent() && zeroAuthority165($assessment),
        'actual CRM recognizes MAC but grants no authentication/lead/ACK');
    check165(!$wire->canAuthenticateService() && !$wire->canContactRealCrm()
        && !$wire->canAcceptLead() && !$wire->canAcknowledgeVisitor()
        && !$wire->canRetryAutomatically() && !$wire->canMarkDelivered(),
        'Sites does not expose operational grants either');
    check165($wire->metadataJson===$make($op)->metadataJson &&
        $wire->authentication===$make($op)->authentication,
        'CI wire is deterministic and not a secret authorization scheme');
    $built[$op]=$wire;
}
check165($built['deliver']->authentication['mac']!==
    $built['lookup']->authentication['mac'],
    'operation-specific HMAC domain prevents swapping deliver and lookup');
check165($crm->inspect(
    $expected,$pinned,$built['deliver']->authentication,
    $now,Handoff::FIXTURE_KEY,'lookup'
)->reasonCode==='AUTH_MAC_MISMATCH',
    'a deliver MAC can never serve a lookup operation');
check165($crm->inspect(
    $expected,array_replace($pinned,[
        'destination_reference'=>'99999999-9999-4999-8999-999999999999'
    ]),$built['deliver']->authentication,$now,Handoff::FIXTURE_KEY,'deliver'
)->reasonCode==='PINNED_CONTEXT_MISMATCH',
    'destination change must not silently reroute original submission');
$changed=array_replace($expected,[
    'submission_reference'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
]);
check165($crm->inspect(
    $changed,$pinned,$built['deliver']->authentication,
    $now,Handoff::FIXTURE_KEY,'deliver'
)->reasonCode==='AUTH_MAC_MISMATCH',
    'tampered submission ID changes actual CRM expected MAC');

$invalid=[
 ['delete',$issued,$expires,$nonce],
 ['deliver',$now+35,$expires,$nonce],
 ['deliver',$issued,$now+400,$nonce],
 ['deliver',$issued,$now,$nonce],
 ['deliver',0,$expires,$nonce],
 ['deliver',$issued,$expires,'77777777-7777-1777-8777-777777777777'],
 ['deliver',$issued,$expires,'77777777-7777-4777-0777-777777777777'],
 ['deliver',$issued,$expires,'77777777-7777-4777-8777-77777777777Z'],
 ['deliver',$issued,$expires,'00000000-0000-0000-0000-000000000000'],
];
foreach($invalid as [$op,$start,$end,$once]){
    check165(deny165(static fn()=>$make($op,$start,$end,$once)),
        'invalid operation/window/nonce never signs a technical request');
}
putenv('SITES_F165_SYNTHETIC_AUTH');
check165(deny165(static fn()=>$make('deliver')),
    'lab disabled without explicit synthetic-only switch');
putenv('SITES_F165_SYNTHETIC_AUTH=1');
putenv('APP_ENV=production');
check165(deny165(static fn()=>$make('deliver')),
    'production environment cannot construct a public-key test HMAC');
putenv('APP_ENV=testing');
check165($built['deliver']->canMarkDelivered()===false,
    'a previously constructed fixture never becomes a CRM receipt');

echo "$count F1-165 CRM F3-46 technical wire parity assertions passed. ".
    "No service credentials, independent binding, PII, HTTP, replay store or visitor ACK.\n";
