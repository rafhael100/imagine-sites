<?php

declare(strict_types=1);

/**
 * F1-366: synthetic Portal HTTP receipt source. No events are persisted
 * here; a signed receipt proves only that this CI fixture evaluated bytes.
 * NEVER treat this as Portal production receipt or delivery confirmation.
 */
function f366Reply(int $status,array $data):never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Imagine-Fixture: F1-366-portal-only');
    echo json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    exit;
}
if(PHP_SAPI!=='cli-server' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F366_PORTAL_RECEIPT_LAB')!=='1' ||
    ($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1' ||
    ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:18966') {
    f366Reply(404,['received'=>false,'reason'=>'unavailable']);
}
$path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
$method=(string)($_SERVER['REQUEST_METHOD']??'');
if($path==='/health' && $method==='GET') {
    f366Reply(200,['status'=>'synthetic-portal-only']);
}
if($path!=='/v1/lab/portal/receipt') {
    f366Reply(404,['received'=>false,'reason'=>'unknown_route']);
}
if($method!=='POST') {
    f366Reply(405,['received'=>false,'reason'=>'invalid_method']);
}
if(strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))
    !=='application/json') {
    f366Reply(415,['received'=>false,'reason'=>'content_type']);
}
$length=(string)($_SERVER['CONTENT_LENGTH']??'');
if(!ctype_digit($length) || (int)$length<2 || (int)$length>4096) {
    f366Reply(413,['received'=>false,'reason'=>'request_size']);
}
$raw=file_get_contents('php://input',false,null,0,4097);
if(!is_string($raw) || strlen($raw)!==(int)$length) {
    f366Reply(400,['received'=>false,'reason'=>'invalid_bytes']);
}
try{
    $envelope=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
}catch(JsonException) {
    f366Reply(400,['received'=>false,'reason'=>'invalid_json']);
}
$keys=['schemaVersion','tenantRef','operationRef','eventType',
    'commandDigest','leaseRef','sentAt','mac'];
if(!is_array($envelope) || array_keys($envelope)!==$keys ||
    ($envelope['schemaVersion']??null)!=='sites.synthetic.dispatch.v1' ||
    !is_string($envelope['tenantRef']) ||
    !is_string($envelope['operationRef']) ||
    !is_string($envelope['eventType']) ||
    $envelope['eventType']!=='ProjectQueued' ||
    !is_string($envelope['commandDigest']) ||
    !is_string($envelope['leaseRef']) ||
    !is_int($envelope['sentAt']) ||
    !is_string($envelope['mac']) ||
    !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$envelope['tenantRef']) ||
    !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$envelope['operationRef']) ||
    !preg_match('/\A[a-f0-9]{64}\z/D',$envelope['commandDigest']) ||
    !preg_match('/\A[a-f0-9-]{36}\z/D',$envelope['leaseRef']) ||
    !preg_match('/\A[a-f0-9]{64}\z/D',$envelope['mac']) ||
    abs(time()-$envelope['sentAt'])>10) {
    f366Reply(400,['received'=>false,'reason'=>'invalid_envelope']);
}
$unsigned=array_slice($envelope,0,-1,true);
$expected=hash_hmac('sha256',json_encode(
    $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
), 'F366-FICTIONAL-SITES-DISPATCH-KEY-00000000001');
if(!hash_equals($expected,$envelope['mac'])) {
    f366Reply(403,['received'=>false,'reason'=>'unauthenticated_event']);
}
// Explicit, synthetic fault injection. Never route this through an actual
// client: the fixture has no payment, contract check or durable Portal event.
$mode=(string)($_SERVER['HTTP_X_F366_LAB_MODE']??'normal');
if($mode==='reject') {
    f366Reply(503,['received'=>false,'reason'=>'temporary_unavailable']);
}
if(!in_array($mode,['normal','forged'],true)) {
    f366Reply(400,['received'=>false,'reason'=>'unknown_fixture_mode']);
}
$receipt=[
    'schemaVersion'=>'sites.synthetic.portal-receipt.v1',
    'tenantRef'=>$envelope['tenantRef'],
    'operationRef'=>$envelope['operationRef'],
    'eventType'=>$envelope['eventType'],
    'commandDigest'=>$envelope['commandDigest'],
    'leaseRef'=>$envelope['leaseRef'],
    'receivedAt'=>time(),
    'disposition'=>'observed_only',
];
$mac=hash_hmac('sha256',json_encode(
    $receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
),'F366-FICTIONAL-PORTAL-RECEIPT-KEY-0000000002');
$receipt['mac']=$mode==='forged'?str_repeat('0',64):$mac;
f366Reply(202,$receipt);
