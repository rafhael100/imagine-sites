<?php

declare(strict_types=1);

/**
 * F1-347: demonstrate a strictly limited DB-login-scope RLS alternative
 * in an isolated, disposable PostgreSQL 18 service. Never a production
 * migration, IAM grant, customer authorization, or live tenancy scheme.
 * Must run AFTER F1-93 has applied its REAL Laravel core migration and
 * F1-346 has demonstrated the original GUC spoof weakness.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_F93_SCHEMA_LAB') !== '1' ||
    getenv('SITES_F347_DB_LOGIN_LAB') !== '1' ||
    getenv('DB_DATABASE') !== 'sites_f93_lab' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_PORT') !== '5432' ||
    getenv('DB_USERNAME') !== 'postgres' ||
    (string)getenv('DB_PASSWORD') !== 'f93-synthetic-admin-disposable-only') {
    fwrite(STDERR,"F1-347 disposable PostgreSQL18 CI-only fixture denied.\n");
    exit(1);
}

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f93_lab;connect_timeout=5';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,
       PDO::ATTR_TIMEOUT=>6];
$admin=new PDO($dsn,'postgres','f93-synthetic-admin-disposable-only',$opts);
$checks=0;
function f347Check(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-347 lab scope: '.$label);
    $checks++;
}
function f347SqlDenied(callable $fn,string $expected='42501'):bool {
    try {$fn();} catch(PDOException $e) {
        return $e->getCode()===$expected;
    }
    return false;
}
function f347Count(PDO $db,string $table):int {
    if(!in_array($table,['sites_projects','sites_drafts'],true)) {
        throw new RuntimeException('Unapproved lab table');
    }
    return (int)$db->query("SELECT COUNT(*) FROM ".$table)->fetchColumn();
}

// Private mapping is writable ONLY by the disposable migration owner.
// session_user remains the actual authenticated login across SECURITY
// DEFINER calls, unlike current_user (which becomes the function owner).
foreach([
    "CREATE ROLE sites_f347_a LOGIN NOINHERIT NOSUPERUSER
         NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
         PASSWORD 'f347-fictional-org-a-only'",
    "CREATE ROLE sites_f347_b LOGIN NOINHERIT NOSUPERUSER
         NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
         PASSWORD 'f347-fictional-org-b-only'",
    "CREATE TABLE public.sites_f347_login_map (
         login_name text PRIMARY KEY,
         tenant_ref text NOT NULL,
         active boolean NOT NULL,
         revision integer NOT NULL CHECK (revision >= 1))",
    "REVOKE ALL ON public.sites_f347_login_map FROM PUBLIC",
    // Prevent pg_temp table shadowing through default PUBLIC TEMP permission.
    "REVOKE TEMPORARY ON DATABASE sites_f93_lab FROM PUBLIC",
    // F1-353: deny new unbound logins implicit database access.
    "REVOKE CONNECT ON DATABASE sites_f93_lab FROM PUBLIC",
    "CREATE ROLE sites_f353_unbound LOGIN NOINHERIT NOSUPERUSER
         NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
         PASSWORD 'f353-fictional-unbound-only'",
    "INSERT INTO public.sites_f347_login_map
         (login_name,tenant_ref,active,revision) VALUES
         ('sites_f347_a','tenant-f93-a',true,1),
         ('sites_f347_b','tenant-f93-b',true,1)",
    <<<'SQL'
CREATE FUNCTION public.sites_f347_scope_tenant()
RETURNS text LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog AS $f347$
  SELECT tenant_ref
  FROM public.sites_f347_login_map
  WHERE login_name=session_user
    AND active IS TRUE
$f347$
SQL,
    "REVOKE ALL ON FUNCTION public.sites_f347_scope_tenant() FROM PUBLIC",
    "GRANT EXECUTE ON FUNCTION public.sites_f347_scope_tenant()
         TO sites_f347_a,sites_f347_b",
    "GRANT CONNECT ON DATABASE sites_f93_lab TO sites_f347_a,sites_f347_b",
    "GRANT USAGE ON SCHEMA public TO sites_f347_a,sites_f347_b",
    "GRANT SELECT,INSERT ON TABLE public.sites_projects,public.sites_drafts
         TO sites_f347_a,sites_f347_b",
    "DROP POLICY sites_projects_tenant_policy ON public.sites_projects",
    "DROP POLICY sites_drafts_tenant_policy ON public.sites_drafts",
    "CREATE POLICY sites_projects_login_policy ON public.sites_projects
         FOR ALL USING (tenant_ref=public.sites_f347_scope_tenant())
         WITH CHECK (tenant_ref=public.sites_f347_scope_tenant())",
    "CREATE POLICY sites_drafts_login_policy ON public.sites_drafts
         FOR ALL USING (tenant_ref=public.sites_f347_scope_tenant())
         WITH CHECK (tenant_ref=public.sites_f347_scope_tenant())",
] as $statement){
    $admin->exec($statement);
}

$a=new PDO($dsn,'sites_f347_a','f347-fictional-org-a-only',$opts);
$b=new PDO($dsn,'sites_f347_b','f347-fictional-org-b-only',$opts);
// F1-353: prove a syntactically valid but unbound DB login cannot even
// connect; all privileges are checked on the real disposable PG18 server.
$allowed = $admin->query(<<<'SQL'
SELECT
 has_database_privilege('sites_f347_a','sites_f93_lab','CONNECT'),
 has_database_privilege('sites_f347_b','sites_f93_lab','CONNECT'),
 has_database_privilege('sites_f353_unbound','sites_f93_lab','CONNECT')
SQL)->fetch(PDO::FETCH_NUM);
f347Check(array_map(
    static fn($v):bool=>in_array($v,[true,'t','1',1],true), $allowed
) === [true,true,false], 'only registered lab logins have database CONNECT');
$unboundDenied=false;
try {
    new PDO($dsn,'sites_f353_unbound','f353-fictional-unbound-only',$opts);
} catch (PDOException $e) {
    $unboundDenied=str_contains(strtolower($e->getMessage()),
        'permission denied for database');
}
f347Check($unboundDenied,
    'unbound login cannot connect even with a valid fictional password');

f347Check((string)$a->query('SELECT session_user')->fetchColumn()==='sites_f347_a' &&
          (string)$b->query('SELECT session_user')->fetchColumn()==='sites_f347_b',
          'two physically independent restricted DB logins');
f347Check(f347Count($a,'sites_projects')===1 &&
          f347Count($b,'sites_projects')===1 &&
          f347Count($a,'sites_drafts')===1 &&
          f347Count($b,'sites_drafts')===0,
          'each DB login sees only its mapped synthetic tenant rows');

f347Check(f347SqlDenied(static fn()=> $a->query(
    'SELECT * FROM public.sites_f347_login_map'
)), 'app login cannot read the private authority mapping');
f347Check(f347SqlDenied(static fn()=> $b->exec(
    "UPDATE public.sites_f347_login_map SET active=false"
)), 'app login cannot alter its own tenant binding');
f347Check(f347SqlDenied(static fn()=> $a->exec(
    'SET ROLE sites_f347_b'
)), 'login A cannot acquire login B role');

// F1-350: explicitly verify least privilege and SQL identity boundaries
// before exercising the forged-GUC scenarios. These assertions run only
// against the same disposable CI PostgreSQL roles and schema.
foreach ([$a, $b] as $db) {
    $rights=$db->query(<<<'SQL'
SELECT
  has_table_privilege(current_user, 'public.sites_projects', 'SELECT'),
  has_table_privilege(current_user, 'public.sites_projects', 'INSERT'),
  has_table_privilege(current_user, 'public.sites_projects', 'UPDATE'),
  has_table_privilege(current_user, 'public.sites_projects', 'DELETE'),
  has_table_privilege(current_user, 'public.sites_drafts', 'UPDATE'),
  has_schema_privilege(current_user, 'public', 'CREATE')
SQL)->fetch(PDO::FETCH_NUM);
    f347Check(array_map(static fn($v):bool=>in_array($v,[true,'t','1',1],true), $rights) ===
              [true,true,false,false,false,false],
        'mapped login has SELECT/INSERT but cannot UPDATE/DELETE or create in public');
}
f347Check(f347SqlDenied(static fn()=> $a->exec(
    'SET SESSION AUTHORIZATION sites_f347_b'
)), 'login A cannot impersonate login B via SET SESSION AUTHORIZATION');
f347Check(f347SqlDenied(static fn()=> $b->exec(
    'CREATE TABLE public.sites_f350_unauthorized (id integer)'
)), 'mapped login cannot create unapproved schema objects');
f347Check(f347SqlDenied(static fn()=> $a->exec(
    'CREATE TEMPORARY TABLE sites_projects (site_ref text)'
)), 'mapped login cannot shadow trusted tables using pg_temp');
f347Check(f347SqlDenied(static fn()=> $b->exec(
    'CREATE TEMPORARY TABLE sites_f347_login_map (login_name text)'
)), 'mapped login cannot shadow the private identity map using pg_temp');

// F1-351: a compromised SQL connection must not mutate RLS policy,
// impersonate the owner or redefine the SECURITY DEFINER authority.
// These are adversarial negative tests of the isolated CI fixture only.
f347Check(f347SqlDenied(static fn()=> $a->exec(
    'SET ROLE postgres'
)), 'mapped login cannot assume the schema owner role');
f347Check(f347SqlDenied(static fn()=> $a->exec(
    'ALTER TABLE public.sites_projects DISABLE ROW LEVEL SECURITY'
)), 'mapped login cannot turn off project RLS');
f347Check(f347SqlDenied(static fn()=> $b->exec(
    'DROP POLICY sites_projects_login_policy ON public.sites_projects'
)), 'mapped login cannot drop the tenant isolation policy');
f347Check(f347SqlDenied(static fn()=> $a->exec(
    'DROP FUNCTION public.sites_f347_scope_tenant()'
)), 'mapped login cannot drop the private tenant scope function');
f347Check(f347SqlDenied(static fn()=> $b->exec(
    'GRANT SELECT ON TABLE public.sites_f347_login_map TO sites_f347_b'
)), 'mapped login cannot grant itself access to the private identity mapping');
$a->exec('SET search_path = pg_temp, public');
f347Check(
    f347Count($a, 'sites_projects')===1 &&
    (string)$a->query(
        'SELECT public.sites_f347_scope_tenant()'
    )->fetchColumn()==='tenant-f93-a',
    'session search_path changes do not redirect qualified scope authority'
);
$a->exec('RESET search_path');

// Untrusted GUC values can still be changed by apps, but they are no
// longer the authority consulted by these temporary lab policies.
$a->beginTransaction();
try {
    $a->exec("SET LOCAL imagine_sites.tenant_ref='tenant-f93-b'");
    f347Check(
        f347Count($a,'sites_projects')===1 &&
        (string)$a->query(
            'SELECT site_ref FROM sites_projects'
        )->fetchColumn()==='site-f93-a',
        'forged B GUC does not expose B project to login A'
    );
    f347Check(
        f347Count($a,'sites_drafts')===1,
        'forged GUC cannot expose B draft ledger'
    );
    f347Check(
        f347SqlDenied(static fn()=> $a->exec(
            "INSERT INTO sites_projects(tenant_ref,site_ref,product_code)
             VALUES ('tenant-f93-b','site-f347-cross','onepage')"
        )),
        'RLS WITH CHECK rejects foreign tenant INSERT despite forged GUC'
    );
} finally {
    if($a->inTransaction())$a->rollBack();
}
$b->beginTransaction();
try {
    $b->exec("SET LOCAL imagine_sites.tenant_ref='tenant-f93-a'");
    f347Check(
        f347Count($b,'sites_projects')===1 &&
        (string)$b->query(
            'SELECT site_ref FROM sites_projects'
        )->fetchColumn()==='site-f93-b',
        'forged A GUC does not expose A project to login B'
    );
    $b->exec(
        "INSERT INTO sites_projects(tenant_ref,site_ref,product_code)
         VALUES ('tenant-f93-b','site-f347-local','landing_page')"
    );
    f347Check(
        f347Count($b,'sites_projects')===2,
        'login B can still write only its own lab tenant despite forged GUC'
    );
} finally {
    if($b->inTransaction())$b->rollBack();
}

// An existing connection must stop seeing tenant data after revocation,
// without trusting a transaction-local setting supplied by the caller.
f347Check($admin->exec(
    "UPDATE public.sites_f347_login_map
     SET active=false,revision=2 WHERE login_name='sites_f347_a'"
)===1,'private authority can revoke tenant A');
f347Check(
    f347Count($a,'sites_projects')===0 &&
    f347Count($a,'sites_drafts')===0 &&
    f347Count($b,'sites_projects')===1,
    'same A session denied after revocation while tenant B remains visible'
);
$a->beginTransaction();
try {
    $a->exec("SET LOCAL imagine_sites.tenant_ref='tenant-f93-a'");
    f347Check(
        f347Count($a,'sites_projects')===0,
        'forged original A GUC cannot bypass revoked login mapping'
    );
} finally {
    if($a->inTransaction())$a->rollBack();
}
f347Check(
    (int)$admin->query('SELECT COUNT(*) FROM sites_projects')->fetchColumn()===2 &&
    (int)$admin->query('SELECT COUNT(*) FROM sites_drafts')->fetchColumn()===1,
    'all transient attempts rolled back; original fictional rows preserved'
);

echo "$checks F1-347 mapped-login RLS/forgery/revocation checks passed.\n";
echo "NO-GO: role-per-tenant is not Portal IAM or production pooled SaaS architecture.\n";
