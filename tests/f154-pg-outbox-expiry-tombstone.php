<?php

declare(strict_types=1);

/** F1-154 CI-only retention: delete expired technical outbox, keep identity. */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
 || getenv('GITHUB_ACTIONS')!=='true' || getenv('SITES_F140_PG_LAB')!=='1'
 || getenv('DB_HOST')!=='127.0.0.1'
 || getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
 fwrite(STDERR,"F1-154 requires isolated PG18 CI.\n"); exit(1);
}
foreach ([
 'SyntheticCrmLeadEnvelopeV1Draft','LabTenantBoundCrmClaimAssessment',
 'LabPostgresTenantBoundCrmClaim'
] as $name) require __DIR__.'/../src/Integration/'.$name.'.php';

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\LabPostgresTenantBoundCrmClaim as Claim;

function db154(string $role,string $password):PDO {
 return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
 $role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false]);
}
function check154(bool $condition,string $label):void {
 global $checks;
 if (!$condition)throw new RuntimeException('F1-154: '.$label);
 $checks++;
 echo 'PASS '.$label."\n";
}
function claim154(PDO $db,string $org,string $sub):string {
 $draft=new Draft($org,'22222222-2222-4222-8222-222222222222',$sub,
  '44444444-4444-4444-8444-444444444444',
  '55555555-5555-4555-8555-555555555555',
  '66666666-6666-4666-8666-666666666666');
 $db->beginTransaction();
 try {
  $reason=(new Claim($db))->register($draft,7)->reasonCode;
  $db->commit();return $reason;
 } catch(Throwable $e) {$db->rollBack();throw $e;}
}
function count154(PDO $admin,string $table):int {
 if (!in_array($table,['synthetic_claims','synthetic_claim_outbox'],true))
  throw new RuntimeException('Invalid fixture table name');
 return (int)$admin->query('SELECT count(*) FROM f140_lab.'.$table)->fetchColumn();
}
function purge154(PDO $db,int $limit):int {
 $q=$db->prepare('SELECT f140_lab.purge_expired_synthetic_outbox(?)');
 $q->execute([$limit]);return (int)$q->fetchColumn();
}
function state154(callable $fn,string $state):bool {
 try {$fn();}catch(PDOException $e){return $e->getCode()===$state;}
 return false;
}

$checks=0;
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$s1='33333333-3333-4333-8333-333333333333';
$s2='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$s3='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$a=db154('sites_f140_app','f140-fictional-ci-only');
$b=db154('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=db154('postgres','f140-fictional-admin-ci-only');

check154(claim154($a,$orgA,$s1)==='CLAIM_RECORDED_UNVERIFIED',
 'A first technical claim created');
check154(claim154($a,$orgA,$s2)==='CLAIM_RECORDED_UNVERIFIED',
 'A second technical claim created');
check154(claim154($b,$orgB,$s1)==='CLAIM_RECORDED_UNVERIFIED',
 'B same UUID creates independent technical claim');
check154(count154($admin,'synthetic_claims')===3
 && count154($admin,'synthetic_claim_outbox')===3,
 'three immutable identities and three outbox intents exist');

$age=$admin->prepare(
 'UPDATE f140_lab.synthetic_claims '.
 "SET created_at=statement_timestamp()-interval '433 hours' ".
 'WHERE organization_reference=?'
);
$age->execute([$orgA]);
check154($age->rowCount()===2,'CI owner simulates A original T0 beyond 432h');
$original=$admin->query(
 "SELECT submission_reference::text AS sub,created_at::text AS t0 ".
 "FROM f140_lab.synthetic_claims WHERE organization_reference='$orgA' ".
 "ORDER BY submission_reference"
)->fetchAll(PDO::FETCH_ASSOC);
check154(count($original)===2,'original identity ledger timestamps retained');

check154(state154(static fn()=>purge154($a,1),'42501'),
 'restricted app cannot invoke owner-only purge');
check154(state154(static fn()=>purge154($b,1),'42501'),
 'other app login cannot purge a tenant');
check154(state154(static fn()=>purge154($admin,0),'22023'),
 'owner cannot invoke purge with zero batch size');
check154(purge154($admin,1)===1,'bounded owner purge removes only one expired outbox');
check154(count154($admin,'synthetic_claims')===3
 && count154($admin,'synthetic_claim_outbox')===2,
 'identity history retained when one expired outbox is removed');
check154(purge154($admin,1)===1,'second batch removes second expired intent');
check154(purge154($admin,1)===0,'fresh tenant B cannot be purged before T0+432h');
check154(count154($admin,'synthetic_claims')===3
 && count154($admin,'synthetic_claim_outbox')===1,
 'all A outbox entries removed, three identity tombstones remain');

check154(claim154($a,$orgA,$s1)==='EXISTING_CLAIM_UNVERIFIED',
 'replay after expiry/purge cannot create new first-persisted claim');
check154(count154($admin,'synthetic_claim_outbox')===1,
 'same submission replay cannot recreate purged outbox and reset retry clock');
$after=$admin->query(
 "SELECT submission_reference::text AS sub,created_at::text AS t0 ".
 "FROM f140_lab.synthetic_claims WHERE organization_reference='$orgA' ".
 "ORDER BY submission_reference"
)->fetchAll(PDO::FETCH_ASSOC);
check154($after===$original,
 'original server-generated claim timestamps remain fixed across purge/replay');

check154(claim154($a,$orgA,$s3)==='CLAIM_RECORDED_UNVERIFIED',
 'new, distinct A submission still records a new technical identity');
check154(count154($admin,'synthetic_claims')===4
 && count154($admin,'synthetic_claim_outbox')===2,
 'new technical intent does not recreate any expired identity');

echo "$checks F1-154 PG18 retention/tombstone checks passed. ".
 "No customer PII, real deletion policy, backup purge, CRM or deploy.\n";
