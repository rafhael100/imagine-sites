<?php

declare(strict_types=1);

/** F1-167: disposable PostgreSQL18 globally unique CRM request nonces. */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F167_PG_LAB')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
    fwrite(STDERR,"F1-167 only runs on disposable PostgreSQL18 CI.\n");
    exit(1);
}
require __DIR__.'/../src/Integration/LabCrmServiceNonceAssessment.php';
require __DIR__.'/../src/Integration/LabPostgresCrmServiceNonceStore.php';

use Imagine\Sites\Integration\LabCrmServiceNonceAssessment as Assessment;
use Imagine\Sites\Integration\LabPostgresCrmServiceNonceStore as Store;

$count=0;
function check167(bool $ok,string $why):void {
    global $count;
    if(!$ok)throw new RuntimeException('F1-167 '.$why);
    ++$count;
}
function connect167(string $user,string $password):PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
        $user,$password,[
          PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
function deniedSql167(callable $query,string $expectedState):bool {
    try{$query();}catch(PDOException $e){
        return $e->getCode()===$expectedState;
    }
    return false;
}
function withoutRights167(Assessment $a):bool {
    return !$a->canAuthenticateService() && !$a->canAcceptLead() &&
        !$a->canSendLeadToCrm() && !$a->canAcknowledgeVisitor() &&
        !$a->canMarkDelivered() && !$a->canRetryAutomatically();
}
$admin=connect167('postgres','f140-fictional-admin-ci-only');
$appA=connect167('sites_f167_a','f167-fictional-a-only');
$appB=connect167('sites_f167_b','f167-fictional-b-only');
$storeA=new Store($appA);
$storeB=new Store($appB);
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$binding='22222222-2222-4222-8222-222222222222';
$nonce1='77777777-7777-4777-8777-777777777777';
$nonce2='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$nonce3='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$countRows=static fn():int=>
    (int)$admin->query('SELECT count(*) FROM f167_lab.synthetic_crm_service_nonce_history')
        ->fetchColumn();

check167($countRows()===0,'empty global nonce history at fresh start');
$blocked=$storeA->reserve($orgA,$binding,$nonce1,'deliver',300);
check167($blocked->reasonCode==='TRANSACTION_REQUIRED' &&
    withoutRights167($blocked),'PDO adapter never claims nonce outside transaction');
$appA->beginTransaction();
$first=$storeA->reserve($orgA,$binding,$nonce1,'deliver',1);
check167($first->reasonCode==='NONCE_RESERVED_UNVERIFIED' &&
    withoutRights167($first),
    'first technical nonce reserved without operational receipt');
check167($countRows()===0,
    'separate admin connection cannot observe uncommitted nonce claim');
$appA->commit();
check167($countRows()===1,'committing nonce adds a durable unique DB record');

$appA->beginTransaction();
$repeat=$storeA->reserve($orgA,$binding,$nonce1,'deliver',300);
check167($repeat->reasonCode==='NONCE_ALREADY_CONSUMED' &&
    withoutRights167($repeat),'repeat nonce denied without modifying history');
$appA->commit();
check167($countRows()===1,'same identity replay cannot create duplicate');

$appB->beginTransaction();
$cross=$storeB->reserve($orgB,$binding,$nonce1,'lookup',300);
check167($cross->reasonCode==='NONCE_ALREADY_CONSUMED',
    'global nonce remains unavailable to another organization and operation');
$appB->commit();
check167($countRows()===1,'cross tenant replay cannot create row');

$appA->beginTransaction();
$wrong=$storeA->reserve($orgB,$binding,$nonce2,'deliver',300);
check167($wrong->reasonCode==='DB_LOGIN_SCOPE_DENIED',
    'A service login cannot claim B organization ID');
$appA->commit();
$appB->beginTransaction();
$wrong=$storeB->reserve($orgA,$binding,$nonce2,'lookup',300);
check167($wrong->reasonCode==='DB_LOGIN_SCOPE_DENIED',
    'B service login cannot claim A organization ID');
$appB->commit();
check167($countRows()===1,'forged organization references cause no write');

$appB->beginTransaction();
$original=$storeB->reserve($orgB,$binding,$nonce2,'lookup',300);
check167($original->reasonCode==='NONCE_RESERVED_UNVERIFIED',
    'authorized-looking B test login has independent nonce claim');
$appB->commit();
check167($countRows()===2,'separate nonce uses separate record');
$appA->beginTransaction();
$transient=$storeA->reserve($orgA,$binding,$nonce3,'deliver',300);
check167($transient->reasonCode==='NONCE_RESERVED_UNVERIFIED',
    'uncommitted claim is not durable and not a receipt');
$appA->rollBack();
check167($countRows()===2,'transaction rollback removes uncommitted nonce');
$appA->beginTransaction();
$afterRollback=$storeA->reserve($orgA,$binding,$nonce3,'deliver',300);
check167($afterRollback->reasonCode==='NONCE_RESERVED_UNVERIFIED',
    'rollback allows a subsequent first committed use, never an ACK');
$appA->commit();
check167($countRows()===3,'committed retry after rollback leaves a single nonce');

sleep(2);
$appB->beginTransaction();
$afterTtl=$storeB->reserve($orgB,$binding,$nonce1,'deliver',300);
check167($afterTtl->reasonCode==='NONCE_ALREADY_CONSUMED',
    'expired request TTL never permits a globally consumed nonce');
$appB->commit();
check167($countRows()===3,'expired replay does not reset history');

$appA->beginTransaction();
foreach([
    [$orgA,$binding,$nonce1,'delete',300],
    [$orgA,$binding,$nonce1,'deliver',0],
    [$orgA,$binding,$nonce1,'deliver',301],
    [$orgA,$binding,'77777777-7777-1777-8777-777777777777','deliver',30],
    [$orgA,$binding,'77777777-7777-4777-0777-777777777777','deliver',30],
] as $input) {
    $invalid=$storeA->reserve(...$input);
    check167($invalid->reasonCode==='INVALID_SYNTHETIC_REQUEST' &&
        withoutRights167($invalid),
        'malformed service request never reaches durable claim');
}
$appA->commit();

check167(deniedSql167(
    static fn()=>$appA->query('SELECT * FROM f167_lab.synthetic_crm_service_nonce_history'),
    '42501'),'service A login has no direct SELECT on global history');
check167(deniedSql167(
    static fn()=>$appB->query('SELECT * FROM f167_lab.synthetic_crm_service_nonce_history'),
    '42501'),'service B login has no direct SELECT on global history');
check167(deniedSql167(
    static fn()=>$appA->exec("DELETE FROM f167_lab.synthetic_crm_service_nonce_history"),
    '42501'),'service login cannot erase consumed nonce history');
check167(deniedSql167(static fn()=>$admin->exec(
    "DELETE FROM f167_lab.synthetic_crm_service_nonce_history"
),'P0001'),'even admin test cleanup is rejected by immutable-history trigger');
check167(deniedSql167(static fn()=>$admin->exec(
    "UPDATE f167_lab.synthetic_crm_service_nonce_history SET operation='lookup'"
),'P0001'),'mutation cannot repurpose nonce into different request operation');
check167($countRows()===3,'immutability attempts leave durable ledger unchanged');

putenv('SITES_F167_PG_LAB');
$denied=$storeA->reserve($orgA,$binding,$nonce1,'deliver',100);
check167($denied->reasonCode==='LAB_DISABLED' && withoutRights167($denied),
    'adapter remains disabled without explicit CI-only flag');
putenv('SITES_F167_PG_LAB=1');
check167(Assessment::safeCode('DELIVERED')==='UNEXPECTED_DATABASE_STATE',
    'unknown success-like database result cannot grant delivery');
$rejected=false;
try {new Assessment('ACK_VISITOR');}catch(InvalidArgumentException){$rejected=true;}
check167($rejected,'forged visitor acknowledgement reason cannot instantiate');

echo "$count F1-167 PostgreSQL18 globally durable CRM nonce assertions passed. ".
    "No PII, authenticated service, operational CRM, visitor ACK or deploy.\n";
