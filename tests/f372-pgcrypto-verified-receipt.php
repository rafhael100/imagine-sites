<?php

declare(strict_types=1);

/**
 * F1-372: CI-only proof that PostgreSQL18 itself verifies signed Portal
 * receipt bytes before atomic ACK/evidence, and legacy bypasses are revoked.
 * All identities, database credentials and HMAC keys are fictitious.
 */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
   getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F360_PG_LAB')!=='1' ||
   getenv('SITES_F361_OUTBOX_LAB')!=='1' ||
   getenv('SITES_F371_RECEIPT_EVIDENCE_LAB')!=='1' ||
   getenv('SITES_F372_PGCRYPTO_LAB')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('DB_PORT')!=='5432' ||
   getenv('DB_DATABASE')!=='sites_f360_lab' ||
   getenv('DB_USERNAME')!=='postgres' ||
   getenv('DB_PASSWORD')!=='f360-fictional-admin-disposable-only') {
    fwrite(STDERR,"F1-372: refusing operation outside disposable PG18 CI.\n");
    exit(1);
}
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5];
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$admin=new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',$options);
if((int)$admin->query('SHOW server_version_num')->fetchColumn()<180000 ||
   (string)$admin->query('SELECT current_database()')->fetchColumn()!=='sites_f360_lab') {
    throw new RuntimeException('F1-372 requires isolated PG18.');
}
$fixture=__DIR__.'/fixtures/f372-pgcrypto-verified-receipt.sql';
if(!is_file($fixture) || is_link($fixture)) {
    throw new RuntimeException('Unsafe/missing F1-372 SQL fixture.');
}
$admin->exec((string)file_get_contents($fixture));
$worker=new PDO($dsn,'sites_f361_worker','f361-fictional-outbox-worker-only',$options);
$tenant=new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$options);
$portal=new PDO(
    'pgsql:host=127.0.0.1;port=5434;dbname=portal_f369_restore_lab;connect_timeout=5',
    'postgres','f369-fictional-restore-db-only',$options
);
$stale=new PDO(
    'pgsql:host=127.0.0.1;port=5434;dbname=portal_f370_stale_lab;connect_timeout=5',
    'postgres','f369-fictional-restore-db-only',$options
);
$checks=0;
function f372Check(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-372 FAIL: '.$label);
    ++$checks;
}
function f372Denied(callable $action,string $expectedCode):bool {
    try{$action();}catch(PDOException $e){return $e->getCode()===$expectedCode;}
    return false;
}
function f372ProofCount(PDO $admin):int {
    return (int)$admin->query(
        'SELECT count(*) FROM sites_f360.portal_receipt_evidence'
    )->fetchColumn();
}
function f372Add(PDO $tenant,string $fingerprint):string {
    $q=$tenant->prepare('SELECT sites_f360.admit_project(?,?,?,?,?,?,?)');
    $q->execute([
        'tenant-f360-b','idem-f372','site-f372','contract-f372',
        'onepage',1,$fingerprint,
    ]);
    return (string)$q->fetchColumn();
}
function f372Envelope(array $event):array {
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
function f372Http(array $event):array {
    $c=curl_init('http://127.0.0.1:18968/v1/lab/portal/durable-receipt');
    if($c===false)throw new RuntimeException('No local HTTP client.');
    curl_setopt_array($c,[
       CURLOPT_POST=>true,
       CURLOPT_POSTFIELDS=>json_encode(
          f372Envelope($event),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
       ),
       CURLOPT_RETURNTRANSFER=>true,
       CURLOPT_FOLLOWLOCATION=>false,
       CURLOPT_CONNECTTIMEOUT=>2,
       CURLOPT_TIMEOUT=>5,
       CURLOPT_PROXY=>'',
       CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
    ]);
    $raw=curl_exec($c);
    $status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
    curl_close($c);
    if(!is_string($raw))return [0,null];
    try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){return [$status,null];}
    return [$status,is_array($data)?$data:null];
}
function f372Unsigned(array $receipt):string {
    $unsigned=array_slice($receipt,0,-1,true);
    return json_encode(
        $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    );
}
function f372Ack(PDO $worker,string $unsigned,string $mac):bool {
    $query=$worker->prepare(
        'SELECT sites_f360.ack_verified_portal_receipt(?,?)'
    );
    $query->execute([$unsigned,$mac]);
    return in_array($query->fetchColumn(),[true,'t','1',1],true);
}
function f372ReceiptIn(PDO $db,array $claim):bool {
    $q=$db->prepare(
        'SELECT command_digest FROM portal_f367.receipts
         WHERE tenant_ref=? AND operation_ref=?'
    );
    $q->execute([$claim['tenant_ref'],$claim['operation_ref']]);
    return $q->fetchColumn()===$claim['command_digest'];
}
function f372State(PDO $admin,array $claim):string {
    $q=$admin->prepare(
       'SELECT delivery_state FROM sites_f360.outbox
        WHERE tenant_ref=? AND operation_ref=?'
    );
    $q->execute([$claim['tenant_ref'],$claim['operation_ref']]);
    return (string)$q->fetchColumn();
}
f372Check(f372ProofCount($admin)===1,
    'one earlier CI receipt evidence survives F1-371 proof');
