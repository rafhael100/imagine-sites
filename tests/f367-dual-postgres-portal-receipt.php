<?php

declare(strict_types=1);

/**
 * F1-367: real loopback HTTP to a *second* disposable PG18 Portal ledger.
 * Worker ACKs Sites outbox only if receipt is MAC-valid and the receiver
 * has committed a matching durable fictional event.
 */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F360_PG_LAB')!=='1' ||
    getenv('SITES_F361_OUTBOX_LAB')!=='1' ||
    getenv('SITES_F367_PORTAL_DB_LAB')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='sites_f360_lab') {
    fwrite(STDERR,"F1-367: refusing non-CI two-database fixture.\n");
    exit(1);
}
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$sitesDsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$portalDsn='pgsql:host=127.0.0.1;port=5433;dbname=portal_f367_lab;connect_timeout=5';
$sitesAdmin=new PDO($sitesDsn,'postgres','f360-fictional-admin-disposable-only',$opts);
$portalAdmin=new PDO($portalDsn,'postgres','f367-fictional-portal-db-only',$opts);
$worker=new PDO($sitesDsn,'sites_f361_worker','f361-fictional-outbox-worker-only',$opts);
$checks=0;
function f367Check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('F1-367 FAIL: '.$label);
    ++$checks;
}
function f367Count(PDO $db):int
{
    return (int)$db->query('SELECT count(*) FROM portal_f367.receipts')
        ->fetchColumn();
}
function f367Claim(PDO $worker):?array
{
    $stmt=$worker->query('SELECT * FROM sites_f360.claim_next_outbox(30)');
    $item=$stmt->fetch(PDO::FETCH_ASSOC);
    return $item===false?null:$item;
}
function f367Ack(PDO $worker,array $event):bool
{
    $stmt=$worker->prepare('SELECT sites_f360.ack_outbox(?,?,?::uuid)');
    $stmt->execute([$event['tenant_ref'],$event['operation_ref'],
       $event['lease_ref']]);
    return in_array($stmt->fetchColumn(),[true,'t','1',1],true);
}
function f367Expire(PDO $db,array $event):void
{
    $stmt=$db->prepare(
      "UPDATE sites_f360.outbox
          SET lease_expires_at=clock_timestamp()-interval '1 second'
        WHERE tenant_ref=? AND operation_ref=?
          AND lease_ref=?::uuid AND delivery_state='leased'"
    );
    $stmt->execute([$event['tenant_ref'],$event['operation_ref'],
        $event['lease_ref']]);
    f367Check($stmt->rowCount()===1,
        'fake Site administrator expired exact lease in disposable fixture');
}
function f367Envelope(array $event):array
{
    $msg=[
        'schemaVersion'=>'sites.synthetic.dispatch.v1',
        'tenantRef'=>$event['tenant_ref'],
        'operationRef'=>$event['operation_ref'],
        'eventType'=>$event['event_type'],
        'commandDigest'=>$event['command_digest'],
        'leaseRef'=>$event['lease_ref'],
        'sentAt'=>time(),
    ];
    $msg['mac']=hash_hmac('sha256',json_encode(
        $msg,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),'F366-FICTIONAL-SITES-DISPATCH-KEY-00000000001');
    return $msg;
}
function f367Send(array $message,string $mode='normal'):array
{
    $ch=curl_init('http://127.0.0.1:18968/v1/lab/portal/durable-receipt');
    if($ch===false)throw new RuntimeException('No loopback HTTP client.');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(
            $message,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
        ),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>6,
        CURLOPT_PROXY=>'',
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json','Accept: application/json',
            'X-F367-Lab-Mode: '.$mode,
        ],
    ]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if(!is_string($raw))return [0,null];
    try{$response=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){return [$status,null];}
    return [$status,is_array($response)?$response:null];
}
function f367ValidateReceipt(array $event,array $reply):bool
{
    [$status,$receipt]=$reply;
    $keys=['schemaVersion','tenantRef','operationRef','eventType',
        'commandDigest','leaseRef','receivedAt','disposition','mac'];
    if($status!==202 || !is_array($receipt) ||
        array_keys($receipt)!==$keys ||
        $receipt['schemaVersion']!=='sites.synthetic.durable-receipt.v1' ||
        $receipt['tenantRef']!==$event['tenant_ref'] ||
        $receipt['operationRef']!==$event['operation_ref'] ||
        $receipt['eventType']!==$event['event_type'] ||
        $receipt['commandDigest']!==$event['command_digest'] ||
        $receipt['leaseRef']!==$event['lease_ref'] ||
        !in_array($receipt['disposition'],['stored','already_recorded'],true) ||
        !is_int($receipt['receivedAt']) ||
        abs(time()-$receipt['receivedAt'])>10 ||
        !is_string($receipt['mac']) ||
        !preg_match('/\A[a-f0-9]{64}\z/D',$receipt['mac'])) {
        return false;
    }
    $unsigned=array_slice($receipt,0,-1,true);
    $expected=hash_hmac('sha256',json_encode(
        $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),'F367-FICTIONAL-PORTAL-DURABLE-RECEIPT-KEY-0001');
    return hash_equals($expected,$receipt['mac']);
}
function f367State(PDO $db,array $event):string
{
    $p=$db->prepare(
      'SELECT delivery_state FROM sites_f360.outbox
        WHERE tenant_ref=? AND operation_ref=?'
    );
    $p->execute([$event['tenant_ref'],$event['operation_ref']]);
    return (string)$p->fetchColumn();
}
$before=(int)$sitesAdmin->query(
    "SELECT count(*) FROM sites_f360.outbox
      WHERE delivery_state='delivered'"
)->fetchColumn();
f367Check(f367Count($portalAdmin)===0,
    'independent fictional Portal database starts without receipts');
