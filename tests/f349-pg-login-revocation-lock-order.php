<?php

declare(strict_types=1);

/**
 * F1-349: PostgreSQL18 race experiment. An in-flight transaction that
 * consults a login mapping must hold a row SHARE lock until commit, so an
 * external revocation cannot commit behind the in-flight operation.
 *
 * This experiment is NOT a real IAM, a production lock service, customer
 * tenant isolation, SQL grant or proof of distributed API authorization.
 */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F93_SCHEMA_LAB')!=='1' ||
    getenv('SITES_F347_DB_LOGIN_LAB')!=='1' ||
    getenv('SITES_F349_REVOCATION_LOCK_LAB')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_PORT')!=='5432' ||
    getenv('DB_DATABASE')!=='sites_f93_lab' ||
    getenv('DB_USERNAME')!=='postgres' ||
    (string)getenv('DB_PASSWORD')!=='f93-synthetic-admin-disposable-only') {
    fwrite(STDERR,"F1-349 disposable PG18 lab only.\n");
    exit(1);
}

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f93_lab;connect_timeout=5';
$options=[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_TIMEOUT=>6,
];
$admin=new PDO($dsn,'postgres','f93-synthetic-admin-disposable-only',$options);
$b=new PDO($dsn,'sites_f347_b','f347-fictional-org-b-only',$options);
$a=new PDO($dsn,'sites_f347_a','f347-fictional-org-a-only',$options);
$checks=0;
function f349Check(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-349: '.$label);
    ++$checks;
}
function f349Sqlstate(callable $fn,string $expected):bool {
    try {$fn();} catch(PDOException $e) {
        return $e->getCode()===$expected;
    }
    return false;
}

// The earlier F1-347 mapping reader was STABLE and did not lock its row.
// The lab variant below is VOLATILE because SELECT ... FOR SHARE acquires
// a transaction-long lock on the login's independent authority record.
$admin->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION public.sites_f347_scope_tenant()
RETURNS text LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog AS $f349$
  SELECT tenant_ref
  FROM public.sites_f347_login_map
  WHERE login_name=session_user AND active IS TRUE
  FOR SHARE
$f349$
SQL);

f349Check(
    (int)$b->query('SELECT COUNT(*) FROM sites_projects')->fetchColumn()===1 &&
    (int)$a->query('SELECT COUNT(*) FROM sites_projects')->fetchColumn()===0,
    'B is live and A was revoked by F1-347'
);

$b->beginTransaction();
try {
    // This RLS-backed query reads the map through the SECURITY DEFINER
    // function and holds FOR SHARE lock until the test transaction ends.
    $site=$b->query('SELECT site_ref FROM sites_projects')->fetchColumn();
    f349Check($site==='site-f93-b',
        'restricted login B read its own row inside an open transaction');

    $admin->exec("SET lock_timeout = '250ms'");
    try {
        $blocked=f349Sqlstate(
            static fn()=>$admin->exec(
                "UPDATE public.sites_f347_login_map
                 SET active=false,revision=2
                 WHERE login_name='sites_f347_b'"
            ), '55P03'
        );
    } finally {
        $admin->exec('RESET lock_timeout');
    }
    f349Check($blocked,
        'revocation cannot acquire mapping row while B transaction holds RLS scope lock');
    f349Check(
        (int)$b->query('SELECT COUNT(*) FROM sites_projects')->fetchColumn()===1,
        'B still has only its own scope until the in-flight transaction ends'
    );
    $b->commit();
} finally {
    if($b->inTransaction())$b->rollBack();
}

$binding=$admin->query(
    "SELECT active,revision FROM public.sites_f347_login_map
     WHERE login_name='sites_f347_b'"
)->fetch(PDO::FETCH_ASSOC);
f349Check(
    is_array($binding) && in_array($binding['active'],[true,'t','1'],true) &&
    (int)$binding['revision']===1,
    'timed-out revocation never silently changed authority record'
);

// Test revocation AFTER the prior operation completes, but rollback this
// simulated change so F1-348 can attach its one-way revision trigger.
$admin->beginTransaction();
try {
    f349Check($admin->exec(
        "UPDATE public.sites_f347_login_map
         SET active=false,revision=2 WHERE login_name='sites_f347_b'"
    )===1, 'revocation can proceed when in-flight B operation has committed');
    // A separate session cannot read B while revocation holds its row lock.
    // Do not add a third process: this is already a verified SQL ordering.
} finally {
    if($admin->inTransaction())$admin->rollBack();
}

f349Check(
    (int)$b->query('SELECT COUNT(*) FROM sites_projects')->fetchColumn()===1,
    'rolled-back test revocation preserves B scope for F1-348'
);
f349Check(
    (int)$admin->query('SELECT COUNT(*) FROM sites_projects')->fetchColumn()===2 &&
    (int)$admin->query('SELECT COUNT(*) FROM sites_drafts')->fetchColumn()===1,
    'the experiment did not modify persistent fictional project records'
);
echo "$checks F1-349 login-scope transaction lock/revocation ordering checks passed.\n";
echo "NO-GO: local PG row locks do not authenticate Portal nor authorize live SaaS.\n";
