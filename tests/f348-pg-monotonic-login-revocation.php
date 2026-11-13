<?php

declare(strict_types=1);

/**
 * F1-348: one-way, monotonic login mapping revocation in a disposable PG18
 * laboratory. Runs only AFTER F1-347 created two fictional DB login scopes.
 * No production schema, Portal IAM, multi-worker authorization or PII.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_F93_SCHEMA_LAB') !== '1' ||
    getenv('SITES_F347_DB_LOGIN_LAB') !== '1' ||
    getenv('SITES_F348_DB_REVOCATION_LAB') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_PORT') !== '5432' ||
    getenv('DB_DATABASE') !== 'sites_f93_lab' ||
    getenv('DB_USERNAME') !== 'postgres' ||
    (string)getenv('DB_PASSWORD') !== 'f93-synthetic-admin-disposable-only') {
    fwrite(STDERR,"F1-348 disposable PG18 revision test denied.\n");
    exit(1);
}

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f93_lab;connect_timeout=5';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,
       PDO::ATTR_TIMEOUT=>6];
$admin=new PDO($dsn,'postgres','f93-synthetic-admin-disposable-only',$opts);
$b=new PDO($dsn,'sites_f347_b','f347-fictional-org-b-only',$opts);
$checks=0;
function f348Check(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-348: '.$label);
    ++$checks;
}
function f348Denied(callable $fn):bool {
    try {$fn();} catch(PDOException $e) {
        return $e->getCode()==='23514';
    }
    return false;
}
function f348Scope(PDO $db):array {
    return $db->query(
        "SELECT login_name,tenant_ref,active,revision
         FROM public.sites_f347_login_map ORDER BY login_name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Attach a lab-only protection to the mapping created in F1-347.
// Once a login is revoked, never reactivate, delete, remap tenant,
// reuse a revision, or silently rewrite the row. The ONLY permitted
// transition is currently active rev1 -> inactive rev2.
$admin->exec(<<<'SQL'
CREATE FUNCTION public.sites_f348_oneway_revocation()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog AS $f348$
BEGIN
  IF TG_OP='DELETE' THEN
    RAISE EXCEPTION 'synthetic_login_scope_delete_denied'
      USING ERRCODE='23514';
  END IF;
  IF OLD.active IS DISTINCT FROM true OR
     NEW.active IS DISTINCT FROM false OR
     NEW.login_name IS DISTINCT FROM OLD.login_name OR
     NEW.tenant_ref IS DISTINCT FROM OLD.tenant_ref OR
     NEW.revision IS DISTINCT FROM OLD.revision + 1 THEN
    RAISE EXCEPTION 'synthetic_login_scope_rebind_or_revive_denied'
      USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END
$f348$
SQL);
$admin->exec(
    "CREATE TRIGGER sites_f348_login_scope_oneway
     BEFORE UPDATE OR DELETE ON public.sites_f347_login_map
     FOR EACH ROW EXECUTE FUNCTION public.sites_f348_oneway_revocation()"
);

$before=f348Scope($admin);
f348Check(count($before)===2 &&
    $before[0]['login_name']==='sites_f347_a' &&
    in_array($before[0]['active'],[false,'f','0'],true) &&
    (int)$before[0]['revision']===2 &&
    $before[1]['login_name']==='sites_f347_b' &&
    in_array($before[1]['active'],[true,'t','1'],true) &&
    (int)$before[1]['revision']===1,
    'F1-347 already revoked A and B is still active');

f348Check((int)$b->query('SELECT COUNT(*) FROM sites_projects')
    ->fetchColumn()===1,'existing login B can read only its synthetic row');
f348Check(f348Denied(static fn()=>$admin->exec(
    "UPDATE public.sites_f347_login_map SET active=true,revision=3
     WHERE login_name='sites_f347_a'"
)), 'revoked A cannot be reactivated');
f348Check(f348Denied(static fn()=>$admin->exec(
    "UPDATE public.sites_f347_login_map
     SET tenant_ref='tenant-f93-b',revision=3
     WHERE login_name='sites_f347_a'"
)), 'revoked A cannot be remapped to B');
f348Check(f348Denied(static fn()=>$admin->exec(
    "UPDATE public.sites_f347_login_map SET revision=1
     WHERE login_name='sites_f347_a'"
)), 'A revocation revision cannot go backward');
f348Check(f348Denied(static fn()=>$admin->exec(
    "UPDATE public.sites_f347_login_map SET revision=3
     WHERE login_name='sites_f347_a'"
)), 'revoked A cannot silently replace row or advance revision');
f348Check(f348Denied(static fn()=>$admin->exec(
    "DELETE FROM public.sites_f347_login_map
     WHERE login_name='sites_f347_a'"
)), 'revoked A mapping cannot be erased');

f348Check(f348Denied(static fn()=>$admin->exec(
    "UPDATE public.sites_f347_login_map SET active=true,revision=2
     WHERE login_name='sites_f347_b'"
)), 'B active scope cannot be rewritten with false revocation');
f348Check(f348Denied(static fn()=>$admin->exec(
    "UPDATE public.sites_f347_login_map SET active=false,revision=1
     WHERE login_name='sites_f347_b'"
)), 'B cannot reuse revision on revocation');
f348Check(f348Denied(static fn()=>$admin->exec(
    "UPDATE public.sites_f347_login_map
     SET active=false,revision=2,tenant_ref='tenant-f93-a'
     WHERE login_name='sites_f347_b'"
)), 'B cannot be rebound to another tenant during revocation');

// The one permitted transition is a monotonic one-way revocation.
f348Check($admin->exec(
    "UPDATE public.sites_f347_login_map SET active=false,revision=2
     WHERE login_name='sites_f347_b'"
)===1,'B can be revoked precisely once with a new revision');
f348Check((int)$b->query('SELECT COUNT(*) FROM sites_projects')
    ->fetchColumn()===0,
    'existing B session cannot see tenant after immediate revocation');
$b->beginTransaction();
try {
    $b->exec("SET LOCAL imagine_sites.tenant_ref='tenant-f93-b'");
    f348Check((int)$b->query('SELECT COUNT(*) FROM sites_projects')
        ->fetchColumn()===0,
        'forged GUC cannot reopen revoked B DB login');
} finally {
    if($b->inTransaction())$b->rollBack();
}
f348Check(f348Denied(static fn()=>$admin->exec(
    "UPDATE public.sites_f347_login_map SET active=true,revision=3
     WHERE login_name='sites_f347_b'"
)), 'revoked B cannot be revived even by fixture DB owner');
f348Check(f348Denied(static fn()=>$admin->exec(
    "DELETE FROM public.sites_f347_login_map
     WHERE login_name='sites_f347_b'"
)), 'revoked B mapping cannot be erased');

$after=f348Scope($admin);
f348Check(count($after)===2 &&
    in_array($after[0]['active'],[false,'f','0'],true) &&
    in_array($after[1]['active'],[false,'f','0'],true) &&
    (int)$after[0]['revision']===2 &&
    (int)$after[1]['revision']===2,
    'two original login associations remain revoked and monotonic');
f348Check((int)$admin->query(
    'SELECT COUNT(*) FROM public.sites_projects'
)->fetchColumn()===2 &&
    (int)$admin->query(
        'SELECT COUNT(*) FROM public.sites_drafts'
    )->fetchColumn()===1,
    'revocation tests never mutate original fictional project records');

echo "$checks F1-348 one-way DB login revocation checks passed.\n";
echo "NO-GO: laboratory trigger is not operational Portal IAM or pooled tenant access.\n";
