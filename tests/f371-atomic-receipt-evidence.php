<?php

declare(strict_types=1);

/**
 * F1-371: CI-only atomic Sites outbox ACK + durable Portal receipt evidence.
 * The worker checks a fictional HMAC receipt from a separate restored PG18
 * over loopback HTTP before calling the SQL ACK/evidence function.
 *
 * No real Portal, keys, authentication, customers or production migrations.
 */
if(PHP_SAPI!=='cli' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F360_PG_LAB')!=='1' ||
   getenv('SITES_F361_OUTBOX_LAB')!=='1' ||
   getenv('SITES_F369_RESTORE_LAB')!=='1' ||
   getenv('SITES_F371_RECEIPT_EVIDENCE_LAB')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('DB_PORT')!=='5432' ||
   getenv('DB_DATABASE')!=='sites_f360_lab' ||
   getenv('DB_USERNAME')!=='postgres' ||
   getenv('DB_PASSWORD')!=='f360-fictional-admin-disposable-only') {
    fwrite(STDERR,"F1-371: CI-only evidence gate denied.\n");
    exit(1);
}
$options=[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_TIMEOUT=>5,
];
$dsnSites='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$dsnPortal='pgsql:host=127.0.0.1;port=5434;dbname=portal_f369_restore_lab;connect_timeout=5';
$dsnStale='pgsql:host=127.0.0.1;port=5434;dbname=portal_f370_stale_lab;connect_timeout=5';
$sites=new PDO($dsnSites,'postgres','f360-fictional-admin-disposable-only',$options);
$worker=new PDO($dsnSites,'sites_f361_worker','f361-fictional-outbox-worker-only',$options);
$portal=new PDO($dsnPortal,'postgres','f369-fictional-restore-db-only',$options);
$stale=new PDO($dsnStale,'postgres','f369-fictional-restore-db-only',$options);
if((int)$sites->query('SHOW server_version_num')->fetchColumn()<180000 ||
   (int)$portal->query('SHOW server_version_num')->fetchColumn()<180000 ||
   (string)$portal->query('SELECT current_database()')->fetchColumn()!=='portal_f369_restore_lab' ||
   (string)$stale->query('SELECT current_database()')->fetchColumn()!=='portal_f370_stale_lab') {
    throw new RuntimeException('F1-371: refusing non-PG18/non-disposable databases.');
}
$fixture=__DIR__.'/fixtures/f371-sites-portal-receipt-evidence.sql';
if(!is_file($fixture) || is_link($fixture)) {
    throw new RuntimeException('F1-371: unsafe or missing SQL fixture.');
}
$sites->exec((string)file_get_contents($fixture));

