<?php

declare(strict_types=1);

/**
 * F1-369: three separate PostgreSQL18 services ONLY in GitHub CI:
 * Sites PG -> original fictional Portal PG -> restored fictional Portal PG.
 * A real pg_dump/pg_restore transfers an already committed receipt after
 * intentionally losing the HTTP response. Never uses customer data.
 */
$phase=$argv[1]??'';
if (PHP_SAPI!=='cli' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F360_PG_LAB')!=='1' ||
    getenv('SITES_F361_OUTBOX_LAB')!=='1' ||
    getenv('SITES_F367_PORTAL_DB_LAB')!=='1' ||
    getenv('SITES_F369_RESTORE_LAB')!=='1' ||
    getenv('DB_CONNECTION')!=='pgsql' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_PORT')!=='5432' ||
    getenv('DB_DATABASE')!=='sites_f360_lab' ||
    getenv('DB_USERNAME')!=='postgres' ||
    getenv('DB_PASSWORD')!=='f360-fictional-admin-disposable-only' ||
    !in_array($phase,['cause-loss','verify-restored','recover-restored'],true)) {
    fwrite(STDERR,"F1-369: refusing non-disposable PG18 restore fixture.\n");
    exit(1);
}
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5];
function f369Db(string $port,string $name,string $password):PDO {
    global $options;
    $db=new PDO(
        'pgsql:host=127.0.0.1;port='.$port.';dbname='.$name.';connect_timeout=5',
        'postgres',$password,$options
    );
    $version=(int)$db->query('SHOW server_version_num')->fetchColumn();
    $actual=(string)$db->query('SELECT current_database()')->fetchColumn();
    if($version<180000 || $version>=190000 || $actual!==$name) {
        throw new RuntimeException('F1-369: incorrect disposable DB/PG18 version.');
    }
    return $db;
}
$sites=f369Db('5432','sites_f360_lab','f360-fictional-admin-disposable-only');
$worker=new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5',
    'sites_f361_worker','f361-fictional-outbox-worker-only',$options
);
function f369Check(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('F1-369 FAIL: '.$label);
}
function f369Count(PDO $db):int {
    return (int)$db->query('SELECT count(*) FROM portal_f367.receipts')
        ->fetchColumn();
}
function f369Claim(PDO $db):?array {
    $statement=$db->query('SELECT * FROM sites_f360.claim_next_outbox(30)');
    $row=$statement->fetch(PDO::FETCH_ASSOC);
    return $row===false?null:$row;
}
function f369State(PDO $db,array $item):array {
    $query=$db->prepare(
        'SELECT delivery_state,delivery_attempts,lease_ref::text AS lease_ref
           FROM sites_f360.outbox WHERE tenant_ref=? AND operation_ref=?'
    );
    $query->execute([$item['tenant_ref'],$item['operation_ref']]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('F1-369: missing event.');
    return $row;
}
function f369Ack(PDO $db,array $item):bool {
    $q=$db->prepare('SELECT sites_f360.ack_outbox(?,?,?::uuid)');
    $q->execute([$item['tenant_ref'],$item['operation_ref'],$item['lease_ref']]);
    return in_array($q->fetchColumn(),[true,'t','1',1],true);
}
function f369Envelope(array $item):array {
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
function f369Http(array $data,string $mode='normal'):array {
    $ch=curl_init('http://127.0.0.1:18968/v1/lab/portal/durable-receipt');
    if($ch===false)throw new RuntimeException('No local HTTP client.');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>1,
        CURLOPT_TIMEOUT=>5,
        CURLOPT_PROXY=>'',
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'Accept: application/json',
            'X-F367-Lab-Mode: '.$mode,
        ],
    ]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if(!is_string($raw))return [0,null];
    try {$parsed=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException) {return [$status,null];}
    return [$status,is_array($parsed)?$parsed:null];
}
function f369ReceiptValid(array $item,array $http):bool {
    [$status,$receipt]=$http;
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
function f369LeasedFromRestore(PDO $portal, PDO $sites):array {
    $rows=$portal->query(
        'SELECT tenant_ref,operation_ref,event_type,command_digest
         FROM portal_f367.receipts ORDER BY tenant_ref,operation_ref'
    )->fetchAll(PDO::FETCH_ASSOC);
    $found=[];
    foreach($rows as $row) {
        $state=f369State($sites,$row);
        if($state['delivery_state']==='leased') {
            $found[]=['receipt'=>$row,'state'=>$state];
        }
    }
    f369Check(count($found)===1,
        'exactly one persisted Portal receipt is unacknowledged in Sites');
    return $found[0];
}

if($phase==='cause-loss') {
    $original=f369Db('5433','portal_f367_lab','f367-fictional-portal-db-only');
    f369Check(f369Count($original)===4,
        'previous F1-367/368 produced four committed fictional Portal receipts');
    $pending=(int)$sites->query(
        "SELECT count(*) FROM sites_f360.outbox WHERE delivery_state='pending'"
    )->fetchColumn();
    f369Check($pending>=1,'pending Sites event available for backup-restore scenario');
    $event=f369Claim($worker);
    f369Check($event!==null && $event['event_type']==='ProjectQueued',
        'one pending Sites event claimed with exact lease UUID');
    $response=f369Http(f369Envelope($event),'lost_after_commit');
    f369Check($response[0]===503 &&
        ($response[1]['reason']??'')==='simulated_response_loss',
        'receiver committed but returned lost-response failure');
    f369Check(f369Count($original)===5,
        'original Portal PG18 committed fifth receipt before response loss');
    $state=f369State($sites,$event);
    f369Check($state['delivery_state']==='leased' &&
        $state['lease_ref']===$event['lease_ref'] &&
        !f369Ack($worker,[
            'tenant_ref'=>$event['tenant_ref'],
            'operation_ref'=>$event['operation_ref'],
            'lease_ref'=>'00000000-0000-0000-0000-000000000000'
        ]),
        'Sites did not ACK missing response; bogus lease cannot confirm');
    echo "F1-369 phase 1 PASS: original Portal DB committed; Sites lease not ACKed.\n";
    exit(0);
}
$restore=f369Db('5434','portal_f369_restore_lab','f369-fictional-restore-db-only');
f369Check(f369Count($restore)===5,
    'pg_restore into separate third PostgreSQL18 recovered five receipts');
$pair=f369LeasedFromRestore($restore,$sites);
$stored=$pair['receipt'];
$previous=$pair['state'];
f369Check((int)$previous['delivery_attempts']>=1 &&
    is_string($previous['lease_ref']),
    'restored Portal receipt matches an outstanding Sites lease');
if($phase==='verify-restored') {
    f369Check($previous['delivery_state']==='leased',
        'backup validation does not prematurely mark Sites delivered');
    $q=$restore->prepare(
        'SELECT event_type,command_digest FROM portal_f367.receipts
         WHERE tenant_ref=? AND operation_ref=?'
    );
    $q->execute([$stored['tenant_ref'],$stored['operation_ref']]);
    $same=$q->fetch(PDO::FETCH_ASSOC);
    f369Check($same['event_type']===$stored['event_type'] &&
        $same['command_digest']===$stored['command_digest'],
        'independent restored ledger retains exact fictional event digest');
    echo "F1-369 phase 2 PASS: restored third PG18 retains unacknowledged receipt.\n";
    exit(0);
}
// Site lease is expired ONLY by synthetic admin, never worker authority.
$update=$sites->prepare(
    "UPDATE sites_f360.outbox
      SET lease_expires_at=clock_timestamp()-interval '1 second'
      WHERE tenant_ref=? AND operation_ref=? AND lease_ref=?::uuid
        AND delivery_state='leased'"
);
$update->execute([
    $stored['tenant_ref'],$stored['operation_ref'],$previous['lease_ref']
]);
f369Check($update->rowCount()===1,'expiry limited to persisted old lease');
$new=f369Claim($worker);
f369Check($new!==null &&
    $new['tenant_ref']===$stored['tenant_ref'] &&
    $new['operation_ref']===$stored['operation_ref'] &&
    $new['command_digest']===$stored['command_digest'] &&
    $new['lease_ref']!==$previous['lease_ref'] &&
    (int)$new['delivery_attempts']===(int)$previous['delivery_attempts']+1,
    'worker reclaimed same restored receipt with a fresh lease token');
$reply=f369Http(f369Envelope($new));
f369Check(f369ReceiptValid($new,$reply) &&
    ($reply[1]['disposition']??'')==='already_recorded',
    'receiver using restored DB recognizes existing event and signs current lease');
f369Check(f369Count($restore)===5,'HTTP replay does not duplicate restored receipt');
$conflict=f369Envelope($new);
$conflict['commandDigest']=hash('sha256','f369-conflicting-synthetic-digest');
$unsigned=array_slice($conflict,0,-1,true);
$conflict['mac']=hash_hmac('sha256',json_encode(
    $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
),'F366-FICTIONAL-SITES-DISPATCH-KEY-00000000001');
f369Check(f369Http($conflict)[0]===409 &&
    f369Count($restore)===5,
    'restored DB denies conflicting digest under same idempotent key');
f369Check(!f369Ack($worker,[
    'tenant_ref'=>$stored['tenant_ref'],
    'operation_ref'=>$stored['operation_ref'],
    'lease_ref'=>$previous['lease_ref']
]),'old lease cannot ACK a recovered receipt');
f369Check(f369Ack($worker,$new) &&
    f369State($sites,$new)['delivery_state']==='delivered' &&
    !f369Ack($worker,$new),
    'Sites ACKs only once after verifying receipt from third restored DB');
f369Check((int)$sites->query(
    'SELECT count(*) FROM sites_f360.admissions a
     LEFT JOIN sites_f360.outbox o
       ON a.tenant_ref=o.tenant_ref AND a.operation_ref=o.operation_ref
     WHERE o.tenant_ref IS NULL'
)->fetchColumn()===0,'no orphan Sites admissions after PG18 restore and retry');
echo "F1-369 phase 3 PASS: third PG18 restored receipt, idempotent retry, Sites ACK.\n";
echo "NO-GO: backup artifact is fictional and short-lived in a single CI runner; no actual disaster recovery or external vault.\n";
