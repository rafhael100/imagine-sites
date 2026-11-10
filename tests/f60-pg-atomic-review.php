<?php

declare(strict_types=1);

/**
 * F1-60: DB-owned current authority + v2 nonce under one row-lock transaction.
 * NO HMAC verification, production IAM, API, customer data or distributed proof.
 */
if (PHP_SAPI!=='cli' || getenv('SITES_F60_PG_LAB')!=='1') {
    fwrite(STDERR,"F1-60 disabled outside flagged, disposable CLI/Postgres lab.\n");
    exit(1);
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_auth_replay_lab';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false];
$connect=static fn()=>new PDO($dsn,'postgres','nonce-lab-fictional-only',$opts);
$owner=$connect();
$a=$connect();
$b=$connect();
$unauthorized=$connect();
if($owner->query('SELECT current_database()')->fetchColumn()!=='sites_auth_replay_lab') {
    throw new RuntimeException('Wrong safety-limited lab DB');
}
$a->exec('SET ROLE sites_review_tenant_a');
$b->exec('SET ROLE sites_review_tenant_b');
$unauthorized->exec('SET ROLE sites_auth_runtime');

$cases=0;
function f60Check(bool $ok,string $name):void {
    global $cases;
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    ++$cases;echo 'PASS '.$name."\n";
}
function f60Consume(PDO $conn,array $overrides=[]):bool {
    $args=array_replace([
        'tenant'=>'tenant-f49-a',
        'site'=>'site-f49-a',
        'workflow'=>'workflow-f49-a',
        'revision'=>3,
        'release'=>'release-f49-a',
        'digest'=>str_repeat('c',64),
        'epoch'=>7,
        'nonce'=>'f60-good-replay-00001',
        'expires'=>time()+60,
    ],$overrides);
    $q=$conn->prepare(
        'SELECT auth_replay.consume_current_review_v2(
            :tenant,:site,:workflow,:revision,:release,:digest,:epoch,:nonce,
            to_timestamp(:expires)
        )'
    );
    foreach($args as $name=>$value) {
        $q->bindValue(':'.$name,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    }
    $q->execute();
    $value=$q->fetchColumn();
    return $value===true || $value==='t' || $value==='1';
}
function f60Count(PDO $db):int {
    return (int)$db->query(
        'SELECT count(*) FROM auth_replay.review_v2_nonce_consumptions'
    )->fetchColumn();
}
$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET checked_at=clock_timestamp(),
     valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref IN ('tenant-f49-a','tenant-f49-b')");
f60Check($a->query("SELECT current_setting('role')")->fetchColumn()==='sites_review_tenant_a',
    'invoker role A is a restricted SQL role (not envelope claim)');
f60Check($b->query("SELECT current_setting('role')")->fetchColumn()==='sites_review_tenant_b',
    'invoker role B is independent and restricted');
f60Check(f60Count($owner)===0,'v2 nonce table starts empty');
f60Check(f60Consume($a),'current A grant, release, epoch and nonce permit ONE fictional proposal');
f60Check(!f60Consume($a),'same signed nonce cannot be consumed twice');
f60Check(f60Count($owner)===1,'replay denial does not add a second row');

$forB=[
    'tenant'=>'tenant-f49-b','site'=>'site-f49-b','workflow'=>'workflow-f49-b',
    'release'=>'release-f49-b','digest'=>str_repeat('b',64),'epoch'=>3,
    'nonce'=>'f60-good-b-nonce-00002',
];
f60Check(f60Consume($b,$forB),'role B can atomically consume its own fictional proposal');
f60Check(!f60Consume($a,$forB),'role A cannot consume other tenant B proposal');
f60Check(!f60Consume($b,['nonce'=>'f60-b-tries-a-0003']),
    'role B cannot consume tenant A proposal');
f60Check(f60Count($owner)===2,'cross-tenant requests never consume nonce');

$negatives=[
    ['site'=>'site-f49-b'],['workflow'=>'workflow-other'],
    ['revision'=>4],['release'=>'release-other'],
    ['digest'=>str_repeat('d',64)],['epoch'=>8],
    // Keep negative expiry fixtures well outside SQL clock boundaries.
    ['expires'=>time()-10],['expires'=>time()+150],
    ['nonce'=>'bad'],['tenant'=>'../tenant'],
    ['site'=>'site-f49-a;DROP TABLE fake;--'],
];
foreach($negatives as $i=>$patch) {
    f60Check(!f60Consume($a,$patch+['nonce'=>'f60-denied-attempt-'.($i+100)]),
        'forged/expired/out-of-scope proposal denied '.$i);
}
f60Check(f60Count($owner)===2,'failed authorizations leave v2 nonce history unchanged');

$shared='f60-globally-shared-nonce-00004';
f60Check(f60Consume($a,['nonce'=>$shared]),
    'first legitimate tenant reserves a globally unique issuer+nonce');
f60Check(!f60Consume($b,array_replace($forB,['nonce'=>$shared])),
    'same issuer+nonce cannot be reused across another tenant');
f60Check(f60Count($owner)===3,'cross-tenant reuse did not change ledger');

$writeDenied=false;
try {
    $a->exec("UPDATE auth_replay.current_portal_review_authority
      SET status='reviewable' WHERE tenant_ref='tenant-f49-a'");
} catch(PDOException) {$writeDenied=true;}
f60Check($writeDenied,'tenant reader cannot modify protected current authority');
$insertDenied=false;
try {
    $a->exec("INSERT INTO auth_replay.review_v2_nonce_consumptions
      (issuer,nonce,tenant_ref,site_ref,authorization_revision,expires_at)
      VALUES ('imagine-sites:review-handoff.v2','f60-bypass-insert-00005',
      'tenant-f49-a','site-f49-a',7,clock_timestamp()+interval '25 seconds')");
} catch(PDOException) {$insertDenied=true;}
f60Check($insertDenied,'tenant cannot insert nonce without locked authority function');

$noExecute=false;
try {f60Consume($unauthorized,['nonce'=>'f60-non-granted-0006']);}
catch(PDOException){$noExecute=true;}
f60Check($noExecute,'non-review runtime role has no permission to invoke atomic function');

$before=f60Count($owner);
$owner->beginTransaction();
$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET status='revoked',authorization_revision=8,
     checked_at=clock_timestamp(),valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref='tenant-f49-a'");
$a->exec("SET lock_timeout='120ms'");
$blockedByRevoke=false;
try {
    f60Consume($a,['nonce'=>'f60-owner-revoke-lock-0007']);
} catch(PDOException) {$blockedByRevoke=true;}
$owner->commit();
$a->exec("SET lock_timeout='0'");
f60Check($blockedByRevoke,'revocation holds current-authority row lock until committed');
f60Check(f60Count($owner)===$before,'timed-out concurrent proposal consumed no nonce');
f60Check(!f60Consume($a,['nonce'=>'f60-revoked-after-commit-008']),
    'status revoked is seen after lock release');
$owner->exec("UPDATE auth_replay.current_portal_review_authority
 SET status='reviewable',authorization_revision=9,
     checked_at=clock_timestamp(),valid_until=clock_timestamp()+interval '25 seconds'
 WHERE tenant_ref='tenant-f49-a'");
f60Check(!f60Consume($a,['nonce'=>'f60-old-epoch-reactivation-009']),
    'epoch 7 cannot be revived when approval resumes at epoch 9');
f60Check(f60Consume($a,['nonce'=>'f60-new-epoch-after-revoke-010','epoch'=>9]),
    'fresh epoch 9 creates a new proposal (not a client approval)');

$before=f60Count($owner);
$owner->beginTransaction();
$owner->exec("UPDATE auth_replay.review_role_grants
 SET status='revoked'
 WHERE tenant_ref='tenant-f49-a' AND role_name='sites_review_tenant_a'");
$a->exec("SET lock_timeout='120ms'");
$blockedByGrant=false;
try {f60Consume($a,['nonce'=>'f60-grant-lock-00011','epoch'=>9]);}
catch(PDOException){$blockedByGrant=true;}
$owner->commit();
$a->exec("SET lock_timeout='0'");
f60Check($blockedByGrant,'role-grant revocation and consumption share a lock');
f60Check(f60Count($owner)===$before,'grant revocation race did not consume a nonce');
f60Check(!f60Consume($a,['nonce'=>'f60-grant-revoked-00012','epoch'=>9]),
    'revoked grant cannot consume despite apparently reviewable resource');
f60Check(f60Consume($b,array_replace($forB,['nonce'=>'f60-other-tenant-active-0013'])),
    'other tenant independently continues after A grant revoked');
f60Check(f60Count($owner)===5,'exactly five independently permitted fictional proposals stored');

echo "\n$cases F1-60 atomic lab assertions passed. NO real Portal IAM, HMAC, client approval or distributed fence.\n";
