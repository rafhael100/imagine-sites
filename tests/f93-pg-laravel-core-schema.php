<?php

declare(strict_types=1);

/**
 * F1-93: disposable PostgreSQL 18 proof of a REAL Laravel migration (not a
 * shadow SQL lab schema). This requires a restricted DB login independent of
 * the migration owner and never processes customer content.
 */
if (PHP_SAPI !== 'cli' || getenv('SITES_F93_SCHEMA_LAB') !== '1' ||
    getenv('DB_DATABASE') !== 'sites_f93_lab' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    !is_string(getenv('SITES_F93_APP_PASSWORD')) ||
    strlen((string)getenv('SITES_F93_APP_PASSWORD')) < 16) {
    fwrite(STDERR, "F1-93 only runs against the explicitly opted-in PG18 disposable database.\n");
    exit(1);
}

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=sites_f93_lab;connect_timeout=5';
$app = new PDO($dsn, 'sites_f93_app', (string)getenv('SITES_F93_APP_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$admin = new PDO($dsn, 'postgres', (string)getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$checks=0;
function f93Assert(bool $yes, string $label): void {
    global $checks;
    if (!$yes) throw new RuntimeException("FAIL F1-93: ".$label);
    ++$checks;
    echo "PASS F1-93: ".$label."\n";
}
function f93Denied(PDO $db, string $tenant, string $sql, array $params=[]): bool {
    try {
        $db->beginTransaction();
        $db->exec("SET LOCAL imagine_sites.tenant_ref = ".$db->quote($tenant));
        $q=$db->prepare($sql);
        $q->execute($params);
        $db->rollBack();
        return false;
    } catch (PDOException) {
        if ($db->inTransaction()) $db->rollBack();
        return true;
    }
}
function f93Count(PDO $db, string $tenant, string $table): int {
    $db->beginTransaction();
    try {
        $db->exec("SET LOCAL imagine_sites.tenant_ref = ".$db->quote($tenant));
        return (int)$db->query("SELECT count(*) FROM ".$table)->fetchColumn();
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}
$role=$app->query(
    "SELECT current_user,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole
     FROM pg_roles WHERE rolname=current_user"
)->fetch(PDO::FETCH_ASSOC);
f93Assert(
    $role['current_user'] === 'sites_f93_app' &&
    $role['rolsuper'] === false &&
    $role['rolbypassrls'] === false &&
    $role['rolcreatedb'] === false &&
    $role['rolcreaterole'] === false,
    'application connects using a restricted independent PostgreSQL login'
);
f93Assert((int)$app->query('SHOW server_version_num')->fetchColumn() >= 180000 &&
    (int)$app->query('SHOW server_version_num')->fetchColumn() < 190000,
    'the backend is a real PostgreSQL 18 service');
$r=$app->query(
    "SELECT relname,relrowsecurity,relforcerowsecurity
     FROM pg_class WHERE relname IN ('sites_projects','sites_drafts')
       AND relkind='r' ORDER BY relname"
)->fetchAll(PDO::FETCH_ASSOC);
f93Assert(count($r)===2 && $r[0]['relrowsecurity'] && $r[0]['relforcerowsecurity'] &&
    $r[1]['relrowsecurity'] && $r[1]['relforcerowsecurity'],
    'both real Laravel tables enforce FORCE RLS');

f93Assert(!$app->query(
    "SELECT has_schema_privilege(current_user, 'public', 'CREATE')"
)->fetchColumn() &&
    !$app->query(
        "SELECT has_database_privilege(current_user, 'sites_f93_lab', 'CREATE')"
    )->fetchColumn(),
    'application cannot perform DDL or create new tables');

// With no transaction-local trusted tenant, both tables default DENY.
f93Assert(
    (int)$app->query('SELECT count(*) FROM sites_projects')->fetchColumn()===0 &&
    (int)$app->query('SELECT count(*) FROM sites_drafts')->fetchColumn()===0,
    'unset tenant context cannot see any tenant rows');

$app->beginTransaction();
try {
    $app->exec("SET LOCAL imagine_sites.tenant_ref='tenant-f93-a'");
    $q=$app->prepare(
        "INSERT INTO sites_projects(tenant_ref,site_ref,product_code)
         VALUES (:tenant,:site,:product)"
    );
    $q->execute(['tenant'=>'tenant-f93-a','site'=>'site-f93-a','product'=>'onepage']);
    $q=$app->prepare(
        "INSERT INTO sites_drafts(tenant_ref,site_ref,draft_revision,workflow_ref,
            release_ref,artifact_digest,dossier_digest)
         VALUES (:tenant,:site,:revision,:workflow,:release,:artifact,:dossier)"
    );
    $q->execute([
        'tenant'=>'tenant-f93-a','site'=>'site-f93-a','revision'=>1,
        'workflow'=>'workflow-f93-a','release'=>'release-f93-a',
        'artifact'=>str_repeat('a',64),'dossier'=>str_repeat('b',64),
    ]);
    $app->commit();
} finally {
    if($app->inTransaction()) $app->rollBack();
}
f93Assert(f93Count($app,'tenant-f93-a','sites_projects')===1 &&
    f93Count($app,'tenant-f93-a','sites_drafts')===1,
    'trusted transaction-local tenant A can read own project and append draft');

f93Assert(f93Count($app,'tenant-f93-b','sites_projects')===0 &&
    f93Count($app,'tenant-f93-b','sites_drafts')===0,
    'tenant B context cannot SELECT tenant A rows under RLS');
f93Assert(f93Denied($app,'tenant-f93-b',
    "INSERT INTO sites_projects(tenant_ref,site_ref,product_code)
     VALUES ('tenant-f93-a','site-f93-cross','onepage')"),
    'RLS WITH CHECK denies inserting a different tenant ref');
f93Assert(f93Denied($app,'tenant-f93-b',
    "INSERT INTO sites_drafts(tenant_ref,site_ref,draft_revision,workflow_ref,
        release_ref,artifact_digest,dossier_digest)
     VALUES ('tenant-f93-a','site-f93-a',2,'workflow-f93-cross',
        'release-f93-cross',:artifact,:dossier)",
    ['artifact'=>str_repeat('c',64),'dossier'=>str_repeat('d',64)]),
    'RLS WITH CHECK denies cross-tenant draft insertion');
f93Assert(f93Denied($app,'tenant-f93-a',
    "INSERT INTO sites_drafts(tenant_ref,site_ref,draft_revision,workflow_ref,
        release_ref,artifact_digest,dossier_digest)
     VALUES ('tenant-f93-a','site-f93-a',1,'workflow-f93-dup',
        'release-f93-dup',:artifact,:dossier)",
    ['artifact'=>str_repeat('e',64),'dossier'=>str_repeat('f',64)]),
    'duplicate revision cannot overwrite an existing draft');
f93Assert(f93Denied($app,'tenant-f93-a',
    "INSERT INTO sites_drafts(tenant_ref,site_ref,draft_revision,workflow_ref,
        release_ref,artifact_digest,dossier_digest)
     VALUES ('tenant-f93-a','site-f93-a',2,'workflow-f93-bad',
        'release-f93-bad','not-a-hash',:dossier)",
    ['dossier'=>str_repeat('d',64)]),
    'invalid SHA-256 digest is rejected at the PostgreSQL constraint');

