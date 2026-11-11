<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
 || getenv('GITHUB_ACTIONS')!=='true'
 || getenv('SITES_F140_PG_LAB')!=='1'
 || getenv('DB_HOST')!=='127.0.0.1'
 || getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
 fwrite(STDERR,"F1-145 only runs against disposable PG18 CI.\n");
 exit(1);
}
foreach(['SyntheticCrmLeadEnvelopeV1Draft','LabTenantBoundCrmClaimAssessment',
 'LabPostgresTenantBoundCrmClaim'] as $file) {
 require __DIR__.'/../src/Integration/'.$file.'.php';
}
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\LabPostgresTenantBoundCrmClaim as Claim;

function f145Db(string $user,string $pw):PDO {
 return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
  $user,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
   PDO::ATTR_EMULATE_PREPARES=>false]);
}
function f145Check(bool $condition,string $message):void {
 global $checks;
 if(!$condition)throw new RuntimeException('F1-145: '.$message);
 $checks++;
 echo "PASS: $message\n";
}
function f145SqlState(callable $callback,string $state):bool {
 try {$callback();} catch(PDOException $error) {
  return $error->getCode()===$state;
 }
 return false;
}
function f145Counts(PDO $admin):array {
 return [
  (int)$admin->query('SELECT count(*) FROM f140_lab.synthetic_claims')->fetchColumn(),
  (int)$admin->query('SELECT count(*) FROM f140_lab.synthetic_claim_outbox')->fetchColumn(),
 ];
}
$checks=0;
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$binding='22222222-2222-4222-8222-222222222222';
$sub1='33333333-3333-4333-8333-333333333333';
$sub2='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$dest='66666666-6666-4666-8666-666666666666';
$draft=static fn(string $org,string $sub,string $destination=$dest):Draft =>
 new Draft($org,$binding,$sub,$site,$form,$destination);
$appA=f145Db('sites_f140_app','f140-fictional-ci-only');
$appB=f145Db('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=f145Db('postgres','f140-fictional-admin-ci-only');
$bridgeA=new Claim($appA);
$bridgeB=new Claim($appB);

$appA->beginTransaction();
$first=$bridgeA->register($draft($orgA,$sub1),7);
f145Check($first->reasonCode==='CLAIM_RECORDED_UNVERIFIED'
 && !$first->canAcceptLead() && !$first->canAcknowledgeVisitor()
 && !$first->canDeliverToCrm() && !$first->canRetryAutomatically(),
 'new technical claim never grants actual CRM delivery or ACK');
f145Check(f145Counts($admin)===[0,0],
 'separate admin connection cannot read uncommitted claim or outbox');
$appA->commit();
f145Check(f145Counts($admin)===[1,1],
 'commit persists claim and outbox together');

$out=$admin->query(
 "SELECT state,destination_reference::text AS destination,created_at::text AS t0 ".
 "FROM f140_lab.synthetic_claim_outbox"
)->fetch(PDO::FETCH_ASSOC);
f145Check($out['state']==='pending_unverified'
 && $out['destination']===$dest && is_string($out['t0']) && $out['t0']!=='',
 'durable technical outbox keeps original destination and DB timestamp, not delivered');

$appA->beginTransaction();
f145Check($bridgeA->register($draft($orgA,$sub1),7)->reasonCode
 ==='EXISTING_CLAIM_UNVERIFIED','replayed submission does not create new outbox intent');
$appA->commit();
f145Check(f145Counts($admin)===[1,1],'replay leaves exactly one outbox row');

$appB->beginTransaction();
f145Check($bridgeB->register($draft($orgB,$sub1),7)->reasonCode
 ==='CLAIM_RECORDED_UNVERIFIED','other tenant has independent technical claim');
$appB->rollBack();
f145Check(f145Counts($admin)===[1,1],
 'rollback removes both claim and outbox for other tenant');

$appB->beginTransaction();
f145Check($bridgeB->register($draft($orgB,$sub1),7)->reasonCode
 ==='CLAIM_RECORDED_UNVERIFIED','rolled-back tenant can retry as first claim');
$appB->commit();
f145Check(f145Counts($admin)===[2,2],'two tenant submissions create two physical outbox rows');

$appA->beginTransaction();
f145Check($bridgeA->register($draft($orgB,$sub2),7)->reasonCode
 ==='DB_LOGIN_SCOPE_DENIED','forged tenant cannot enqueue technical message');
$appA->commit();
f145Check(f145Counts($admin)===[2,2],'cross-tenant attempt leaves outbox unchanged');

foreach([$appA,$appB] as $db) {
 f145Check(f145SqlState(
  static fn()=>$db->query('SELECT * FROM f140_lab.synthetic_claim_outbox'),
  '42501'),'app role cannot inspect outbox rows directly');
}
f145Check(f145SqlState(static fn()=>$admin->exec(
 "UPDATE f140_lab.synthetic_claim_outbox SET state='delivered'"
),'23514'),'even admin fixture cannot mark outbox delivered by state update');

$admin->exec(
 "UPDATE f140_lab.synthetic_login_scope SET revision=2,active=false ".
 "WHERE login_name='sites_f140_app'"
);
$appA->beginTransaction();
f145Check($bridgeA->register($draft($orgA,$sub2),7)->reasonCode
 ==='DB_LOGIN_SCOPE_DENIED','revoked A login cannot create technical outbox');
$appA->commit();
f145Check(f145Counts($admin)===[2,2],
 'revocation leaves no new claim or outbox row');

echo "$checks F1-145 PG18 atomic technical-outbox assertions passed. ".
  "No lead payload, real worker, CRM delivery or ACK.\n";
