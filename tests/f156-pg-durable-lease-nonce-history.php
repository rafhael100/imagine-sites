<?php
declare(strict_types=1);

/**
 * F1-156: unique technical lease nonce persists beyond outbox replacement
 * and expiry. Fictional CI metadata only, never CRM auth or delivery.
 */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
 || getenv('GITHUB_ACTIONS')!=='true' || getenv('SITES_F140_PG_LAB')!=='1'
 || getenv('DB_HOST')!=='127.0.0.1'
 || getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
 fwrite(STDERR,"F1-156 requires disposable PG18 CI.\n");exit(1);
}
foreach ([
 'SyntheticCrmLeadEnvelopeV1Draft','LabTenantBoundCrmClaimAssessment',
 'LabPostgresTenantBoundCrmClaim'
] as $f)require __DIR__.'/../src/Integration/'.$f.'.php';
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\LabPostgresTenantBoundCrmClaim as Claim;

function db156(string $role,string $pw):PDO {
 return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
 $role,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false]);
}
function check156(bool $ok,string $desc):void {
 global $checks; if(!$ok)throw new RuntimeException('F1-156: '.$desc);
 $checks++;
 echo 'PASS: '.$desc."\n";
}
function claim156(PDO $db,string $org,string $submission):string {
 $draft=new Draft($org,'22222222-2222-4222-8222-222222222222',
 $submission,'44444444-4444-4444-8444-444444444444',
 '55555555-5555-4555-8555-555555555555',
 '66666666-6666-4666-8666-666666666666');
 $db->beginTransaction();
 try {
  $r=(new Claim($db))->register($draft,7)->reasonCode;
  $db->commit();return $r;
 } catch(Throwable $e){$db->rollBack();throw $e;}
}
function lease156(PDO $db,string $org,string $leaseId):string {
 $q=$db->prepare('SELECT f140_lab.try_lease_synthetic_outbox(?,?)');
 $q->execute([$org,$leaseId]);return (string)$q->fetchColumn();
}
function nonceCount156(PDO $db):int {
 return (int)$db->query('SELECT count(*) FROM f140_lab.synthetic_lease_nonce_history')
  ->fetchColumn();
}
function denied156(callable $fn,string $state):bool {
 try{$fn();}catch(PDOException $e){return $e->getCode()===$state;}
 return false;
}
$checks=0;
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$s1='33333333-3333-4333-8333-333333333333';
$s2='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$s3='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$n1='11111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$n2='22222222-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$n3='33333333-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$n4='44444444-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$n5='55555555-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$a=db156('sites_f140_app','f140-fictional-ci-only');
$b=db156('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=db156('postgres','f140-fictional-admin-ci-only');

check156(claim156($a,$orgA,$s1)==='CLAIM_RECORDED_UNVERIFIED',
 'first A synthetic metadata claim');
check156(claim156($a,$orgA,$s2)==='CLAIM_RECORDED_UNVERIFIED',
 'second A synthetic metadata claim');
check156(claim156($b,$orgB,$s1)==='CLAIM_RECORDED_UNVERIFIED',
 'B independent claim with same submission UUID');

check156(lease156($a,$orgA,$n1)==='LEASED_INITIAL_UNVERIFIED',
 'first A lease reserves nonce N1');
check156(lease156($a,$orgA,$n2)==='LEASED_INITIAL_UNVERIFIED',
 'second A lease reserves nonce N2');
check156(nonceCount156($admin)===2,
 'two distinct technical nonces durably registered');

$admin->exec(
 "UPDATE f140_lab.synthetic_claim_outbox ".
 "SET leased_until=statement_timestamp()-interval '1 minute' ".
 "WHERE organization_reference='$orgA' AND submission_reference='$s1'"
);
check156(lease156($a,$orgA,$n3)==='LEASED_INITIAL_UNVERIFIED',
 'expired s1 lease rotates from N1 to N3');
check156(nonceCount156($admin)===3,
 'historical N1 persisted after live outbox now carries N3');

$admin->exec(
 "UPDATE f140_lab.synthetic_claim_outbox ".
 "SET leased_until=statement_timestamp()-interval '1 minute' ".
 "WHERE organization_reference='$orgA' AND submission_reference='$s1'"
);
check156(lease156($a,$orgA,$n1)==='LEASE_NONCE_REUSED',
 'old N1 cannot be replayed after outbox lease ID is overwritten');
check156(nonceCount156($admin)===3,
 'nonce replay does not insert another history row');

check156(lease156($b,$orgB,$n1)==='LEASE_NONCE_REUSED',
 'nonce reuse across another synthetic organization also denied');
check156(lease156($b,$orgB,$n4)==='LEASED_INITIAL_UNVERIFIED',
 'fresh independent B nonce may be classified but never deliver');
check156(nonceCount156($admin)===4,
 'only four unique historical nonce IDs are recorded');

check156(denied156(
 static fn()=>$a->query('SELECT * FROM f140_lab.synthetic_lease_nonce_history'),
 '42501'),'application login cannot inspect nonce tombstones');

$admin->exec(
 "UPDATE f140_lab.synthetic_claims ".
 "SET created_at=statement_timestamp()-interval '433 hours' ".
 "WHERE organization_reference='$orgA'"
);
check156((int)$admin->query(
 'SELECT f140_lab.purge_expired_synthetic_outbox(100)'
)->fetchColumn()===2,'expired A outboxes can be purged despite nonce history');
check156(nonceCount156($admin)===4,
 'nonce tombstones persist after all A outbox rows are deleted');

check156(claim156($a,$orgA,$s3)==='CLAIM_RECORDED_UNVERIFIED',
 'A genuinely new submission receives separate outbox');
check156(lease156($a,$orgA,$n1)==='LEASE_NONCE_REUSED',
 'N1 cannot be recycled even after its original outbox was deleted');
$attempts=$admin->query(
 "SELECT lease_attempts FROM f140_lab.synthetic_claim_outbox ".
 "WHERE organization_reference='$orgA' AND submission_reference='$s3'"
)->fetchColumn();
check156((int)$attempts===0,
 'nonce replay cannot consume or mutate new outbox attempt count');
check156(lease156($a,$orgA,$n5)==='LEASED_INITIAL_UNVERIFIED',
 'new N5 is recorded only as a technical diagnostic lease');
check156(nonceCount156($admin)===5,
 'five nonces retained without reuse across expiry/replay/purge');

$bad=$admin->query(
 "SELECT count(*) FROM f140_lab.synthetic_claim_outbox ".
 "WHERE state<>'pending_unverified'"
)->fetchColumn();
check156((int)$bad===0,'no technical lease can imply CRM delivery or ACK');

echo "$checks F1-156 PG18 lease nonce history checks passed. ".
 "No customer, PII, Portal IAM, transport or CRM confirmation.\n";