f93Assert(f93Denied($admin,'tenant-f93-a',
    "UPDATE sites_drafts SET review_state='rework_requested'
     WHERE tenant_ref='tenant-f93-a' AND site_ref='site-f93-a'"),
    'append-only trigger rejects modification even by migration administrator');
f93Assert(f93Denied($admin,'tenant-f93-a',
    "DELETE FROM sites_drafts WHERE tenant_ref='tenant-f93-a'"),
    'append-only trigger rejects deletion even by migration administrator');

f93Assert(f93Count($app,'tenant-f93-a','sites_drafts')===1 &&
    f93Count($app,'tenant-f93-b','sites_drafts')===0,
    'denied mutations leave the original revision intact');

$app->beginTransaction();
try {
    $app->exec("SET LOCAL imagine_sites.tenant_ref='tenant-f93-b'");
    $q=$app->prepare(
        "INSERT INTO sites_projects(tenant_ref,site_ref,product_code)
         VALUES ('tenant-f93-b','site-f93-b','landing_page')"
    );
    $q->execute();
    $app->commit();
} finally {
    if($app->inTransaction()) $app->rollBack();
}
f93Assert(f93Count($app,'tenant-f93-b','sites_projects')===1 &&
    f93Count($app,'tenant-f93-a','sites_projects')===1,
    'two tenant contexts each see their own separate project only');

f93Assert((int)$app->query('SELECT count(*) FROM sites_projects')->fetchColumn()===0 &&
    (int)$app->query('SELECT count(*) FROM sites_drafts')->fetchColumn()===0,
    'transaction-local tenant setting does not leak to subsequent requests');

echo "\n$checks F1-93 Laravel-backed PostgreSQL18 tenant-storage checks passed.\n";
echo "This is NOT Portal IAM; the application must prohibit arbitrary SQL or tenant context setting.\n";