$checks=0;
function f371Check(bool $ok,string $message):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('F1-371 FAIL: '.$message);
    ++$checks;
}
function f371Denied(callable $cb,string $state):bool
{
    try{$cb();}catch(PDOException $e){return $e->getCode()===$state;}
    return false;
}
function f371Count(PDO $db,string $table):int
{
    if($table==='evidence') {
        return (int)$db->query(
            'SELECT count(*) FROM sites_f360.portal_receipt_evidence'
        )->fetchColumn();
    }
    if($table==='receipts') {
        return (int)$db->query(
            'SELECT count(*) FROM portal_f367.receipts'
        )->fetchColumn();
    }
    throw new InvalidArgumentException('Unapproved fixture table name.');
}
function f371Envelope(array $item):array
{
    $msg=[
        'schemaVersion'=>'sites.synthetic.dispatch.v1',
        'tenantRef'=>$item['tenant_ref'],
        'operationRef'=>$item['operation_ref'],
        'eventType'=>$item['event_type'],
        'commandDigest'=>$item['command_digest'],
        'leaseRef'=>$item['lease_ref'],
        'sentAt'=>time(),
    ];
    $msg['mac']=hash_hmac('sha256',json_encode(
        $msg,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),'F366-FICTIONAL-SITES-DISPATCH-KEY-00000000001');
    return $msg;
}
function f371Send(array $envelope):array
{
    $c=curl_init('http://127.0.0.1:18968/v1/lab/portal/durable-receipt');
    if($c===false)throw new RuntimeException('No loopback curl handler.');
    curl_setopt_array($c,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($envelope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>5,
        CURLOPT_PROXY=>'',
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json','Accept: application/json'
        ],
    ]);
    $raw=curl_exec($c);
    $status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
    curl_close($c);
    if(!is_string($raw))return [0,null];
    try{$parsed=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){return [$status,null];}
    return [$status,is_array($parsed)?$parsed:null];
}
function f371Validate(array $item,array $response):bool
{
    [$status,$receipt]=$response;
    $keys=['schemaVersion','tenantRef','operationRef','eventType',
        'commandDigest','leaseRef','receivedAt','disposition','mac'];
    if($status!==202 || !is_array($receipt) ||
       array_keys($receipt)!==$keys ||
       $receipt['schemaVersion']!=='sites.synthetic.durable-receipt.v1' ||
       $receipt['tenantRef']!==$item['tenant_ref'] ||
       $receipt['operationRef']!==$item['operation_ref'] ||
       $receipt['eventType']!==$item['event_type'] ||
       $receipt['commandDigest']!==$item['command_digest'] ||
       $receipt['leaseRef']!==$item['lease_ref'] ||
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
function f371Ack(PDO $db,array $claim,array $receipt):bool
{
    $q=$db->prepare(
        'SELECT sites_f360.ack_with_portal_receipt(?,?,?,?,?,?)'
    );
    $q->execute([
        $claim['tenant_ref'],$claim['operation_ref'],$claim['lease_ref'],
        $receipt['mac'],$receipt['disposition'],$receipt['receivedAt'],
    ]);
    return in_array($q->fetchColumn(),[true,'t','1',1],true);
}
function f371PortalMatches(PDO $db,array $claim):bool
{
    $q=$db->prepare(
        'SELECT event_type,command_digest FROM portal_f367.receipts
         WHERE tenant_ref=? AND operation_ref=?'
    );
    $q->execute([$claim['tenant_ref'],$claim['operation_ref']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row) &&
      $row['event_type']===$claim['event_type'] &&
      $row['command_digest']===$claim['command_digest'];
}
function f371Evidence(PDO $db,array $claim):?array
{
    $q=$db->prepare(
        'SELECT command_digest,receipt_mac,disposition,receipt_issued_at
         FROM sites_f360.portal_receipt_evidence
         WHERE tenant_ref=? AND operation_ref=?'
    );
    $q->execute([$claim['tenant_ref'],$claim['operation_ref']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row===false?null:$row;
}
f371Check(f371Count($sites,'evidence')===0,
    'new signed receipt evidence ledger starts empty');
f371Check(f371Count($portal,'receipts')===4,
    'current Portal restored database has four prior receipts');
f371Check(f371Count($stale,'receipts')===4,
    'stale database includes three previous receipts and one synthetic unknown row');
f371Check(f371Denied(static fn()=> $worker->query(
    'SELECT * FROM sites_f360.portal_receipt_evidence'
),'42501'), 'worker cannot directly query protected evidence');
f371Check(f371Denied(static fn()=> $worker->exec(
    "INSERT INTO sites_f360.portal_receipt_evidence
       (tenant_ref,operation_ref,command_digest,receipt_mac,
        disposition,receipt_issued_at)
     VALUES ('tenant-f371','operation-f371','".str_repeat('0',64)."',
             '".str_repeat('0',64)."','stored',1000000)"
),'42501'), 'worker cannot bypass function with direct evidence insertion');
f371Check(f371Denied(static fn()=> $sites->query(
    "SELECT sites_f360.ack_with_portal_receipt('tenant-f371','op-f371',
      '00000000-0000-0000-0000-000000000000'::uuid,
      '".str_repeat('0',64)."','stored',".time().")"
),'42501'), 'administrative SQL login cannot impersonate receipt worker');

$claimed=$worker->query('SELECT * FROM sites_f360.claim_next_outbox(30)')
    ->fetch(PDO::FETCH_ASSOC);
f371Check(is_array($claimed) && $claimed['event_type']==='ProjectQueued',
    'worker claimed a genuine remaining synthetic outbox event');
f371Check(!f371PortalMatches($stale,$claimed),
    'stale Portal database does not contain newly claimed operation');
$response=f371Send(f371Envelope($claimed));
f371Check(f371Validate($claimed,$response) &&
    ($response[1]['disposition']??null)==='stored',
    'recovered Portal signs receipt only after committing new fictional event');
f371Check(f371PortalMatches($portal,$claimed),
    'new event committed to restored Portal fixture database');
f371Check(!f371PortalMatches($stale,$claimed),
    'same event remains missing in stale backup');
f371Check(f371Count($sites,'evidence')===0,
    'HTTP 202 alone did not record receipt evidence in Sites DB');

$faked=$response;
$faked[1]['mac']=str_repeat('f',64);
f371Check(!f371Validate($claimed,$faked),
    'tampered receipt MAC rejected in app before atomic SQL ACK');
f371Check(f371Denied(static fn()=>f371Ack($worker,$claimed,[
    'mac'=>'invalid','disposition'=>'stored','receivedAt'=>time()
]),'22023'), 'SQL rejects malformed receipt proof metadata');
f371Check(f371Count($sites,'evidence')===0,
    'invalid proof did not write evidence or acknowledge outbox');
f371Check(f371Ack($worker,$claimed,$response[1]),
    'verified signed receipt and outbox ACK committed as single SQL operation');
f371Check(!f371Ack($worker,$claimed,$response[1]),
    'duplicate lease ACK cannot write second evidence record');
f371Check(f371Count($sites,'evidence')===1,
    'exactly one durable signed receipt evidence record retained in Sites DB');
$proof=f371Evidence($sites,$claimed);
f371Check($proof!==null &&
    $proof['receipt_mac']===$response[1]['mac'] &&
    $proof['command_digest']===$claimed['command_digest'] &&
    $proof['disposition']==='stored' &&
    (int)$proof['receipt_issued_at']===$response[1]['receivedAt'],
    'Sites independent evidence contains exact signed Portal digest and MAC');

$sitesState=$sites->prepare(
    'SELECT delivery_state,lease_ref FROM sites_f360.outbox
     WHERE tenant_ref=? AND operation_ref=?'
);
$sitesState->execute([$claimed['tenant_ref'],$claimed['operation_ref']]);
$state=$sitesState->fetch(PDO::FETCH_ASSOC);
f371Check($state['delivery_state']==='delivered' &&
    $state['lease_ref']===null,
    'Sites transaction delivered event and cleared its lease after evidence commit');

$missingFromStale=0;
$missingFromHealthy=0;
$evidence=$sites->query(
    'SELECT tenant_ref,operation_ref,command_digest
     FROM sites_f360.portal_receipt_evidence'
)->fetchAll(PDO::FETCH_ASSOC);
foreach($evidence as $item) {
    $minimal=[
        'tenant_ref'=>$item['tenant_ref'],
        'operation_ref'=>$item['operation_ref'],
        'event_type'=>'ProjectQueued',
        'command_digest'=>$item['command_digest'],
    ];
    if(!f371PortalMatches($stale,$minimal))++$missingFromStale;
    if(!f371PortalMatches($portal,$minimal))++$missingFromHealthy;
}
f371Check(count($evidence)===1 &&
    $missingFromStale===1 && $missingFromHealthy===0,
    'Sites evidence ALONE identifies missing event on stale Portal restore, no newer Portal reference needed');
echo "$checks F1-371 atomic Site ACK + signed Portal receipt evidence checks passed.\n";
echo "NO-GO: fake receipt keys, CI-only SQL adapter and nonproduction source of truth; production requires independently verified identities and AppSec review.\n";
