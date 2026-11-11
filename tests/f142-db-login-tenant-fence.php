<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('SITES_F140_PG_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'sites_f140_atomic_lab') {
    fwrite(STDERR, "F1-142 restricted to disposable PostgreSQL 18 CI lab.\n");
    exit(1);
}

function db142(string $user,string $password): PDO {
  return new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
    $user,$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES=>false]
  );
}
function check142(bool $ok,string $reason): void {
  global $checks;
  if (!$ok) throw new RuntimeException('F1-142 failure: '.$reason);
  $checks++;
}
function state142(callable $call,string $sqlState): bool {
  try {$call();} catch(PDOException $e) {return $e->getCode()===$sqlState;}
  return false;
}
function claim142(PDO $db,array $a): string {
  $q=$db->prepare('SELECT f140_lab.try_login_scoped_claim(?,?,?,?,?,?,?)');
  $q->execute($a);
  return (string)$q->fetchColumn();
}
$checks=0;
$a='11111111-1111-4111-8111-111111111111';
$b='88888888-8888-4888-8888-888888888888';
$submission='33333333-3333-4333-8333-333333333333';
$binding='22222222-2222-4222-8222-222222222222';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$dest='66666666-6666-4666-8666-666666666666';
$make=static fn(string $org,string $sub=$submission):array =>
  [$org,$sub,$binding,$site,$form,$dest,7];
$appA=db142('sites_f140_app','f140-fictional-ci-only');
$appB=db142('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=db142('postgres','f140-fictional-admin-ci-only');

check142($appA->query('SELECT session_user')->fetchColumn()==='sites_f140_app',
 'session_user reflects authenticated tenant A DB login');
check142($appB->query('SELECT session_user')->fetchColumn()==='sites_f142_org_b',
 'session_user reflects authenticated tenant B DB login');

foreach ([$appA,$appB] as $db) {
 check142(state142(
   static fn()=>$db->query('SELECT * FROM f140_lab.synthetic_login_scope'),
   '42501'
 ),'restricted login cannot read role tenant mapping');
 check142(state142(
   static fn()=>$db->query('SELECT * FROM f140_lab.synthetic_binding_versions'),
   '42501'
 ),'restricted login cannot read authority rows');
 check142(state142(
   static fn()=>$db->query("SELECT f140_lab.try_claim(".
      "'11111111-1111-4111-8111-111111111111',".
      "'33333333-3333-4333-8333-333333333333',".
      "'22222222-2222-4222-8222-222222222222',".
      "'44444444-4444-4444-8444-444444444444',".
      "'55555555-5555-4555-8555-555555555555',".
      "'66666666-6666-4666-8666-666666666666',7)"),
   '42501'
 ),'old unscoped F1-140 entrypoint is not callable by app');
}

check142(claim142($appA,$make($b))==='DB_LOGIN_SCOPE_DENIED',
 'tenant A login cannot claim tenant B binding');
check142(claim142($appB,$make($a))==='DB_LOGIN_SCOPE_DENIED',
 'tenant B login cannot claim tenant A binding');

check142(state142(
 static fn()=>$appA->exec('SET ROLE sites_f142_org_b'),'42501'),
 'A DB login cannot SET ROLE to B');
check142(state142(
 static fn()=>$appB->exec('SET ROLE sites_f140_app'),'42501'),
 'B DB login cannot SET ROLE to A');

check142(claim142($appA,$make($a))==='CLAIM_RECORDED_UNVERIFIED',
 'tenant A records only synthetic metadata');
check142(claim142($appB,$make($b))==='CLAIM_RECORDED_UNVERIFIED',
 'tenant B independently records identical submission UUID');
check142(claim142($appA,$make($a))==='EXISTING_CLAIM_UNVERIFIED',
 'same tenant A and submission are idempotent');
check142(claim142($appB,$make($b))==='EXISTING_CLAIM_UNVERIFIED',
 'same tenant B and submission are idempotent');

$rows=$admin->query(
 'SELECT organization_reference::text AS org,submission_reference::text AS sub '.
 'FROM f140_lab.synthetic_claims ORDER BY organization_reference'
)->fetchAll(PDO::FETCH_ASSOC);
check142(count($rows)===2 && $rows[0]['org']===$a && $rows[1]['org']===$b &&
  $rows[0]['sub']===$submission && $rows[1]['sub']===$submission,
 'two synthetic organizations have isolated physical rows');

check142(claim142($appA,$make($b,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'))
    ==='DB_LOGIN_SCOPE_DENIED',
 'tenant A cannot create arbitrary second tenant submission');
check142((int)$admin->query('SELECT count(*) FROM f140_lab.synthetic_claims')
    ->fetchColumn()===2,'cross-tenant requests never write rows');

echo "$checks F1-142 DB-login-bound tenant claims passed. ".
  "Not Portal IAM, a trusted service identity or CRM receipt.\n";
