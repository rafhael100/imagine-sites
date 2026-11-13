<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
 || getenv('GITHUB_ACTIONS')!=='true'
 || getenv('SITES_F140_PG_LAB')!=='1'
 || getenv('DB_HOST')!=='127.0.0.1'
 || getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
 fwrite(STDERR,"F1-147 only on disposable CI PG18.\n");
 exit(1);
}
foreach ([
 'SyntheticCrmLeadEnvelopeV1Draft',
 'LabTenantBoundCrmClaimAssessment',
 'LabPostgresTenantBoundCrmClaim',
 'LabSyntheticOutboxLeaseAssessment',
 'LabPostgresSyntheticOutboxLease'
] as $name) {
 require __DIR__.'/../src/Integration/'.$name.'.php';
}
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\LabPostgresTenantBoundCrmClaim as Claim;
use Imagine\Sites\Integration\LabPostgresSyntheticOutboxLease as Lease;
use Imagine\Sites\Integration\LabSyntheticOutboxLeaseAssessment as Assessment;

function db147(string $role,string $pw):PDO {
 return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
  $role,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
   PDO::ATTR_EMULATE_PREPARES=>false]);
}
function check147(bool $ok,string $what):void {
 global $checks;
 if(!$ok) throw new RuntimeException('F1-147: '.$what);
 ++$checks;
}
function noAuthority147(Assessment $r):bool {
 return !$r->canSendLeadToCrm() && !$r->canAcknowledgeVisitor()
    && !$r->canMarkDelivered() && !$r->canRetryAutomatically();
}
$checks=0;
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$sub='33333333-3333-4333-8333-333333333333';
$binding='22222222-2222-4222-8222-222222222222';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$dest='66666666-6666-4666-8666-666666666666';
$draft=static fn(string $org):Draft=>
 new Draft($org,$binding,$sub,$site,$form,$dest);
$a=db147('sites_f140_app','f140-fictional-ci-only');
$b=db147('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=db147('postgres','f140-fictional-admin-ci-only');
$leaseA=new Lease($a);
$leaseB=new Lease($b);

check147($leaseA->tryLease($orgA,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
 ->reasonCode==='TRANSACTION_REQUIRED','no transaction denies lease');
$a->beginTransaction();
check147($leaseA->tryLease($orgA,'invalid-id')->reasonCode
 ==='INVALID_SYNTHETIC_LEASE','bad UUID denies before query');
check147((new Claim($a))->register($draft($orgA),7)->reasonCode
 ==='CLAIM_RECORDED_UNVERIFIED','A creates technical claim');
$r=$leaseA->tryLease($orgA,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
check147($r->reasonCode==='LEASED_INITIAL_UNVERIFIED' && noAuthority147($r),
 'PHP receives initial lease with no send/ACK permissions');
$r2=$leaseA->tryLease($orgA,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
check147($r2->reasonCode==='NO_READY_SYNTHETIC_INTENT' && noAuthority147($r2),
 'repeat PHP lease has no ready intent and no operational authority');

$flag=getenv('SITES_F140_PG_LAB');
putenv('SITES_F140_PG_LAB=0');
try {
 check147($leaseA->tryLease($orgA,'cccccccc-cccc-4ccc-8ccc-cccccccccccc')
  ->reasonCode==='LAB_DISABLED','lab flag disabled fails closed');
} finally {
 if($flag===false)putenv('SITES_F140_PG_LAB');
 else putenv('SITES_F140_PG_LAB='.$flag);
}
$a->commit();

$b->beginTransaction();
check147($leaseB->tryLease($orgA,'dddddddd-dddd-4ddd-8ddd-dddddddddddd')
 ->reasonCode==='DB_LOGIN_SCOPE_DENIED','B cannot lease A queue');
check147((new Claim($b))->register($draft($orgB),7)->reasonCode
 ==='CLAIM_RECORDED_UNVERIFIED','B creates independent technical claim');
$rb=$leaseB->tryLease($orgB,'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee');
check147($rb->reasonCode==='LEASED_INITIAL_UNVERIFIED' && noAuthority147($rb),
 'B lease remains explicitly unverified');
$b->commit();

$admin->exec(
 "UPDATE f140_lab.synthetic_login_scope SET revision=2,active=false ".
 "WHERE login_name='sites_f140_app'"
);
$a->beginTransaction();
$revoked=$leaseA->tryLease($orgA,'ffffffff-ffff-4fff-8fff-ffffffffffff');
check147($revoked->reasonCode==='DB_LOGIN_SCOPE_DENIED'
 && noAuthority147($revoked),'revocation denies lease in existing PDO session');
$a->commit();

check147(Assessment::safeCode('DELIVERED')==='UNEXPECTED_DATABASE_STATE',
 'unrecognized delivered code cannot be promoted');
check147(noAuthority147(new Assessment(Assessment::safeCode('ACK'))),
 'unknown ACK cannot grant delivery');
check147((int)$admin->query(
  "SELECT count(*) FROM f140_lab.synthetic_claim_outbox ".
  "WHERE state='pending_unverified' AND lease_attempts=1"
 )->fetchColumn()===2,
 'both leased metadata rows are still only pending/unverified');

echo "$checks F1-147 PHP lease bridge checks passed. No actual worker, ".
 "CRM send, ACK, PII, retry or deployment.\n";
