<?php

declare(strict_types=1);

require_once __DIR__.'/../src/Integration/SyntheticCrmCaptureEnvelope.php';
use Imagine\Sites\Integration\SyntheticCrmCaptureEnvelope as E;

function ensure398(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('F398 failed: '.$label);
    echo 'PASS '.$label."\n";
}
function denies398(callable $f): bool
{
    try { $f(); } catch (InvalidArgumentException|LogicException) { return true; }
    return false;
}
$now=1790000000;
$input=[
    'organizationReference'=>'A1111111-1111-4111-8111-111111111111',
    'bindingReference'=>'a2222222-2222-4222-8222-222222222222',
    'submissionReference'=>'a3333333-3333-4333-8333-333333333333',
    'siteReference'=>'a4444444-4444-4444-8444-444444444444',
    'formReference'=>'a5555555-5555-4555-8555-555555555555',
    'destinationReference'=>'a6666666-6666-4666-8666-666666666666',
    'leadId'=>'a7777777-7777-4777-8777-777777777777',
    'syntheticTitle'=>'DEMO_F398_01',
    'sourceCode'=>'onepage',
    'nonce'=>'a8888888-8888-4888-8888-888888888888',
    'issuedAt'=>$now-10,
    'expiresAt'=>$now+40,
];
$payload=E::sign($input,$now);
ensure398(strlen($payload['signature'])===64,'explicit fake CI HMAC');
ensure398($payload['organizationReference']===strtolower($input['organizationReference']),'normalized tenant');
ensure398($payload['signature']===hash_hmac('sha256', E::canonical($payload), E::CI_KEY),'canonical matches MAC');
ensure398(E::sign($input,$now)===$payload,'retry emits identical bytes');
foreach (['organizationReference','bindingReference','submissionReference','siteReference','formReference','destinationReference','leadId','syntheticTitle','sourceCode','nonce','issuedAt','expiresAt'] as $field) {
    $tampered=$payload;
    $tampered[$field]=is_int($tampered[$field]) ? $tampered[$field]+1 : ($field==='sourceCode'?'landing':($field==='syntheticTitle'?'DEMO_F398_02':'b1111111-1111-4111-8111-111111111111'));
    ensure398(!hash_equals($payload['signature'],hash_hmac('sha256',E::canonical($tampered),E::CI_KEY)),'tampering invalidates '.$field);
}
$bad=$input;$bad['expiresAt']=$now;
ensure398(denies398(fn()=>E::sign($bad,$now)),'expired message refused');
$bad=$input;$bad['expiresAt']=$now+301;
ensure398(denies398(fn()=>E::sign($bad,$now)),'TTL over five minutes refused');
$bad=$input;$bad['issuedAt']=$now+31;
ensure398(denies398(fn()=>E::sign($bad,$now)),'future message refused');
$bad=$input;$bad['organizationReference']='00000000-0000-0000-0000-000000000000';
ensure398(denies398(fn()=>E::sign($bad,$now)),'zero tenant refused');
$bad=$input;$bad['syntheticTitle']='REAL CUSTOMER NAME';
ensure398(denies398(fn()=>E::sign($bad,$now)),'free-text content refused');
$bad=$input;$bad['sourceCode']='unknown';
ensure398(denies398(fn()=>E::sign($bad,$now)),'unknown origin refused');
$bad=$input;$bad['untrusted']=true;
ensure398(denies398(fn()=>E::sign($bad,$now)),'extra field refused');
$bad=$input;unset($bad['formReference']);$bad['formReference']=$input['formReference'];
ensure398(denies398(fn()=>E::sign($bad,$now)),'reordered signed fields refused');
putenv('SITES_F398_CRM_CAPTURE_CI=0');
ensure398(denies398(fn()=>E::sign($input,$now)),'unsafe CI flag denies');
echo "F398 CI-only Sites emitter finished; NO API, lead or customer provisioned.\n";