f372Check(f372Denied(static fn()=> $worker->query(
    'SELECT * FROM sites_f360.portal_receipt_verifiers'
),'42501'), 'SQL worker has no read permission on private signing key');
f372Check(f372Denied(static fn()=> $tenant->query(
    'SELECT * FROM sites_f360.portal_receipt_verifiers'
),'42501'), 'tenant SQL login has no access to private signing key');
f372Check(f372Denied(static fn()=> $worker->query(
    "SELECT sites_f360.ack_outbox(
      'tenant-f360-b','idem-f372','00000000-0000-0000-0000-000000000000'::uuid)"
),'42501'), 'unverified legacy outbox ACK path revoked from worker');
f372Check(f372Denied(static fn()=> $worker->query(
    "SELECT sites_f360.ack_with_portal_receipt(
      'tenant-f360-b','idem-f372',
      '00000000-0000-0000-0000-000000000000'::uuid,
      '".str_repeat('0',64)."','stored',".time().")"
),'42501'), 'legacy evidence-only function also revoked from worker');

$digest=hash('sha256','F1-372-FICTIONAL-ONEPAGE-COMMAND');
f372Check(f372Add($tenant,$digest)==='queued',
    'new synthetic Onepage admitted with durable outbox');
$event=$worker->query(
    'SELECT * FROM sites_f360.claim_next_outbox(30)'
)->fetch(PDO::FETCH_ASSOC);
f372Check(is_array($event) &&
    $event['operation_ref']==='idem-f372' &&
    $event['tenant_ref']==='tenant-f360-b' &&
    $event['command_digest']===$digest,
    'worker claims only new verified-ACK test event');
$http=f372Http($event);
f372Check($http[0]===202 && is_array($http[1]) &&
    $http[1]['leaseRef']===$event['lease_ref'] &&
    $http[1]['commandDigest']===$digest &&
    in_array($http[1]['disposition'],['stored','already_recorded'],true),
    'restored Portal HTTP server returned signed candidate for claimed lease');
$receipt=$http[1];
$unsigned=f372Unsigned($receipt);
f372Check(f372ReceiptIn($portal,$event) &&
    !f372ReceiptIn($stale,$event),
    'full Portal PG18 contains new signed receipt, stale backup does not');

f372Check(f372Denied(static fn()=>f372Ack(
    $worker,$unsigned,str_repeat('0',64)
),'42501'), 'PostgreSQL refuses forged MAC even if worker calls SQL directly');
$tampered=json_decode($unsigned,true,16,JSON_THROW_ON_ERROR);
$tampered['commandDigest']=hash('sha256','f372-falsified-digest');
$changed=json_encode($tampered,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
f372Check(f372Denied(static fn()=>f372Ack(
    $worker,$changed,$receipt['mac']
),'42501'), 'PostgreSQL refuses changed digest with original signed MAC');
f372Check(f372State($admin,$event)==='leased' &&
    f372ProofCount($admin)===1,
    'invalid MAC attempts neither ACK the outbox nor persist evidence');

f372Check(f372Ack($worker,$unsigned,$receipt['mac']),
    'PostgreSQL independently verifies real fixture HMAC and atomically ACKs');
f372Check(!f372Ack($worker,$unsigned,$receipt['mac']),
    'replaying valid signed receipt cannot ACK a second time');
f372Check(f372ProofCount($admin)===2 &&
    f372State($admin,$event)==='delivered',
    'new evidence and delivered state are durably committed only once');
$q=$admin->prepare(
    'SELECT command_digest,receipt_mac,disposition,receipt_issued_at
      FROM sites_f360.portal_receipt_evidence
     WHERE tenant_ref=? AND operation_ref=?'
);
$q->execute([$event['tenant_ref'],$event['operation_ref']]);
$evidence=$q->fetch(PDO::FETCH_ASSOC);
f372Check(is_array($evidence) &&
    $evidence['receipt_mac']===$receipt['mac'] &&
    $evidence['command_digest']===$digest &&
    $evidence['disposition']===$receipt['disposition'] &&
    (int)$evidence['receipt_issued_at']===$receipt['receivedAt'],
    'Sites proof journal stores exact DB-verified receipt signature');

f372Check(!f372ReceiptIn($stale,$event) &&
    f372ReceiptIn($portal,$event),
    'independent Site evidence flags an event lost by stale restored Portal backup');
echo "$checks F1-372 PostgreSQL18 pgcrypto receipt verification + bypass revocation checks passed.\n";
echo "NO-GO: key in SQL fixture is public/fake; production must use rotated verification material with distinct IAM and independent authority.\n";
