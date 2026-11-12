<?php
declare(strict_types=1);

/**
 * F1-143 two-session revocation test. All records are fictional metadata.
 * No API, network to Portal/CRM, real user, PII or authorization to deliver.
 */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
    || getenv('GITHUB_ACTIONS')!=='true'
    || getenv('SITES_F140_PG_LAB')!=='1'
    || getenv('DB_HOST')!=='127.0.0.1'
    || getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
    fwrite(STDERR,"F1-143 disposable PostgreSQL 18 CI only.\n");
    exit(1);
}
function f143Db(string $u,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
        $u,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
}
function f143Check(bool $condition,string $label):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-143 '.$label);
    $checks++;
    echo 'PASS: '.$label."\n";
}
function f143SqlError(callable $call,string $state):bool {
    try {$call();} catch(PDOException $error) {
        return $error->getCode()===$state;
    }
    return false;
}
function f143Claim(PDO $db,array $input):string {
    $q=$db->prepare('SELECT f140_lab.try_login_scoped_claim(?,?,?,?,?,?,?)');
    $q->execute($input);
    return (string)$q->fetchColumn();
}
$checks=0;
$orgA='11111111-1111-4111-8111-111111111111';
$orgB='88888888-8888-4888-8888-888888888888';
$binding='22222222-2222-4222-8222-222222222222';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$dest='66666666-6666-4666-8666-666666666666';
$sub1='33333333-3333-4333-8333-333333333333';
$sub2='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$arguments=static fn(string $org,string $sub):array =>
  [$org,$sub,$binding,$site,$form,$dest,7];
$appA=f143Db('sites_f140_app','f140-fictional-ci-only');
$appB=f143Db('sites_f142_org_b','f142-fictional-org-b-ci-only');
$admin=f143Db('postgres','f140-fictional-admin-ci-only');
$revocation='UPDATE f140_lab.synthetic_login_scope SET active=false,revision=2 '.
            'WHERE login_name=\'sites_f140_app\'';
$appA->beginTransaction();
f143Check(f143Claim($appA,$arguments($orgA,$sub1))==='CLAIM_RECORDED_UNVERIFIED',
    'A inserts technical claim while login-scope row remains locked');
$admin->exec("SET lock_timeout='250ms'");
f143Check(f143SqlError(static fn()=>$admin->exec($revocation),'55P03'),
    'revocation must wait for in-flight A transaction');
$appA->commit();
$admin->exec("SET lock_timeout='0'");
f143Check($admin->exec($revocation)===1,'scope revocation applied with higher revision');

f143Check(f143Claim($appA,$arguments($orgA,$sub2))==='DB_LOGIN_SCOPE_DENIED',
    'existing A session denied after scope revocation');
$appA->exec("SELECT set_config('app.tenant_id','88888888-8888-4888-8888-888888888888',false)");
f143Check(f143Claim($appA,$arguments($orgB,$sub2))==='DB_LOGIN_SCOPE_DENIED',
    'forged tenant GUC cannot bypass revoked A login');
f143Check(f143Claim($appB,$arguments($orgB,$sub2))==='CLAIM_RECORDED_UNVERIFIED',
    'unrevoked B session still records only B technical claim');
f143Check(f143Claim($appA,$arguments($orgB,$sub1))==='DB_LOGIN_SCOPE_DENIED',
    'A cannot cross-scope after revocation');

f143Check(f143SqlError(
    static fn()=>$admin->exec(
      "UPDATE f140_lab.synthetic_login_scope SET active=true,revision=3 ".
      "WHERE login_name='sites_f140_app'"
    ),'23514'),'revoked DB login scope cannot be silently reactivated');
f143Check(f143SqlError(
    static fn()=>$admin->exec(
      "UPDATE f140_lab.synthetic_login_scope SET revision=1 ".
      "WHERE login_name='sites_f140_app'"
    ),'23514'),'login scope revision cannot go backward');
f143Check(f143SqlError(
    static fn()=>$admin->exec(
      "UPDATE f140_lab.synthetic_login_scope SET revision=2 ".
      "WHERE login_name='sites_f140_app'"
    ),'23514'),'login scope revision cannot be reused');
f143Check(f143SqlError(
    static fn()=>$admin->exec(
      "UPDATE f140_lab.synthetic_login_scope ".
      "SET organization_reference='99999999-9999-4999-8999-999999999999',revision=3 ".
      "WHERE login_name='sites_f140_app'"
    ),'23514'),'DB login identity cannot be remapped to another tenant');
f143Check(f143SqlError(
    static fn()=>$admin->exec(
      "DELETE FROM f140_lab.synthetic_login_scope ".
      "WHERE login_name='sites_f140_app'"
    ),'23514'),'revocation cannot be erased by DELETE');
f143Check(
    (int)$admin->query('SELECT count(*) FROM f140_lab.synthetic_claims')
        ->fetchColumn()===2,
    'revoked and cross-tenant calls write no extra claims'
);
echo "\n$checks F1-143 CI login scope revocation and lock tests passed. ".
  "Still not Portal IAM, CRM ACK, consent or operational delivery.\n";
