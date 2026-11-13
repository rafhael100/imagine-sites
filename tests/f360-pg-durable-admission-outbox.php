<?php

declare(strict_types=1);

/**
 * F1-360: real PG18 transaction + outbox in a throwaway CI database ONLY.
 * There is no Portal IAM, external revocation source or live customer data.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_F360_PG_LAB') !== '1' ||
    getenv('DB_CONNECTION') !== 'pgsql' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_PORT') !== '5432' ||
    getenv('DB_DATABASE') !== 'sites_f360_lab' ||
    getenv('DB_USERNAME') !== 'postgres' ||
    getenv('DB_PASSWORD') !== 'f360-fictional-admin-disposable-only') {
    fwrite(STDERR, "F1-360: refusing database outside explicit disposable PG18 CI target.\n");
    exit(1);
}
$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 6,
];
$admin = new PDO($dsn, 'postgres', 'f360-fictional-admin-disposable-only', $options);
$version = (int)$admin->query('SHOW server_version_num')->fetchColumn();
if ($version < 180000 || $version >= 190000) {
    throw new RuntimeException('F1-360 requires isolated PostgreSQL 18 specifically.');
}

$ddl = __DIR__.'/fixtures/f360-pg-durable-admission.sql';
if (!is_file($ddl) || is_link($ddl)) {
    throw new RuntimeException('F1-360 SQL fixture missing or unsafe.');
}
$admin->exec((string)file_get_contents($ddl));

$a = new PDO($dsn,'sites_f360_a','f360-fictional-tenant-a-only',$options);
$b = new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$options);
$checks=0;
function f360Check(bool $condition,string $label):void
{
    global $checks;
    if (!$condition) throw new RuntimeException('F1-360 FAIL: '.$label);
    ++$checks;
}
function f360Denied(callable $action,string $sqlstate):bool
{
    try { $action(); } catch(PDOException $e) {
        return $e->getCode() === $sqlstate;
    }
    return false;
}
function f360Admit(PDO $db,string $operation,string $site,string $contract,
    string $product,int $revision,string $digest,?string $expectedTenant=null):string
{
    // Only this synthetic test convenience derives a tenant from the exact
    // fictional SQL login. The real adapter must pass its *signed* tenant.
    $login=(string)$db->query('SELECT session_user')->fetchColumn();
    $expectedTenant ??= match($login) {
        'sites_f360_a'=>'tenant-f360-a',
        'sites_f360_b'=>'tenant-f360-b',
        default=>throw new RuntimeException('Unexpected fictional PG login.')
    };
    $stmt=$db->prepare(
        'SELECT sites_f360.admit_project(?,?,?,?,?,?,?)'
    );
    $stmt->execute([$expectedTenant,$operation,$site,$contract,$product,
        $revision,$digest]);
    return (string)$stmt->fetchColumn();
}
function f360Count(PDO $admin,string $table):int
{
    if (!in_array($table,['admissions','outbox','login_bindings'],true)) {
        throw new InvalidArgumentException('Unapproved fixture table.');
    }
    return (int)$admin->query(
        'SELECT count(*) FROM sites_f360.'.$table
    )->fetchColumn();
}
$shaA=hash('sha256','fictional-command-A');
$shaB=hash('sha256','fictional-command-B');
$shaConflict=hash('sha256','fictional-conflicting-command');
f360Check(
    (string)$a->query('SELECT session_user')->fetchColumn()==='sites_f360_a' &&
    (string)$b->query('SELECT session_user')->fetchColumn()==='sites_f360_b',
    'two independently authenticated, non-superuser DB logins'
);
f360Check(
    f360Denied(static fn()=> $a->query('SELECT * FROM sites_f360.admissions'),'42501') &&
    f360Denied(static fn()=> $b->query('SELECT * FROM sites_f360.outbox'),'42501') &&
    f360Denied(static fn()=> $a->query('SELECT * FROM sites_f360.login_bindings'),'42501'),
    'app logins cannot read admission, outbox or private authority tables'
);
f360Check(f360Denied(static fn()=> $a->exec(
    "INSERT INTO sites_f360.outbox(tenant_ref,operation_ref,command_digest)
     VALUES('tenant-f360-a','forged','$shaA')"
),'42501'), 'app login cannot directly forge an outbox row');
f360Check(f360Denied(static fn()=> $a->exec('SET ROLE sites_f360_owner'),'42501'),
    'app login cannot impersonate protected schema owner');
f360Check(f360Denied(static fn()=> $b->exec('SET ROLE sites_f360_a'),'42501'),
    'tenant B cannot assume tenant A login');
f360Check(f360Denied(static fn()=> $a->exec(
    'ALTER TABLE sites_f360.admissions DISABLE ROW LEVEL SECURITY'
),'42501'), 'app cannot alter admission schema or relax security');

$unboundDenied=false;
try {
    new PDO($dsn,'sites_f360_unbound','f360-fictional-unbound-only',$options);
} catch(PDOException $e) {
    $unboundDenied=str_contains(strtolower($e->getMessage()),
        'permission denied for database');
}
f360Check($unboundDenied,'unbound account denied CONNECT despite valid fictional password');

f360Check(f360Admit($a,'op-shared','site-a','contract-a','onepage',1,$shaA)==='queued',
    'tenant A first signed-command-shaped admission queued');
f360Check(f360Count($admin,'admissions')===1 &&
    f360Count($admin,'outbox')===1,
    'one committed admission has exactly one committed outbox event');
f360Check(f360Admit($a,'op-shared','site-a','contract-a','onepage',1,$shaA)
    ==='duplicate_ignored', 'identical operation never queues another event');
f360Check(f360Count($admin,'admissions')===1 &&
    f360Count($admin,'outbox')===1,
    'idempotent duplicate leaves exactly one admission and event');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'op-shared','site-a','contract-a','onepage',1,$shaConflict
),'23505'), 'same operation with a changed payload digest rejected');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'op-shared','different-site','contract-a','onepage',1,$shaA
),'23505'), 'same digest cannot hide changed project target');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'op-shared','site-a','changed-contract','onepage',1,$shaA
),'23505'), 'same digest cannot hide changed contract');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'op-shared','site-a','contract-a','landing_page',1,$shaA
),'23505'), 'same digest cannot hide product change');

f360Check(f360Denied(static fn()=>f360Admit(
    $b,'signed-tenant-mismatch','site-b','contract-b','landing_page',1,
    $shaB,'tenant-f360-a'
),'42501'), 'signed tenant A cannot be admitted through tenant B SQL login');
f360Check(f360Admit($b,'op-shared','site-b','contract-b','landing_page',1,$shaB)
    ==='queued', 'same operation ID in a separate DB tenant has independent scope');
f360Check(f360Count($admin,'admissions')===2 &&
    f360Count($admin,'outbox')===2,
    'two independent tenant records have two durable outbox rows');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'bad with spaces','site-a','contract-a','onepage',1,$shaA
),'22023'), 'invalid operation identifier rejected');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'bad-product','site-a','contract-a','unapproved',1,$shaA
),'22023'), 'unknown Sites product rejected');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'bad-digest','site-a','contract-a','onepage',1,'incorrect'
),'22023'), 'non-SHA256 digest rejected');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'stale-revision','site-a','contract-a','onepage',0,$shaA
),'42501'), 'stale authority revision fails closed');

$a->beginTransaction();
try {
    $a->exec("SET LOCAL imagine_sites.tenant_ref='tenant-f360-b'");
    f360Check(f360Admit(
        $a,'guc-forged','site-a-2','contract-a','onepage',1,$shaA
    )==='queued', 'forged tenant GUC does not change authenticated SQL identity');
    $a->commit();
} finally {
    if($a->inTransaction())$a->rollBack();
}
$row=$admin->query(
    "SELECT tenant_ref FROM sites_f360.admissions WHERE operation_ref='guc-forged'"
)->fetchColumn();
f360Check($row==='tenant-f360-a','GUC-forged attempt still belongs solely to login A');

f360Check(f360Denied(static fn()=>f360Admit(
    $b,'failoutbox-f360','site-fail','contract-b','landing_page',1,$shaB
),'23514'), 'synthetic outbox CHECK failure rejects whole admission statement');
f360Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.admissions WHERE operation_ref='failoutbox-f360'"
)->fetchColumn()===0 &&
    (int)$admin->query(
    "SELECT count(*) FROM sites_f360.outbox WHERE operation_ref='failoutbox-f360'"
)->fetchColumn()===0,
    'no orphan admission after failed outbox insert');

$b->beginTransaction();
try {
    f360Check(f360Admit(
        $b,'manual-rollback','site-rollback','contract-b','onepage',1,$shaB
    )==='queued', 'admission + outbox can be performed within explicit transaction');
    $b->rollBack();
} finally {
    if($b->inTransaction())$b->rollBack();
}
f360Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.admissions WHERE operation_ref='manual-rollback'"
)->fetchColumn()===0 &&
    (int)$admin->query(
    "SELECT count(*) FROM sites_f360.outbox WHERE operation_ref='manual-rollback'"
)->fetchColumn()===0,
    'explicit rollback cancels both rows together');

$a->beginTransaction();
try {
    f360Check(f360Admit(
        $a,'inflight','site-inflight','contract-a','onepage',1,$shaA
    )==='queued', 'inflight transaction holds authority mapping lock');
    $admin->exec("SET lock_timeout='250ms'");
    try {
        f360Check(f360Denied(static fn()=> $admin->exec(
            "UPDATE sites_f360.login_bindings SET active=false,authority_revision=2
             WHERE login_name='sites_f360_a'"
        ),'55P03'), 'revocation cannot race ahead of an inflight admission');
    } finally {
        $admin->exec('RESET lock_timeout');
    }
    $a->commit();
} finally {
    if($a->inTransaction())$a->rollBack();
}
f360Check($admin->exec(
    "UPDATE sites_f360.login_bindings SET active=false,authority_revision=2
     WHERE login_name='sites_f360_a'"
)===1, 'revocation proceeds after inflight transaction commits');
f360Check(f360Denied(static fn()=>f360Admit(
    $a,'post-revocation','site-a-3','contract-a','onepage',1,$shaA
),'42501'), 'already connected revoked login cannot enqueue another project');
f360Check(f360Admit(
    $b,'still-active','site-b-2','contract-b','landing_page',1,$shaB
)==='queued', 'other tenant remains unaffected by A revocation');

$admissions=f360Count($admin,'admissions');
$outbox=f360Count($admin,'outbox');
$missing=(int)$admin->query(
    'SELECT count(*) FROM sites_f360.admissions a
     LEFT JOIN sites_f360.outbox o
       ON o.tenant_ref=a.tenant_ref AND o.operation_ref=a.operation_ref
     WHERE o.tenant_ref IS NULL'
)->fetchColumn();
f360Check($admissions===5 && $outbox===5 && $missing===0,
    'exactly five durable admissions and five matching outbox events, no missing rows');
f360Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.admissions WHERE tenant_ref='tenant-f360-b'"
)->fetchColumn()===2, 'tenant B admissions remain scoped and durably separate');
echo "$checks F1-360 PG18 transactional admission + durable outbox checks passed.\n";
echo "NO-GO: synthetic DB logins and in-DB authority cannot substitute for Portal signatures, external revocation, real API, backups or live SaaS.\n";
