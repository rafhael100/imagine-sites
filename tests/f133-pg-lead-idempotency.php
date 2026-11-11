<?php

declare(strict_types=1);

/**
 * F1-133: physically test PostgreSQL18 uniqueness and row-level security.
 * NO lead-specific delivery/ACK, no HTTP, no production table, no PII.
 * The tenant GUC is synthetic and NOT an IAM assertion.
 */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F133_LEAD_PG_LAB')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='sites_f133_lead_lab') {
    fwrite(STDERR,"F1-133 only runs with disposable CI PostgreSQL18 opt-in.\n");
    exit(1);
}

require __DIR__.'/../src/Integration/SyntheticCrmLeadEnvelopeV1Draft.php';
require __DIR__.'/../src/Integration/SyntheticCrmLeadLedgerIntentV1.php';
require __DIR__.'/../src/Integration/SyntheticCrmLeadMetadataReadbackAssessment.php';
require __DIR__.'/../src/Integration/LabPostgresCrmLeadMetadataReadback.php';

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\SyntheticCrmLeadLedgerIntentV1 as Intent;
use Imagine\Sites\Integration\LabPostgresCrmLeadMetadataReadback as Readback;

$checks=0;
function f133Check(bool $ok,string $reason):void {
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL: '.$reason);
    ++$checks;
    echo 'PASS: '.$reason."\n";
}
function f133Db():PDO {
    $db=new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=sites_f133_lead_lab',
        'sites_f133_lead_app','f133-fictional-disposable-ci-only',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $identity=$db->query("SELECT current_user AS role, current_database() AS db,
        current_setting('row_security') AS rls")->fetch(PDO::FETCH_ASSOC);
    if($identity['role']!=='sites_f133_lead_app' ||
       $identity['db']!=='sites_f133_lead_lab' || $identity['rls']!=='on') {
        throw new RuntimeException('Unsafe F1-133 client context.');
    }
    return $db;
}
function f133Begin(PDO $db,string $org):void {
    $db->beginTransaction();
    $s=$db->prepare("SELECT set_config('app.tenant_id', ?, true)");
    $s->execute([$org]);
}
function f133Insert(PDO $db,Draft $draft):bool {
    $stmt=$db->prepare("INSERT INTO f133_lead.synthetic_metadata_claims
      (organization_reference,submission_reference,binding_reference,
       site_reference,form_reference,destination_reference)
      VALUES (?,?,?,?,?,?)
      ON CONFLICT (organization_reference,submission_reference) DO NOTHING
      RETURNING submission_reference");
    $stmt->execute([
      $draft->organizationReference,$draft->submissionReference,
      $draft->bindingReference,$draft->siteReference,
      $draft->formReference,$draft->destinationReference
    ]);
    return $stmt->fetchColumn()!==false;
}
function f133Rows(PDO $db):array {
    return $db->query("SELECT organization_reference::text AS org,
      submission_reference::text AS submission,
      binding_reference::text AS binding,
      site_reference::text AS site,
      form_reference::text AS form,
      destination_reference::text AS destination,
      outcome,created_at::text AS t0
      FROM f133_lead.synthetic_metadata_claims
      ORDER BY organization_reference,submission_reference")
      ->fetchAll(PDO::FETCH_ASSOC);
}
function f133ExpectedError(callable $fn,string $sqlState):bool {
    try { $fn(); } catch(PDOException $e) {
        return $e->getCode()===$sqlState;
    }
    return false;
}

$fields=[
 'org'=>'11111111-1111-4111-8111-111111111111',
 'sub'=>'33333333-3333-4333-8333-333333333333',
 'binding'=>'22222222-2222-4222-8222-222222222222',
 'site'=>'44444444-4444-4444-8444-444444444444',
 'form'=>'55555555-5555-4555-8555-555555555555',
 'destination'=>'66666666-6666-4666-8666-666666666666',
];
$make=static fn(array $changes=[]):Draft=>
    (static function(array $p):Draft {
        return new Draft($p['org'],$p['binding'],$p['sub'],
            $p['site'],$p['form'],$p['destination']);
    })(array_replace($fields,$changes));
$a=$make();
$intent=new Intent($a);
$primary=f133Db();
$secondary=f133Db();

// Cast PostgreSQL booleans to integer in SQL: pdo_pgsql returns
// platform/version-specific PHP bool/string representations otherwise.
$role=$primary->query("SELECT rolsuper::int AS super,
 rolbypassrls::int AS bypass,rolcreaterole::int AS creatorole,
 rolcreatedb::int AS createdb
 FROM pg_roles WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
f133Check($role!==false &&
    !in_array(1,array_map('intval',array_values($role)),true),
 'no superuser, bypassrls, role or database creation privileges');
$policy=$primary->query("SELECT relrowsecurity::int AS rls,
 relforcerowsecurity::int AS forced,
 pg_has_role(current_user,relowner,'MEMBER')::int AS is_owner
 FROM pg_class WHERE oid='f133_lead.synthetic_metadata_claims'::regclass")
 ->fetch(PDO::FETCH_ASSOC);
f133Check($policy!==false &&
 (int)$policy['rls']===1 &&
 (int)$policy['forced']===1 &&
 (int)$policy['is_owner']===0,
 'mandatory RLS is enabled and app role is not table owner');
foreach(['UPDATE','DELETE','TRUNCATE'] as $permission) {
 $stmt=$primary->prepare("SELECT has_table_privilege(current_user,
    'f133_lead.synthetic_metadata_claims', ?)::int AS allowed");
 $stmt->execute([$permission]);
 f133Check((int)$stmt->fetchColumn()===0,
    'restricted app role cannot '.$permission.' laboratory claim history');
}
f133Check(count(f133Rows($primary))===0,
 'no tenant context exposes no metadata rows');

f133Begin($primary,$a->organizationReference);
f133Check(f133Insert($primary,$a),
 'primary transaction inserts first synthetic org+submission claim');
$secondary->exec("SET lock_timeout='250ms'");
f133Begin($secondary,$a->organizationReference);
f133Check(f133ExpectedError(
    static fn()=>f133Insert($secondary,$a),'55P03'
), 'independent concurrent receiver cannot resolve uncommitted unique claim');
$secondary->rollBack();
$primary->commit();

f133Begin($secondary,$a->organizationReference);
f133Check(!f133Insert($secondary,$a),
 'duplicate after first commit adds no second row');
$rows=f133Rows($secondary);
f133Check(count($rows)===1 &&
    $rows[0]['org']===$a->organizationReference &&
    $rows[0]['submission']===$a->submissionReference &&
    $rows[0]['destination']===$a->destinationReference &&
    $rows[0]['outcome']==='registered_for_test' &&
    $rows[0]['t0']!==null,
    'original CRM destination and DB-created T0 remain pinned in single row');
$t0=$rows[0]['t0'];
$changed=$make(['destination'=>'77777777-7777-4777-8777-777777777777']);
f133Check($intent->sameLogicalScopeAs(new Intent($changed)) &&
    !$intent->samePinnedContextAs(new Intent($changed)) &&
    !f133Insert($secondary,$changed),
    'same submission with changed CRM destination cannot insert second claim');
$after=f133Rows($secondary);
f133Check(count($after)===1 &&
    $after[0]['destination']===$a->destinationReference &&
    $after[0]['t0']===$t0,
    'conflict attempt cannot reroute or restart original DB receipt clock');

// F1-134: a separate readback may classify a stored metadata row but
// NEVER upgrade it into an ACK, actual reservation or CRM delivery proof.
$readback=new Readback($secondary);
$match=$readback->inspect($a);
f133Check($match->reasonCode==='METADATA_MATCH_UNVERIFIED' &&
    !$match->canReserveSubmission() &&
    !$match->canAcknowledgeVisitor() &&
    !$match->canMarkDeliveredToCrm() &&
    !$match->canRetryAutomatically(),
    'physical readback of matching technical metadata grants no permission');
foreach([
    'binding'=>'binding',
    'site'=>'site',
    'form'=>'form',
    'destination'=>'destination',
] as $field=>$name) {
    $changedContext=$make([
        $field=>'77777777-7777-4777-8777-777777777777'
    ]);
    $assessment=$readback->inspect($changedContext);
    f133Check($assessment->reasonCode==='PINNED_CONTEXT_CONFLICT' &&
        !$assessment->canMarkDeliveredToCrm(),
        'physical readback denies reassigned original '.$name);
}
$neverRecorded=$make(['sub'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
f133Check($readback->inspect($neverRecorded)->reasonCode==='NO_STORED_METADATA',
    'no row cannot be confused with a durable receipt');
$foreign=$make(['org'=>'88888888-8888-4888-8888-888888888888']);
f133Check($readback->inspect($foreign)->reasonCode==='UNTRUSTED_LAB_CONTEXT',
    'readback refuses a tenant different from the transaction context');
$originalFlag=getenv('SITES_F133_LEAD_PG_LAB');
putenv('SITES_F133_LEAD_PG_LAB=0');
try {
    f133Check($readback->inspect($a)->reasonCode==='LAB_DISABLED',
        'readback fails closed when F1-133 lab opt-in is disabled');
} finally {
    if($originalFlag===false)putenv('SITES_F133_LEAD_PG_LAB');
    else putenv('SITES_F133_LEAD_PG_LAB='.$originalFlag);
}
$secondary->commit();
f133Check($readback->inspect($a)->reasonCode==='TRANSACTION_REQUIRED',
    'readback refuses an absent trusted tenant-local transaction');

f133Begin($secondary,$a->organizationReference);
f133Check(f133ExpectedError(
    static function() use($secondary,$a):void {
       $q=$secondary->prepare("UPDATE f133_lead.synthetic_metadata_claims
          SET destination_reference=? WHERE organization_reference=?");
       $q->execute(['77777777-7777-4777-8777-777777777777',$a->organizationReference]);
    },'42501'), 'runtime cannot alter original destination after first insert');
$secondary->rollBack();

f133Begin($secondary,$a->organizationReference);
f133Check(f133ExpectedError(
    static function() use($secondary,$a):void {
       $q=$secondary->prepare("INSERT INTO f133_lead.synthetic_metadata_claims
         (organization_reference,submission_reference,binding_reference,
          site_reference,form_reference,destination_reference,created_at)
          VALUES (?,?,?,?,?,?,?)");
       $q->execute([
         $a->organizationReference,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
         $a->bindingReference,$a->siteReference,$a->formReference,
         $a->destinationReference,'2099-01-01 00:00:00+00'
       ]);
    },'42501'), 'runtime cannot spoof first-persisted server timestamp');
$secondary->rollBack();

$otherOrg=$make(['org'=>'88888888-8888-4888-8888-888888888888']);
f133Begin($primary,$a->organizationReference);
f133Check(f133ExpectedError(
    static fn()=>f133Insert($primary,$otherOrg),'42501'
), 'RLS rejects cross-tenant row while context names different tenant');
$primary->rollBack();

f133Begin($primary,$otherOrg->organizationReference);
f133Check(f133Insert($primary,$otherOrg),
 'same submission UUID in another lab tenant gets an independent row');
f133Check(count(f133Rows($primary))===1 &&
    f133Rows($primary)[0]['org']===$otherOrg->organizationReference,
 'RLS isolates read visibility of the second tenant fixture');
$primary->commit();

// F1-137: the lab tenant GUC is not a trusted identity claim.
// With SQL access the *same app role* can set another tenant UUID and see
// its synthetic row. This deliberately proves why operational IAM cannot
// rely on current_setting('app.tenant_id') alone.
f133Begin($secondary,$otherOrg->organizationReference);
$spoofed=(new Readback($secondary))->inspect($otherOrg);
f133Check($spoofed->reasonCode==='METADATA_MATCH_UNVERIFIED' &&
    !$spoofed->canReserveSubmission() &&
    !$spoofed->canAcknowledgeVisitor() &&
    !$spoofed->canMarkDeliveredToCrm() &&
    !$spoofed->canRetryAutomatically(),
    'GUC tenant impersonation is possible with SQL access, but readback never grants authority');
f133Check(count(f133Rows($secondary))===1 &&
    f133Rows($secondary)[0]['org']===$otherOrg->organizationReference,
    'application role can see another synthetic tenant when it chooses that GUC');
$secondary->rollBack();
f133Check(count(f133Rows($secondary))===0,
    'rollback clears spoofed transaction-local tenant context');

$otherSubmission=$make(['sub'=>'99999999-9999-4999-8999-999999999999']);
f133Begin($secondary,$a->organizationReference);
f133Check(f133Insert($secondary,$otherSubmission),
 'different submission in same tenant is not deduplicated by contact');
f133Check(count(f133Rows($secondary))===2,
 'first tenant sees exactly two distinct synthetic submissions');
$secondary->commit();

$rollback=$make(['sub'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
f133Begin($primary,$a->organizationReference);
f133Check(f133Insert($primary,$rollback),'first claim before simulated rollback');
$primary->rollBack();
f133Begin($secondary,$a->organizationReference);
f133Check(f133Insert($secondary,$rollback),
 'after first uncommitted receiver rolls back second can insert');
$secondary->commit();

f133Check(count(f133Rows($primary))===0 &&
    count(f133Rows($secondary))===0,
    'transaction-local tenant context does not leak between sessions');
f133Check(!$intent->canCommitReservation() &&
    !$intent->canAcknowledgeVisitor() &&
    !$intent->canDeliverToCrm(),
    'physical uniqueness is still only a laboratory reservation, never delivery/ACK');

echo "\n$checks F1-133/134 PG18 physical uniqueness and readback, RLS, pinned destination and rollback assertions passed.\n".
 "No lead payload, real authorization, customer, CRM delivery, or deployment.\n";
