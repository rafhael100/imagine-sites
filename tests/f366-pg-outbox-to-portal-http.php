<?php

declare(strict_types=1);

/**
 * F1-366: separate CLI outbox worker -> real local HTTP -> mock signed
 * Portal receipt -> PostgreSQL ACK only after independent MAC validation.
 * CI-only with synthetic events and credentials. Not a live dispatcher.
 */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F360_PG_LAB')!=='1' ||
    getenv('SITES_F361_OUTBOX_LAB')!=='1' ||
    getenv('SITES_F366_PORTAL_RECEIPT_LAB')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_PORT')!=='5432' ||
    getenv('DB_DATABASE')!=='sites_f360_lab' ||
    getenv('DB_USERNAME')!=='postgres' ||
    getenv('DB_PASSWORD')!=='f360-fictional-admin-disposable-only') {
    fwrite(STDERR,"F1-366: refusing to run outside disposable CI.\n");
    exit(1);
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$admin=new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',$options);
$w1=new PDO($dsn,'sites_f361_worker','f361-fictional-outbox-worker-only',$options);
$w2=new PDO($dsn,'sites_f361_worker','f361-fictional-outbox-worker-only',$options);
$checks=0;
function f366Check(bool $ok,string $description):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('F1-366 FAIL: '.$description);
    ++$checks;
}
function f366Claim(PDO $db):?array
{
    $p=$db->query('SELECT * FROM sites_f360.claim_next_outbox(30)');
    $row=$p->fetch(PDO::FETCH_ASSOC);
    return $row===false?null:$row;
}
function f366Ack(PDO $db,array $claim):bool
{
    $p=$db->prepare('SELECT sites_f360.ack_outbox(?,?,?::uuid)');
    $p->execute([
        $claim['tenant_ref'],$claim['operation_ref'],$claim['lease_ref']
    ]);
    return in_array($p->fetchColumn(),[true,'t','1',1],true);
}
function f366Expire(PDO $admin,array $claim):void
{
    $q=$admin->prepare(
      "UPDATE sites_f360.outbox
          SET lease_expires_at=clock_timestamp()-interval '1 second'
        WHERE tenant_ref=? AND operation_ref=?
          AND lease_ref=?::uuid AND delivery_state='leased'"
    );
    $q->execute([
      $claim['tenant_ref'],$claim['operation_ref'],$claim['lease_ref']
    ]);
    f366Check($q->rowCount()===1,
        'CI administrator expires only exact synthetic lease token');
}
function f366Envelope(array $claim):array
{
    $payload=[
        'schemaVersion'=>'sites.synthetic.dispatch.v1',
        'tenantRef'=>$claim['tenant_ref'],
        'operationRef'=>$claim['operation_ref'],
        'eventType'=>$claim['event_type'],
        'commandDigest'=>$claim['command_digest'],
        'leaseRef'=>$claim['lease_ref'],
        'sentAt'=>time(),
    ];
    $payload['mac']=hash_hmac('sha256',json_encode(
        $payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),'F366-FICTIONAL-SITES-DISPATCH-KEY-00000000001');
    return $payload;
}
function f366Send(array $envelope,string $mode='normal',int $port=18966):array
{
    $ch=curl_init('http://127.0.0.1:'.$port.'/v1/lab/portal/receipt');
    if($ch===false)throw new RuntimeException('Could not create curl client.');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($envelope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>1,
        CURLOPT_TIMEOUT=>3,
        CURLOPT_PROXY=>'',
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json','Accept: application/json',
            'X-F366-Lab-Mode: '.$mode,
        ],
    ]);
    $raw=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if(!is_string($raw))return [0,null];
    try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){return [$code,null];}
    return [$code,is_array($data)?$data:null];
}
function f366Verify(array $claim,array $response):bool
{
    [$status,$receipt]=$response;
    $keys=['schemaVersion','tenantRef','operationRef','eventType',
      'commandDigest','leaseRef','receivedAt','disposition','mac'];
    if($status!==202 || !is_array($receipt) ||
        array_keys($receipt)!==$keys ||
        $receipt['schemaVersion']!=='sites.synthetic.portal-receipt.v1' ||
        $receipt['tenantRef']!==$claim['tenant_ref'] ||
        $receipt['operationRef']!==$claim['operation_ref'] ||
        $receipt['eventType']!==$claim['event_type'] ||
        $receipt['commandDigest']!==$claim['command_digest'] ||
        $receipt['leaseRef']!==$claim['lease_ref'] ||
        $receipt['disposition']!=='observed_only' ||
        !is_int($receipt['receivedAt']) ||
        abs(time()-$receipt['receivedAt'])>10 ||
        !is_string($receipt['mac']) ||
        !preg_match('/\A[a-f0-9]{64}\z/D',$receipt['mac'])) {
        return false;
    }
    $unsigned=array_slice($receipt,0,-1,true);
    $expected=hash_hmac('sha256',json_encode(
        $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),'F366-FICTIONAL-PORTAL-RECEIPT-KEY-0000000002');
    return hash_equals($expected,$receipt['mac']);
}
function f366State(PDO $admin,array $claim):string
{
    $q=$admin->prepare(
      'SELECT delivery_state FROM sites_f360.outbox
        WHERE tenant_ref=? AND operation_ref=?'
    );
    $q->execute([$claim['tenant_ref'],$claim['operation_ref']]);
    return (string)$q->fetchColumn();
}

