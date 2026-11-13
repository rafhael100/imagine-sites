<?php

declare(strict_types=1);

/**
 * F1-368: dual-PG18 CI-only lost-response recovery. Two separate CLI
 * executions surround a real PHP HTTP receiver restart in GitHub Actions.
 * No real Portal, customers, billing, secrets, delivery or deployment.
 */
$phase=$argv[1]??'';
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
   getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F360_PG_LAB')!=='1' ||
   getenv('SITES_F361_OUTBOX_LAB')!=='1' ||
   getenv('SITES_F367_PORTAL_DB_LAB')!=='1' ||
   getenv('SITES_F368_RECOVERY_LAB')!=='1' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('DB_PORT')!=='5432' ||
   getenv('DB_DATABASE')!=='sites_f360_lab' ||
   getenv('DB_USERNAME')!=='postgres' ||
   getenv('DB_PASSWORD')!=='f360-fictional-admin-disposable-only' ||
   !in_array($phase,['lost-after-commit','recover-after-restart'],true)) {
    fwrite(STDERR,"F1-368: denied outside explicit disposable CI phase.\n");
    exit(1);
}
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$sitesDsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$portalDsn='pgsql:host=127.0.0.1;port=5433;dbname=portal_f367_lab;connect_timeout=5';
$sites=new PDO($sitesDsn,'postgres','f360-fictional-admin-disposable-only',$opts);
$portal=new PDO($portalDsn,'postgres','f367-fictional-portal-db-only',$opts);
$worker=new PDO($sitesDsn,'sites_f361_worker','f361-fictional-outbox-worker-only',$opts);
function f368Check(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('F1-368 FAIL: '.$label);
}
function f368Count(PDO $db):int {
    return (int)$db->query('SELECT count(*) FROM portal_f367.receipts')->fetchColumn();
}
function f368Claim(PDO $db):?array {
    $q=$db->query('SELECT * FROM sites_f360.claim_next_outbox(30)');
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row===false?null:$row;
}
function f368Ack(PDO $db,array $item):bool {
    $q=$db->prepare('SELECT sites_f360.ack_outbox(?,?,?::uuid)');
    $q->execute([$item['tenant_ref'],$item['operation_ref'],$item['lease_ref']]);
    return in_array($q->fetchColumn(),[true,'t','1',1],true);
}
function f368EventState(PDO $db,array $item):array {
    $q=$db->prepare(
       'SELECT delivery_state,delivery_attempts,lease_ref::text AS lease_ref
        FROM sites_f360.outbox WHERE tenant_ref=? AND operation_ref=?'
    );
    $q->execute([$item['tenant_ref'],$item['operation_ref']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('F1-368: missing outbox event.');
    return $row;
}
function f368Envelope(array $event):array {
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
function f368Post(array $msg,string $mode='normal'):array {
    $client=curl_init('http://127.0.0.1:18968/v1/lab/portal/durable-receipt');
    if($client===false)throw new RuntimeException('No loopback client.');
    curl_setopt_array($client,[
      CURLOPT_POST=>true,
      CURLOPT_POSTFIELDS=>json_encode($msg,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_FOLLOWLOCATION=>false,
      CURLOPT_CONNECTTIMEOUT=>2,
      CURLOPT_TIMEOUT=>5,
      CURLOPT_PROXY=>'',
      CURLOPT_HTTPHEADER=>[
        'Content-Type: application/json','Accept: application/json',
        'X-F367-Lab-Mode: '.$mode,
      ],
    ]);
    $raw=curl_exec($client);
    $code=(int)curl_getinfo($client,CURLINFO_HTTP_CODE);
    curl_close($client);
    if(!is_string($raw))return [0,null];
    try{$response=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){return [$code,null];}
    return [$code,is_array($response)?$response:null];
}
function f368ValidReceipt(array $claim,array $response):bool {
    [$status,$receipt]=$response;
    $keys=['schemaVersion','tenantRef','operationRef','eventType',
        'commandDigest','leaseRef','receivedAt','disposition','mac'];
    if($status!==202 || !is_array($receipt) ||
       array_keys($receipt)!==$keys ||
       $receipt['schemaVersion']!=='sites.synthetic.durable-receipt.v1' ||
       $receipt['tenantRef']!==$claim['tenant_ref'] ||
       $receipt['operationRef']!==$claim['operation_ref'] ||
       $receipt['eventType']!==$claim['event_type'] ||
       $receipt['commandDigest']!==$claim['command_digest'] ||
       $receipt['leaseRef']!==$claim['lease_ref'] ||
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
if($phase==='lost-after-commit') {
    f368Check(f368Count($portal)===2,
        'two previously committed Portal fixture receipts precede loss scenario');
    f368Check((int)$sites->query(
       "SELECT count(*) FROM sites_f360.outbox WHERE delivery_state='pending'"
    )->fetchColumn()>=1,'outbox has an independent event ready for lost response');
    $event=f368Claim($worker);
    f368Check($event!==null && $event['event_type']==='ProjectQueued',
        'worker leased a new fictional event');
    $answer=f368Post(f368Envelope($event),'lost_after_commit');
    f368Check($answer[0]===503 &&
        ($answer[1]['reason']??'')==='simulated_response_loss',
        'receiver returns a failed HTTP response AFTER database commit');
    f368Check(f368Count($portal)===3,
        'second independent PG18 ledger durably stored the event despite HTTP failure');
    $state=f368EventState($sites,$event);
    f368Check($state['delivery_state']==='leased' &&
        $state['lease_ref']===$event['lease_ref'],
        'Sites event remains leased, NOT ACKed after missing receipt');
    f368Check(!f368Ack($worker,[
        'tenant_ref'=>$event['tenant_ref'],
        'operation_ref'=>$event['operation_ref'],
        'lease_ref'=>'00000000-0000-0000-0000-000000000000'
    ]),'wrong token cannot bypass lost response');
    echo "F1-368 phase 1 PASS: Portal PG18 committed; Sites did not ACK absent receipt.\n";
    exit(0);
}

// A fresh CLI process now runs after the router process has been killed and
// restarted. Discovery uses persisted DB state exclusively; no local file
// carries an operation ID, nonce, token or receipt between phases.
f368Check(f368Count($portal)===3,
    'Portal durable receipt survives HTTP process restart');
$q=$portal->query(
    'SELECT tenant_ref,operation_ref,event_type,command_digest
       FROM portal_f367.receipts ORDER BY tenant_ref,operation_ref'
);
$leased=[];
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $state=f368EventState($sites,$row);
    if($state['delivery_state']==='leased') {
        $leased[]=['row'=>$row,'state'=>$state];
    }
}
f368Check(count($leased)===1,
    'exactly one Portal-persisted event remains unacknowledged in Sites');
$stored=$leased[0]['row'];
$original=$leased[0]['state'];
f368Check($original['lease_ref']!==null &&
    (int)$original['delivery_attempts']>=1,
    'lost-response transaction has an extant first lease');
// Only fixture admin may accelerate lease expiry; the worker role cannot.
$expire=$sites->prepare(
    "UPDATE sites_f360.outbox
        SET lease_expires_at=clock_timestamp()-interval '1 second'
      WHERE tenant_ref=? AND operation_ref=? AND lease_ref=?::uuid
        AND delivery_state='leased'"
);
$expire->execute([
    $stored['tenant_ref'],$stored['operation_ref'],$original['lease_ref']
]);
f368Check($expire->rowCount()===1,
    'CI administrator expires only the lost-response lease');
$reclaimed=f368Claim($worker);
f368Check($reclaimed!==null &&
    $reclaimed['tenant_ref']===$stored['tenant_ref'] &&
    $reclaimed['operation_ref']===$stored['operation_ref'] &&
    $reclaimed['command_digest']===$stored['command_digest'] &&
    $reclaimed['lease_ref']!==$original['lease_ref'] &&
    (int)$reclaimed['delivery_attempts']===(int)$original['delivery_attempts']+1,
    'worker reclaims same persisted event using a new token after receiver restart');
$retry=f368Post(f368Envelope($reclaimed));
f368Check(f368ValidReceipt($reclaimed,$retry) &&
    ($retry[1]['disposition']??'')==='already_recorded',
    'restarted receiver returns signed idempotent receipt for previously committed event');
f368Check(f368Count($portal)===3,
    'retransmission after lost response did not create a second Portal receipt');
f368Check(!f368Ack($worker,[
   'tenant_ref'=>$stored['tenant_ref'],
   'operation_ref'=>$stored['operation_ref'],
   'lease_ref'=>$original['lease_ref']
]),'previous lease token cannot acknowledge a recovered event');
f368Check(f368Ack($worker,$reclaimed),
    'Sites ACK succeeds only with the signed receipt from restarted receiver');
f368Check(f368EventState($sites,$reclaimed)['delivery_state']==='delivered',
    'Sites event marked delivered only after successful signed recovery');
f368Check(!f368Ack($worker,$reclaimed),
    'duplicate ACK refused by Sites database');
f368Check((int)$sites->query(
    'SELECT count(*) FROM sites_f360.admissions a
     LEFT JOIN sites_f360.outbox o
       ON o.tenant_ref=a.tenant_ref AND o.operation_ref=a.operation_ref
     WHERE o.tenant_ref IS NULL'
)->fetchColumn()===0,'no admissions orphaned by response loss and restart');
echo "F1-368 phase 2 PASS: restart + signed idempotent Portal receipt + Sites ACK.\n";
echo "NO-GO: two disposable CI databases, fictional HMAC keys and no actual Portal service.\n";
