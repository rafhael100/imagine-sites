<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
 || getenv('GITHUB_ACTIONS')!=='true'
 || getenv('SITES_F140_PG_LAB')!=='1'
 || getenv('DB_HOST')!=='127.0.0.1'
 || getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
 fwrite(STDERR,"F1-146 disposable PostgreSQL 18 CI only.\n");
 exit(1);
}
foreach(['SyntheticCrmLeadEnvelopeV1Draft','LabTenantBoundCrmClaimAssessment',
 'LabPostgresTenantBoundCrmClaim'] as $name)
 require __DIR__.'/../src/Integration/'.$name.'.php';

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\LabPostgresTenantBoundCrmClaim as Claim;

function db146(string $role,string $pw):PDO {
 return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
  $role,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
   PDO::ATTR_EMULATE_PREPARES=>false]);
}
function check146(bool $ok,string $what):void {
 global $checks;
 if(!$ok)throw new RuntimeException('F1-146: '.$what);
 $checks++;
 echo 'PASS: '.$what."\n";
}
function lease146(PDO $db,string $org,string $leaseId):string {
 $q=$db->prepare('SELECT f140_lab.try_lease_synthetic_outbox(?,?)');
 $q->execute([$org,$leaseId]);
 return (string)$q->fetchColumn();
}
function newClaim146(PDO $db,string $org,string $sub):string {
 $draft=new Draft(
  $org,'22222222-2222-4222-8222-222222222222',$sub,
  '44444444-4444-4444-8444-444444444444',
  '55555555-5555-4555-8555-555555555555',
  '66666666-6666-4666-8666-666666666666'
 );
 $db->beginTransaction();
 try {
  $result=(new Claim($db))->register($draft,7)->reasonCode;
  $db->commit();
  return $result;
 } catch(Throwable $error) {
  $db->rollBack();
  throw $error;
 }
}
$checks=0;
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$sub='33333333-3333-4333-8333-333333333333';
$appA=db146('sites_f140_app','f140-fictional-ci-only');
$appA2=db146('sites_f140_app','f140-fictional-ci-only');
$appB=db146('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=db146('postgres','f140-fictional-admin-ci-only');

check146(newClaim146($appA,$orgA,$sub)==='CLAIM_RECORDED_UNVERIFIED',
 'A created synthetic technical intent');
check146(newClaim146($appB,$orgB,$sub)==='CLAIM_RECORDED_UNVERIFIED',
 'B created independent synthetic technical intent');

$appA->beginTransaction();
check146(lease146($appA,$orgA,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
 ==='LEASED_INITIAL_UNVERIFIED','first A lease classifies age under 72h');
$appA2->beginTransaction();
check146(lease146($appA2,$orgA,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
 ==='NO_READY_SYNTHETIC_INTENT',
 'second same-tenant session SKIP LOCKED cannot reserve in-flight row');
$appA2->rollBack();
$appA->commit();

$appA2->beginTransaction();
check146(lease146($appA2,$orgA,'cccccccc-cccc-4ccc-8ccc-cccccccccccc')
 ==='NO_READY_SYNTHETIC_INTENT',
 'committed lease prevents immediate duplicate');
$appA2->commit();

check146(lease146($appA,$orgB,'dddddddd-dddd-4ddd-8ddd-dddddddddddd')
 ==='DB_LOGIN_SCOPE_DENIED',
 'A login cannot reserve B queue');
check146(lease146($appB,$orgA,'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee')
 ==='DB_LOGIN_SCOPE_DENIED',
 'B login cannot reserve A queue');

$admin->exec(
 "UPDATE f140_lab.synthetic_claims ".
 "SET created_at=statement_timestamp()-interval '73 hours' ".
 "WHERE organization_reference='$orgB'"
);
$appB->beginTransaction();
check146(lease146($appB,$orgB,'ffffffff-ffff-4fff-8fff-ffffffffffff')
 ==='LEASED_RECOVERY_UNVERIFIED',
 'age 73h classifies only a technical recovery window, no sending');
$appB->commit();

// F1-148: the lease must end at original T0+432h, not at now+5min,
// even when the intent is a few seconds away from the absolute cutoff.
$admin->exec(
 "UPDATE f140_lab.synthetic_claims ".
 "SET created_at=statement_timestamp()-interval '431 hours 59 minutes' ".
 "WHERE organization_reference='$orgB'"
);
$admin->exec(
 "UPDATE f140_lab.synthetic_claim_outbox ".
 "SET leased_until=statement_timestamp()-interval '1 minute' ".
 "WHERE organization_reference='$orgB'"
);
$appB->beginTransaction();
check146(lease146($appB,$orgB,'33333333-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
 ==='LEASED_RECOVERY_UNVERIFIED',
 'near the 432h cutoff a fresh technical lease is still diagnostic only');
$appB->commit();
$boundary=$admin->query(
 "SELECT (o.leased_until=c.created_at+interval '432 hours') AS at_deadline,".
 " extract(epoch from (o.leased_until-statement_timestamp())) AS seconds_left".
 " FROM f140_lab.synthetic_claim_outbox o".
 " JOIN f140_lab.synthetic_claims c ON c.organization_reference=o.organization_reference".
 " AND c.submission_reference=o.submission_reference".
 " WHERE o.organization_reference='$orgB'"
)->fetch(PDO::FETCH_ASSOC);
check146(in_array($boundary['at_deadline'],[true,'t','1',1],true)
 && (float)$boundary['seconds_left']>0
 && (float)$boundary['seconds_left']<61,
 'technical lease is capped at durable T0+432h rather than extended five minutes');

$admin->exec(
 "UPDATE f140_lab.synthetic_claims ".
 "SET created_at=statement_timestamp()-interval '433 hours' ".
 "WHERE organization_reference='$orgB'"
);
$admin->exec(
 "UPDATE f140_lab.synthetic_claim_outbox ".
 "SET leased_until=statement_timestamp()-interval '1 hour' ".
 "WHERE organization_reference='$orgB'"
);
check146(lease146($appB,$orgB,'11111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
 ==='NO_READY_SYNTHETIC_INTENT',
 'technical outbox older than 432h cannot be leased');

$rows=$admin->query(
 'SELECT organization_reference::text AS org,state,lease_attempts,lease_id::text AS lease '.
 'FROM f140_lab.synthetic_claim_outbox ORDER BY organization_reference'
)->fetchAll(PDO::FETCH_ASSOC);
check146(count($rows)===2
 && $rows[0]['org']===$orgA && $rows[1]['org']===$orgB
 && $rows[0]['state']==='pending_unverified'
 && $rows[1]['state']==='pending_unverified'
 && (int)$rows[0]['lease_attempts']===1
 && (int)$rows[1]['lease_attempts']===2,
 'both intents remain unverified; recovery lease increments B attempt count');

$admin->exec(
 "UPDATE f140_lab.synthetic_login_scope SET revision=2,active=false ".
 "WHERE login_name='sites_f140_app'"
);
check146(lease146($appA,$orgA,'22222222-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
 ==='DB_LOGIN_SCOPE_DENIED',
 'revoked A DB login cannot reserve technical outbox');

check146(lease146($appB,$orgB,'00000000-0000-0000-0000-000000000000')
 ==='INVALID_SYNTHETIC_LEASE','zero lease id rejected');
echo "$checks F1-146 PG18 lease, 72h/432h, concurrency and tenant scope checks passed. ".
 "No worker, PII, CRM delivery, automatic retry or ACK.\n";
