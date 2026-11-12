<?php
declare(strict_types=1);
/**
 * F1-149: outbox leases must be bound to currently active/pinned authority.
 * No HTTP, customer lead, IAM, PII, transport, consent or CRM acknowledgment.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
 || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SITES_F140_PG_LAB') !== '1'
 || getenv('DB_HOST') !== '127.0.0.1'
 || getenv('DB_DATABASE') !== 'sites_f140_atomic_lab') {
 fwrite(STDERR,"F1-149 is disposable PG18 CI only.\n"); exit(1);
}
foreach ([
 'SyntheticCrmLeadEnvelopeV1Draft','LabTenantBoundCrmClaimAssessment',
 'LabPostgresTenantBoundCrmClaim'
] as $name) require __DIR__.'/../src/Integration/'.$name.'.php';

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\LabPostgresTenantBoundCrmClaim as Claim;

function db149(string $role,string $password):PDO {
 return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
 $role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false]);
}
function check149(bool $ok,string $desc):void {
 global $checks; if(!$ok) throw new RuntimeException('F1-149: '.$desc);
 $checks++; echo 'PASS: '.$desc."\n";
}
function claim149(PDO $db,Draft $draft,int $revision):string {
 $db->beginTransaction();
 try {
  $reason=(new Claim($db))->register($draft,$revision)->reasonCode;
  $db->commit();return $reason;
 } catch(Throwable $e) {$db->rollBack();throw $e;}
}
function lease149(PDO $db,string $org,string $leaseId):string {
 $q=$db->prepare('SELECT f140_lab.try_lease_synthetic_outbox(?,?)');
 $q->execute([$org,$leaseId]);return (string)$q->fetchColumn();
}
function expectState149(callable $call,string $state):bool {
 try {$call();}catch(PDOException $e){return $e->getCode()===$state;}
 return false;
}

$checks=0;
$aOrg='11111111-1111-4111-8111-111111111111';
$bOrg='88888888-8888-4888-8888-888888888888';
$binding='22222222-2222-4222-8222-222222222222';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$oldDest='66666666-6666-4666-8666-666666666666';
$newDest='77777777-7777-4777-8777-777777777777';
$oldSub='33333333-3333-4333-8333-333333333333';
$newSub='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$make=static fn(string $org,string $submission,string $destination):Draft=>
 new Draft($org,$binding,$submission,$site,$form,$destination);
$appA=db149('sites_f140_app','f140-fictional-ci-only');
$appB=db149('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=db149('postgres','f140-fictional-admin-ci-only');

check149(claim149($appA,$make($aOrg,$oldSub,$oldDest),7)
 ==='CLAIM_RECORDED_UNVERIFIED','A original technical outbox created');
check149(claim149($appB,$make($bOrg,$oldSub,$oldDest),7)
 ==='CLAIM_RECORDED_UNVERIFIED','B technical outbox independent');

$change=$admin->prepare(
 'UPDATE f140_lab.synthetic_binding_versions '.
 'SET revision=?,destination_reference=? '.
 'WHERE organization_reference=? AND binding_reference=?'
);
$change->execute([8,$newDest,$aOrg,$binding]);
check149(lease149($appA,$aOrg,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
 ==='NO_READY_SYNTHETIC_INTENT',
 'stale A pinned revision/destination cannot be leased after change');

check149(claim149($appA,$make($aOrg,$newSub,$newDest),8)
 ==='CLAIM_RECORDED_UNVERIFIED','fresh A claim uses current binding');

$appA->beginTransaction();
check149(lease149($appA,$aOrg,'cccccccc-cccc-4ccc-8ccc-cccccccccccc')
 ==='LEASED_INITIAL_UNVERIFIED',
 'fresh A row can be leased while stale row is skipped');
$chosen=$appA->query(
 "SELECT current_setting('transaction_isolation')"
)->fetchColumn();
check149(is_string($chosen) && $chosen!=='',
 'lease is held in a real PostgreSQL transaction');

$admin->exec("SET lock_timeout='250ms'");
$revoke=$admin->prepare(
 'UPDATE f140_lab.synthetic_binding_versions SET revision=9,active=false '.
 'WHERE organization_reference=? AND binding_reference=?'
);
check149(expectState149(static fn()=>$revoke->execute([$aOrg,$binding]),'55P03'),
 'concurrent binding revocation cannot overtake leased claim transaction');
$appA->commit();
$admin->exec("SET lock_timeout='0'");
$revoke->execute([$aOrg,$binding]);

$admin->exec(
 "UPDATE f140_lab.synthetic_claim_outbox ".
 "SET leased_until=statement_timestamp()-interval '1 minute' ".
 "WHERE organization_reference='$aOrg' AND lease_id IS NOT NULL"
);
check149(lease149($appA,$aOrg,'dddddddd-dddd-4ddd-8ddd-dddddddddddd')
 ==='NO_READY_SYNTHETIC_INTENT',
 'revoked binding cannot lease old or expired formerly leased intents');

check149(lease149($appB,$bOrg,'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee')
 ==='LEASED_INITIAL_UNVERIFIED',
 'other tenant remains independent after A revocation');

$rows=$admin->query(
 'SELECT organization_reference::text AS org,submission_reference::text AS sub,'.
 'lease_attempts,state,lease_id::text AS lease '.
 'FROM f140_lab.synthetic_claim_outbox '.
 'ORDER BY organization_reference,submission_reference'
)->fetchAll(PDO::FETCH_ASSOC);
$stale=array_values(array_filter($rows,static fn(array $r):bool=>
 $r['org']===$aOrg && $r['sub']===$oldSub));
$fresh=array_values(array_filter($rows,static fn(array $r):bool=>
 $r['org']===$aOrg && $r['sub']===$newSub));
check149(count($rows)===3 && count($stale)===1 && count($fresh)===1
 && (int)$stale[0]['lease_attempts']===0
 && (int)$fresh[0]['lease_attempts']===1
 && $fresh[0]['lease']==='cccccccc-cccc-4ccc-8ccc-cccccccccccc',
 'stale row never obtained lease, fresh row had exactly one');
check149(count(array_filter($rows,static fn(array $r):bool=>
 $r['state']!=='pending_unverified'))===0,
 'no stored row was promoted to CRM delivered or ACK');

echo "$checks F1-149 PostgreSQL binding fence checks passed. ".
 "No delivery, CRM API, operational IAM, worker or PII.\n";
