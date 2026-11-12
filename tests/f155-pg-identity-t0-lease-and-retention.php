<?php

declare(strict_types=1);

/** F1-155: original first-persisted T0 controls every lease and purge. */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
 || getenv('GITHUB_ACTIONS')!=='true' || getenv('SITES_F140_PG_LAB')!=='1'
 || getenv('DB_HOST')!=='127.0.0.1'
 || getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
 fwrite(STDERR,"F1-155 only operates on disposable PG18 CI.\n");exit(1);
}
foreach ([
 'SyntheticCrmLeadEnvelopeV1Draft','LabTenantBoundCrmClaimAssessment',
 'LabPostgresTenantBoundCrmClaim'
] as $file)require __DIR__.'/../src/Integration/'.$file.'.php';
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\LabPostgresTenantBoundCrmClaim as Claim;

function db155(string $role,string $pw):PDO {
 return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
 $role,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false]);
}
function check155(bool $ok,string $why):void {
 global $checks;
 if(!$ok)throw new RuntimeException('F1-155: '.$why);
 $checks++;
 echo 'PASS '.$why."\n";
}
function claim155(PDO $db,string $org,string $sub):string {
 $candidate=new Draft($org,'22222222-2222-4222-8222-222222222222',
 $sub,'44444444-4444-4444-8444-444444444444',
 '55555555-5555-4555-8555-555555555555',
 '66666666-6666-4666-8666-666666666666');
 $db->beginTransaction();
 try {
  $res=(new Claim($db))->register($candidate,7)->reasonCode;
  $db->commit();
  return $res;
 } catch(Throwable $e){$db->rollBack();throw $e;}
}
function lease155(PDO $db,string $org,string $id):string {
 $q=$db->prepare('SELECT f140_lab.try_lease_synthetic_outbox(?,?)');
 $q->execute([$org,$id]);return (string)$q->fetchColumn();
}
function purge155(PDO $admin):int {
 return (int)$admin->query('SELECT f140_lab.purge_expired_synthetic_outbox(10)')
   ->fetchColumn();
}
$checks=0;
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$sub='33333333-3333-4333-8333-333333333333';
$a=db155('sites_f140_app','f140-fictional-ci-only');
$b=db155('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=db155('postgres','f140-fictional-admin-ci-only');

check155(claim155($a,$orgA,$sub)==='CLAIM_RECORDED_UNVERIFIED',
 'A created first durable identity and outbox');
check155(claim155($b,$orgB,$sub)==='CLAIM_RECORDED_UNVERIFIED',
 'B created separate identity with same submission UUID');

$admin->exec(
 "UPDATE f140_lab.synthetic_claim_outbox ".
 "SET created_at=statement_timestamp()+interval '48 hours' ".
 "WHERE organization_reference='$orgA'"
);
$admin->exec(
 "UPDATE f140_lab.synthetic_claims ".
 "SET created_at=statement_timestamp()-interval '433 hours' ".
 "WHERE organization_reference='$orgA'"
);
check155(lease155($a,$orgA,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
 ==='NO_READY_SYNTHETIC_INTENT',
 'recent/future outbox timestamp cannot restart expired original identity T0');

$admin->exec(
 "UPDATE f140_lab.synthetic_claims ".
 "SET created_at=statement_timestamp()-interval '431 hours 59 minutes' ".
 "WHERE organization_reference='$orgA'"
);
$a->beginTransaction();
check155(lease155($a,$orgA,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
 ==='LEASED_RECOVERY_UNVERIFIED',
 'original T0 just under 432h permits diagnostic recovery classification');
$a->commit();
$attested=$admin->query(
 "SELECT o.created_at > statement_timestamp() AS future_outbox,".
 " (o.leased_until=c.created_at+interval '432 hours') AS capped".
 " FROM f140_lab.synthetic_claim_outbox o JOIN f140_lab.synthetic_claims c".
 " ON c.organization_reference=o.organization_reference".
 " AND c.submission_reference=o.submission_reference".
 " WHERE o.organization_reference='$orgA'"
)->fetch(PDO::FETCH_ASSOC);
check155(in_array($attested['future_outbox'],[true,'t','1',1],true)
 && in_array($attested['capped'],[true,'t','1',1],true),
 'lease deadline uses original identity T0 despite a future outbox timestamp');

$admin->exec(
 "UPDATE f140_lab.synthetic_claims ".
 "SET created_at=statement_timestamp()-interval '433 hours' ".
 "WHERE organization_reference='$orgA'"
);
check155(lease155($a,$orgA,'cccccccc-cccc-4ccc-8ccc-cccccccccccc')
 ==='NO_READY_SYNTHETIC_INTENT',
 'after 432h the original identity is expired even if outbox appears recent');
check155(purge155($admin)===1,
 'owner purge removes expired A outbox based on same original claim T0');
check155(claim155($a,$orgA,$sub)==='EXISTING_CLAIM_UNVERIFIED',
 'retry cannot create another outbox or reset first persistence clock');

check155(lease155($b,$orgB,'dddddddd-dddd-4ddd-8ddd-dddddddddddd')
 ==='LEASED_INITIAL_UNVERIFIED',
 'tenant B fresh technical intent remains independent');
check155(purge155($admin)===0,'owner cannot purge fresh tenant B');
check155(
 (int)$admin->query('SELECT count(*) FROM f140_lab.synthetic_claims')->fetchColumn()===2
 && (int)$admin->query('SELECT count(*) FROM f140_lab.synthetic_claim_outbox')->fetchColumn()===1,
 'two permanent technical identities but only unexpired B outbox remain');
echo "$checks F1-155 T0/lease/retention invariants passed. ".
 "No PII, actual lead retry, API, CRM receipt or production purge.\n";
