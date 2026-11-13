<?php

declare(strict_types=1);

/**
 * F1-361: simulated outbox worker leases/acks 5 synthetic PG18 events from
 * the preceding F1-360 fixture. No external delivery or production access.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_F360_PG_LAB') !== '1' ||
    getenv('SITES_F361_OUTBOX_LAB') !== '1' ||
    getenv('DB_CONNECTION') !== 'pgsql' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_PORT') !== '5432' ||
    getenv('DB_DATABASE') !== 'sites_f360_lab' ||
    getenv('DB_USERNAME') !== 'postgres' ||
    getenv('DB_PASSWORD') !== 'f360-fictional-admin-disposable-only') {
    fwrite(STDERR,"F1-361: refusing execution outside disposable PG18 CI lab.\n");
    exit(1);
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$options=[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_TIMEOUT=>6,
];
$admin=new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',$options);
$version=(int)$admin->query('SHOW server_version_num')->fetchColumn();
if($version < 180000 || $version >= 190000) {
    throw new RuntimeException('F1-361: PostgreSQL18 required.');
}
if((int)$admin->query('SELECT count(*) FROM sites_f360.admissions')
    ->fetchColumn() !== 5 ||
   (int)$admin->query('SELECT count(*) FROM sites_f360.outbox')
    ->fetchColumn() !== 5) {
    throw new RuntimeException('F1-361: F1-360 synthetic prerequisite absent.');
}
$fixture=__DIR__.'/fixtures/f361-pg-outbox-worker.sql';
if (!is_file($fixture) || is_link($fixture)) {
    throw new RuntimeException('F1-361: fixture absent or unsafe.');
}
$admin->exec((string)file_get_contents($fixture));

$w1=new PDO($dsn,'sites_f361_worker','f361-fictional-outbox-worker-only',$options);
$w2=new PDO($dsn,'sites_f361_worker','f361-fictional-outbox-worker-only',$options);
$app=new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$options);
$checks=0;
function f361Check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('F1-361 FAIL: '.$label);
    ++$checks;
}
function f361Denied(callable $action,string $code):bool
{
    try{$action();}catch(PDOException $e){return $e->getCode()===$code;}
    return false;
}
function f361Claim(PDO $db,int $seconds=30):?array
{
    $p=$db->prepare('SELECT * FROM sites_f360.claim_next_outbox(?)');
    $p->execute([$seconds]);
    $result=$p->fetch(PDO::FETCH_ASSOC);
    return $result===false?null:$result;
}
function f361Ack(PDO $db,array $claimed):bool
{
    $p=$db->prepare(
        'SELECT sites_f360.ack_outbox(?,?,?::uuid)'
    );
    $p->execute([$claimed['tenant_ref'],$claimed['operation_ref'],
        $claimed['lease_ref']]);
    return in_array($p->fetchColumn(),[true,'t','1',1],true);
}

f361Check((string)$w1->query('SELECT session_user')->fetchColumn()
    ==='sites_f361_worker','worker has separate authenticated SQL login');
f361Check(f361Denied(static fn()=> $w1->query(
    'SELECT * FROM sites_f360.outbox'
),'42501'), 'worker cannot directly list private outbox');
f361Check(f361Denied(static fn()=> $w1->exec(
    "UPDATE sites_f360.outbox SET delivery_state='delivered'"
),'42501'), 'worker cannot arbitrarily alter events');
f361Check(f361Denied(static fn()=> $app->query(
    'SELECT * FROM sites_f360.claim_next_outbox(10)'
),'42501'), 'tenant login cannot execute privileged worker claim');
f361Check(f361Denied(static fn()=> $app->query(
    "SELECT sites_f360.ack_outbox('tenant-f360-b','op-shared',
       '00000000-0000-0000-0000-000000000000'::uuid)"
),'42501'), 'tenant login cannot forge dispatcher ACK');
f361Check(f361Denied(static fn()=> $w1->query(
    "SELECT sites_f360.admit_project('worker-op','worker-site',
      'worker-contract','onepage',1,'".
       hash('sha256','worker-not-allowed')."')"
),'42501'), 'worker cannot impersonate project admission login');
f361Check(f361Denied(static fn()=>f361Claim($w1,0),'22023'),
    'zero-second worker lease rejected');
f361Check(f361Denied(static fn()=>f361Claim($w2,61),'22023'),
    'unbounded worker lease rejected');

$w1->beginTransaction();
try {
    $first=f361Claim($w1,30);
    f361Check($first!==null &&
        $first['event_type']==='ProjectQueued' &&
        (int)$first['delivery_attempts']===1,
        'worker 1 atomically claims exactly one pending event');
    // This operation happens on another PostgreSQL connection while
    // worker 1 still holds its first row lock. SKIP LOCKED must advance.
    $second=f361Claim($w2,30);
    f361Check($second!==null &&
        ($second['tenant_ref']!==$first['tenant_ref'] ||
         $second['operation_ref']!==$first['operation_ref']),
        'worker 2 skips locked event and claims a distinct one');
    f361Check(f361Ack($w2,$second),
        'worker 2 acknowledges its leased event with exact token');
    $w1->commit();
} finally {
    if($w1->inTransaction())$w1->rollBack();
}
f361Check(!f361Ack($w2,$second),'delivered event cannot be ACKed twice');
f361Check(!f361Ack($w2,[
    'tenant_ref'=>$first['tenant_ref'],
    'operation_ref'=>$first['operation_ref'],
    'lease_ref'=>'00000000-0000-0000-0000-000000000000'
]),'wrong lease token does not acknowledge first event');

// Accelerate the lease clock only in the disposable fixture, to avoid
// a wall-clock sleep in GitHub CI. A real worker cannot update this table.
$stmt=$admin->prepare(
    "UPDATE sites_f360.outbox SET lease_expires_at=clock_timestamp()-interval '1 second'
     WHERE tenant_ref=? AND operation_ref=?"
);
$stmt->execute([$first['tenant_ref'],$first['operation_ref']]);
f361Check($stmt->rowCount()===1,
    'fixture explicitly simulates expiry of an unacknowledged lease');

$reclaimed=f361Claim($w2,30);
f361Check($reclaimed!==null &&
    $reclaimed['tenant_ref']===$first['tenant_ref'] &&
    $reclaimed['operation_ref']===$first['operation_ref'] &&
    $reclaimed['lease_ref']!==$first['lease_ref'] &&
    (int)$reclaimed['delivery_attempts']===2,
    'expired lease is reclaimed with new token and increased attempts');
f361Check(!f361Ack($w1,$first),
    'stale worker lease token cannot ACK a reclaimed event');
f361Check(f361Ack($w2,$reclaimed),
    'reclaimed event ACK succeeds with its new token');
f361Check(!f361Ack($w2,$reclaimed),
    'reclaimed event cannot be acknowledged twice');

$remaining=0;
while(($next=f361Claim($w1,30))!==null) {
    f361Check($next['event_type']==='ProjectQueued',
        'remaining claim preserves exact event type');
    f361Check(f361Ack($w1,$next),
        'remaining event acknowledged with its matching lease token');
    if(++$remaining>10)throw new RuntimeException('F1-361: unexpected claim loop.');
}
f361Check($remaining===3,'exactly three remaining unique outbox events claimed');
f361Check(f361Claim($w2,30)===null,
    'fully acknowledged outbox has no more claimable events');

$summary=$admin->query(
    "SELECT count(*) AS total,
       count(*) FILTER(WHERE delivery_state='delivered') AS delivered,
       count(*) FILTER(WHERE delivery_state='pending') AS pending,
       count(*) FILTER(WHERE delivery_state='leased') AS leased,
       sum(delivery_attempts) AS attempts
     FROM sites_f360.outbox"
)->fetch(PDO::FETCH_ASSOC);
f361Check(
    (int)$summary['total']===5 &&
    (int)$summary['delivered']===5 &&
    (int)$summary['pending']===0 &&
    (int)$summary['leased']===0 &&
    (int)$summary['attempts']===6,
    'five durable events delivered in lab with six claims including one retry');
f361Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.admissions a
     LEFT JOIN sites_f360.outbox o ON o.tenant_ref=a.tenant_ref
        AND o.operation_ref=a.operation_ref
     WHERE o.tenant_ref IS NULL"
)->fetchColumn()===0, 'no admission lost its outbox event while workers claimed');
echo "$checks F1-361 PG18 lease, concurrent worker and ACK checks passed.\n";
echo "NO-GO: this is only DB-local claim/ack. No external delivery proof, worker service, CRM or Portal dispatch.\n";
