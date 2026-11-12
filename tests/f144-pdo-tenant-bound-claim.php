<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
    || getenv('GITHUB_ACTIONS')!=='true'
    || getenv('SITES_F140_PG_LAB')!=='1'
    || getenv('DB_HOST')!=='127.0.0.1'
    || getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
    fwrite(STDERR, "F1-144 runs exclusively on disposable PostgreSQL CI.\n");
    exit(1);
}
foreach ([
 'SyntheticCrmLeadEnvelopeV1Draft',
 'LabTenantBoundCrmClaimAssessment',
 'LabPostgresTenantBoundCrmClaim'
] as $file) {
    require __DIR__.'/../src/Integration/'.$file.'.php';
}
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\LabTenantBoundCrmClaimAssessment as Assessment;
use Imagine\Sites\Integration\LabPostgresTenantBoundCrmClaim as Claim;

function f144Db(string $u,string $pwd):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
        $u,$pwd,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
}
function f144Check(bool $ok,string $message):void {
    global $checks;
    if (!$ok)throw new RuntimeException('F1-144 '.$message);
    ++$checks;
}
function f144NoAuthority(Assessment $a,string $why):void {
    f144Check(!$a->canAcceptLead() && !$a->canAcknowledgeVisitor()
        && !$a->canDeliverToCrm() && !$a->canRetryAutomatically(),
        $why.' cannot confer real operational authority');
}
$checks=0;
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$sub='33333333-3333-4333-8333-333333333333';
$binding='22222222-2222-4222-8222-222222222222';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$dest='66666666-6666-4666-8666-666666666666';
$make=static fn(string $org,string $destination):Draft =>
    new Draft($org,$binding,$sub,$site,$form,$destination);
$a=f144Db('sites_f140_app','f140-fictional-ci-only');
$b=f144Db('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=f144Db('postgres','f140-fictional-admin-ci-only');
$bridgeA=new Claim($a);
$bridgeB=new Claim($b);
$draftA=$make($orgA,$dest);
$draftB=$make($orgB,$dest);

f144Check($bridgeA->register($draftA,7)->reasonCode==='TRANSACTION_REQUIRED',
    'transaction is required even in CI');
$a->beginTransaction();
$b->beginTransaction();
foreach ([
  [$bridgeA->register($draftA,7),'CLAIM_RECORDED_UNVERIFIED'],
  [$bridgeB->register($draftB,7),'CLAIM_RECORDED_UNVERIFIED'],
  [$bridgeA->register($draftA,7),'EXISTING_CLAIM_UNVERIFIED'],
  [$bridgeA->register($draftB,7),'DB_LOGIN_SCOPE_DENIED'],
  [$bridgeB->register($draftA,7),'DB_LOGIN_SCOPE_DENIED'],
  [$bridgeB->register($draftB,0),'INVALID_SYNTHETIC_CLAIM'],
  [$bridgeB->register($make($orgB,'77777777-7777-4777-8777-777777777777'),7),
      'BINDING_CONTEXT_MISMATCH']
] as [$outcome,$expected]) {
   f144Check($outcome->reasonCode===$expected,'bridge diagnostic '.$expected);
   f144NoAuthority($outcome,$expected);
}
$flag=getenv('SITES_F140_PG_LAB');
putenv('SITES_F140_PG_LAB=0');
try {
    f144Check($bridgeA->register($draftA,7)->reasonCode==='LAB_DISABLED',
        'lab opt-out fails closed before any database query');
} finally {
    if ($flag===false)putenv('SITES_F140_PG_LAB');
    else putenv('SITES_F140_PG_LAB='.$flag);
}
$a->commit();
$b->commit();

$admin->exec(
    "UPDATE f140_lab.synthetic_login_scope SET revision=2,active=false ".
    "WHERE login_name='sites_f140_app'"
);
$a->beginTransaction();
$revoked=$bridgeA->register($draftA,7);
f144Check($revoked->reasonCode==='DB_LOGIN_SCOPE_DENIED',
    'existing PDO session fails after mapping revocation');
f144NoAuthority($revoked,'revoked login');
$a->commit();

f144Check(Assessment::safeCode('DELIVERED')==='UNEXPECTED_DATABASE_STATE',
    'forged delivered code is mapped to denial');
$unknown=new Assessment(Assessment::safeCode('ACK'));
f144NoAuthority($unknown,'unknown diagnostic');

f144Check((int)$admin->query(
    'SELECT count(*) FROM f140_lab.synthetic_claims'
)->fetchColumn()===2,'exactly two original synthetic claims exist');

echo "$checks F1-144 PHP PDO tenant claims assertions passed; no PII, ".
     "real IAM, CRM receipt or production deployment.\n";