f367Check((int)$sitesAdmin->query(
    "SELECT count(*) FROM sites_f360.outbox
      WHERE delivery_state='pending'"
)->fetchColumn()>=2,
    'fictive Sites outbox has at least two pending events');

$first=f367Claim($worker);
f367Check($first!==null,'Sites outbox worker claimed one event');
$failed=f367Send(f367Envelope($first),'abort');
f367Check($failed[0]===503 &&
    f367Count($portalAdmin)===0 &&
    f367State($sitesAdmin,$first)==='leased',
    'receiver DB insertion rolled back; Sites event not ACKed');
f367Expire($sitesAdmin,$first);
$retry=f367Claim($worker);
f367Check($retry!==null &&
    $retry['tenant_ref']===$first['tenant_ref'] &&
    $retry['operation_ref']===$first['operation_ref'] &&
    $retry['lease_ref']!==$first['lease_ref'],
    'failed delivery reclaimed under a fresh lease');

$receipt=f367Send(f367Envelope($retry));
f367Check(f367ValidateReceipt($retry,$receipt) &&
    ($receipt[1]['disposition']??'')==='stored',
    'separate Portal PG18 committed first receipt before signing response');
f367Check(f367Count($portalAdmin)===1,
    'first fictional Portal receipt exists in second PostgreSQL instance');
f367Check(!f367ValidateReceipt($first,$receipt),
    'receipt from retry cannot authorize old lease ACK');
f367Check(f367Ack($worker,$retry) &&
    f367State($sitesAdmin,$retry)==='delivered',
    'Sites outbox confirmed only after verified durable Portal receipt');

$duplicate=f367Envelope($retry);
$duplicate['leaseRef']='11111111-1111-4111-8111-111111111111';
$unsigned=array_slice($duplicate,0,-1,true);
$duplicate['mac']=hash_hmac('sha256',json_encode(
    $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
),'F366-FICTIONAL-SITES-DISPATCH-KEY-00000000001');
$duplicateReply=f367Send($duplicate);
f367Check($duplicateReply[0]===202 &&
    ($duplicateReply[1]['disposition']??'')==='already_recorded' &&
    f367Count($portalAdmin)===1,
    'receiver idempotently recognizes repeat event without another row');
f367Check(!f367Ack($worker,[
    'tenant_ref'=>$retry['tenant_ref'],
    'operation_ref'=>$retry['operation_ref'],
    'lease_ref'=>$duplicate['leaseRef']
]), 'unrelated duplicate HTTP token cannot ACK delivered Sites event');

$conflict=$duplicate;
$conflict['commandDigest']=hash('sha256','synthetic-conflicting-event');
$unsigned=array_slice($conflict,0,-1,true);
$conflict['mac']=hash_hmac('sha256',json_encode(
    $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
),'F366-FICTIONAL-SITES-DISPATCH-KEY-00000000001');
$conflictResponse=f367Send($conflict);
f367Check($conflictResponse[0]===409 &&
    f367Count($portalAdmin)===1,
    'Portal database refuses conflicting digest under same idempotency key');

$second=f367Claim($worker);
f367Check($second!==null &&
    ($second['tenant_ref']!==$retry['tenant_ref'] ||
     $second['operation_ref']!==$retry['operation_ref']),
    'distinct Sites event remains pending');
$secondReply=f367Send(f367Envelope($second));
f367Check(f367ValidateReceipt($second,$secondReply) &&
    f367Ack($worker,$second),
    'second event recorded in Portal PG18 before Sites SQL ACK');
f367Check(f367Count($portalAdmin)===2,
    'two independent fictional Portal receipts durably stored');
$after=(int)$sitesAdmin->query(
    "SELECT count(*) FROM sites_f360.outbox
      WHERE delivery_state='delivered'"
)->fetchColumn();
f367Check($after===$before+2,
    'exactly two additional Sites outbox events confirmed after Portal commit');
f367Check((int)$sitesAdmin->query(
    'SELECT count(*) FROM sites_f360.admissions a
       LEFT JOIN sites_f360.outbox o ON o.tenant_ref=a.tenant_ref
         AND o.operation_ref=a.operation_ref
     WHERE o.tenant_ref IS NULL'
)->fetchColumn()===0,'two independent PG ledgers did not orphan Sites admissions');

echo "$checks F1-367 separate PG18 Portal receipt/HTTP/Sites ACK checks passed.\n";
echo "NO-GO: separate CI PG containers are not real Portal IAM, independent production restore, TLS, official delivery or payment authorization.\n";