$before=$admin->query(
    "SELECT count(*) FILTER(WHERE delivery_state='delivered') AS done,
            sum(delivery_attempts) AS attempts
       FROM sites_f360.outbox"
)->fetch(PDO::FETCH_ASSOC);
f366Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.outbox
       WHERE delivery_state='pending'"
)->fetchColumn()>=2,'disposable fixture has two pending events to observe');

$first=f366Claim($w1);
f366Check($first!==null && $first['event_type']==='ProjectQueued',
    'restricted outbox worker claims first fictional event');
$offline=f366Send(f366Envelope($first),'normal',18967);
f366Check($offline[0]===0 &&
    f366State($admin,$first)==='leased',
    'receiver offline: no fake receipt, event stays leased');
$badReceipt=f366Send(f366Envelope($first),'forged');
f366Check($badReceipt[0]===202 && !f366Verify($first,$badReceipt),
    'HTTP 202 with forged receipt MAC is NOT successful delivery');
f366Check(f366State($admin,$first)==='leased',
    'nonverified HTTP receipt cannot ACK database outbox');

f366Expire($admin,$first);
$reclaimed=f366Claim($w2);
f366Check($reclaimed!==null &&
    $reclaimed['tenant_ref']===$first['tenant_ref'] &&
    $reclaimed['operation_ref']===$first['operation_ref'] &&
    $reclaimed['lease_ref']!==$first['lease_ref'],
    'expired event reclaimed by independent worker with new lease token');
$validReceipt=f366Send(f366Envelope($reclaimed));
f366Check(f366Verify($reclaimed,$validReceipt),
    'trusted fictional Portal receipt matches event digest and current lease');
f366Check(!f366Verify($first,$validReceipt),
    'a valid receipt for new lease does not authorize stale lease');
f366Check(f366Ack($w2,$reclaimed) &&
    f366State($admin,$reclaimed)==='delivered',
    'worker ACKs PostgreSQL only after verified signed HTTP receipt');
f366Check(!f366Ack($w1,$first) && !f366Ack($w2,$reclaimed),
    'stale and duplicate ACK denied once delivery recorded');

$second=f366Claim($w1);
f366Check($second!==null &&
    ($second['tenant_ref']!==$reclaimed['tenant_ref'] ||
     $second['operation_ref']!==$reclaimed['operation_ref']),
    'second unique pending event still available');
$unavailable=f366Send(f366Envelope($second),'reject');
f366Check($unavailable[0]===503 && !f366Verify($second,$unavailable) &&
    f366State($admin,$second)==='leased',
    'HTTP 503 from receiver prevents premature database ACK');
f366Expire($admin,$second);
$retry=f366Claim($w2);
f366Check($retry!==null &&
    $retry['tenant_ref']===$second['tenant_ref'] &&
    $retry['operation_ref']===$second['operation_ref'] &&
    $retry['lease_ref']!==$second['lease_ref'],
    '503 event reclaimed after lease expiry');
$retryReceipt=f366Send(f366Envelope($retry));
f366Check(f366Verify($retry,$retryReceipt) &&
    f366Ack($w2,$retry),
    'recovered receiver signs correct receipt and event is ACKed once');

$after=$admin->query(
    "SELECT count(*) FILTER(WHERE delivery_state='delivered') AS done,
            sum(delivery_attempts) AS attempts
       FROM sites_f360.outbox"
)->fetch(PDO::FETCH_ASSOC);
f366Check((int)$after['done']===(int)$before['done']+2 &&
    (int)$after['attempts']===(int)$before['attempts']+4,
    'exactly two fictional events delivered with retries after two failures');
f366Check((int)$admin->query(
    'SELECT count(*) FROM sites_f360.admissions a
      LEFT JOIN sites_f360.outbox o ON o.tenant_ref=a.tenant_ref
        AND o.operation_ref=a.operation_ref
      WHERE o.tenant_ref IS NULL'
)->fetchColumn()===0,
    'HTTP/ACK simulation did not create orphan admissions');

echo "$checks F1-366 loopback HTTP synthetic Portal receipt and PG18 ACK checks passed.\n";
echo "NO-GO: no real Portal receipt, signed service identity, external persistence, network egress or proven customer delivery.\n";
