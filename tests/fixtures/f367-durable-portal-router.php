<?php

declare(strict_types=1);

/**
 * F1-367: HTTP receiver on loopback backed by a SECOND disposable PG18.
 * Receipt signatures are emitted only AFTER the fictional database commits.
 * No actual Portal instance or customer is involved.
 */
function f367Reply(int $status,array $data):never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Imagine-Fixture: F1-367-portal-db-only');
    echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    exit;
}
if(PHP_SAPI!=='cli-server' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F367_PORTAL_DB_LAB')!=='1' ||
    ($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1' ||
    ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:18968') {
    f367Reply(404,['stored'=>false,'reason'=>'unavailable']);
}
$method=(string)($_SERVER['REQUEST_METHOD']??'');
$path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
if($method==='GET' && $path==='/health') {
    f367Reply(200,['status'=>'fictional-persistent-portal']);
}
if($path!=='/v1/lab/portal/durable-receipt') {
    f367Reply(404,['stored'=>false,'reason'=>'not_found']);
}
if($method!=='POST')f367Reply(405,['stored'=>false,'reason'=>'method']);
if(strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))
    !=='application/json') {
    f367Reply(415,['stored'=>false,'reason'=>'content_type']);
}
$len=(string)($_SERVER['CONTENT_LENGTH']??'');
if(!ctype_digit($len) || (int)$len<2 || (int)$len>4096) {
    f367Reply(413,['stored'=>false,'reason'=>'size']);
}
$raw=file_get_contents('php://input',false,null,0,4097);
if(!is_string($raw) || strlen($raw)!==(int)$len) {
    f367Reply(400,['stored'=>false,'reason'=>'body']);
}
try {$msg=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
catch(JsonException) {f367Reply(400,['stored'=>false,'reason'=>'json']);}
$keys=['schemaVersion','tenantRef','operationRef','eventType',
    'commandDigest','leaseRef','sentAt','mac'];
if(!is_array($msg) || array_keys($msg)!==$keys ||
    $msg['schemaVersion']!=='sites.synthetic.dispatch.v1' ||
    !is_string($msg['tenantRef']) ||
    !is_string($msg['operationRef']) ||
    $msg['eventType']!=='ProjectQueued' ||
    !is_string($msg['commandDigest']) ||
    !is_string($msg['leaseRef']) ||
    !is_int($msg['sentAt']) ||
    !is_string($msg['mac']) ||
    !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$msg['tenantRef']) ||
    !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$msg['operationRef']) ||
    !preg_match('/\A[a-f0-9]{64}\z/D',$msg['commandDigest']) ||
    !preg_match('/\A[a-f0-9-]{36}\z/D',$msg['leaseRef']) ||
    !preg_match('/\A[a-f0-9]{64}\z/D',$msg['mac']) ||
    abs(time()-$msg['sentAt'])>10) {
    f367Reply(400,['stored'=>false,'reason'=>'invalid_envelope']);
}
$unsigned=array_slice($msg,0,-1,true);
$expected=hash_hmac('sha256',json_encode(
    $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
),'F366-FICTIONAL-SITES-DISPATCH-KEY-00000000001');
if(!hash_equals($expected,$msg['mac'])) {
    f367Reply(403,['stored'=>false,'reason'=>'unauthenticated']);
}
try {
    // F1-369 CI-only failover: receiver may connect to the separately
    // restored third PostgreSQL18 service. Explicit env gates prevent an
    // arbitrary host/database override; this is NOT service discovery.
    $restoreMode=getenv('SITES_F369_RESTORED_RECEIVER_LAB')==='1';
    if($restoreMode && getenv('SITES_F369_RESTORE_LAB')!=='1') {
        f367Reply(503,['stored'=>false,'reason'=>'restore_not_authorized']);
    }
    $dsn=$restoreMode
        ? 'pgsql:host=127.0.0.1;port=5434;dbname=portal_f369_restore_lab;connect_timeout=5'
        : 'pgsql:host=127.0.0.1;port=5433;dbname=portal_f367_lab;connect_timeout=5';
    $password=$restoreMode
        ? 'f369-fictional-restore-db-only'
        : 'f367-fictional-portal-db-only';
    $pdo=new PDO(
        $dsn,'postgres',$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6]
    );
    $pdo->beginTransaction();
    $insert=$pdo->prepare(
      'INSERT INTO portal_f367.receipts
        (tenant_ref,operation_ref,event_type,command_digest)
       VALUES (?,?,?,?) ON CONFLICT(tenant_ref,operation_ref) DO NOTHING'
    );
    $insert->execute([$msg['tenantRef'],$msg['operationRef'],
        $msg['eventType'],$msg['commandDigest']]);
    $added=$insert->rowCount()===1;
    $read=$pdo->prepare(
      'SELECT event_type,command_digest FROM portal_f367.receipts
       WHERE tenant_ref=? AND operation_ref=? FOR SHARE'
    );
    $read->execute([$msg['tenantRef'],$msg['operationRef']]);
    $existing=$read->fetch(PDO::FETCH_ASSOC);
    if(!is_array($existing) ||
       $existing['event_type']!==$msg['eventType'] ||
       $existing['command_digest']!==$msg['commandDigest']) {
        $pdo->rollBack();
        f367Reply(409,['stored'=>false,'reason'=>'conflicting_event']);
    }
    // Simulates crash between insertion and COMMIT: the row MUST roll back.
    if(($_SERVER['HTTP_X_F367_LAB_MODE']??'normal')==='abort') {
        $pdo->rollBack();
        f367Reply(503,['stored'=>false,'reason'=>'rolled_back']);
    }
    $pdo->commit();
    // F1-368: simulated lost HTTP response AFTER a committed receipt.
    // Only the disposable CI fixture may trigger this mode; no ACK is
    // returned despite durable Portal storage.
    if(($_SERVER['HTTP_X_F367_LAB_MODE']??'normal')==='lost_after_commit') {
        f367Reply(503,['stored'=>false,'reason'=>'simulated_response_loss']);
    }
} catch(Throwable) {
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    f367Reply(503,['stored'=>false,'reason'=>'database_unavailable']);
}
$receipt=[
    'schemaVersion'=>'sites.synthetic.durable-receipt.v1',
    'tenantRef'=>$msg['tenantRef'],
    'operationRef'=>$msg['operationRef'],
    'eventType'=>$msg['eventType'],
    'commandDigest'=>$msg['commandDigest'],
    'leaseRef'=>$msg['leaseRef'],
    'receivedAt'=>time(),
    'disposition'=>$added?'stored':'already_recorded',
];
$receipt['mac']=hash_hmac('sha256',json_encode(
    $receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
),'F367-FICTIONAL-PORTAL-DURABLE-RECEIPT-KEY-0001');
f367Reply(202,$receipt);
