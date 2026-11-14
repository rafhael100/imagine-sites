<?php

declare(strict_types=1);

/**
 * F1-384: adversarial test of versioned, default-deny PostgreSQL18
 * authenticated-session read model. Uses ONLY disposable fictional roles.
 */
if (PHP_SAPI!=='cli' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('SITES_F384_READER_LAB')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='sites_f383_lab') {
    fwrite(STDERR,"F1-384 requires disposable PG18 CI credentials.\n");
    exit(1);
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f383_lab';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_EMULATE_PREPARES=>false];
$db=new PDO($dsn,'postgres','f383-fictional-admin-only',$options);
function f384check(bool $value,string $label):void {
    global $checks;
    if (!$value)throw new RuntimeException('F1-384 FAIL: '.$label);
    ++$checks;
}
function f384fail(callable $action,string $sqlstate):bool {
    try{$action();} catch(PDOException $e) {
        return $e->getCode()===$sqlstate;
    }
    return false;
}
$checks=0;
$reader=$db->query(
    "SELECT rolsuper,rolbypassrls,rolcanlogin,rolcreatedb,rolcreaterole
     FROM pg_roles WHERE rolname='sites_core_read_definer'"
)->fetch(PDO::FETCH_ASSOC);
f384check(is_array($reader) &&
    in_array($reader['rolsuper'],['f',false,'0',0],true) &&
    in_array($reader['rolbypassrls'],['f',false,'0',0],true) &&
    in_array($reader['rolcanlogin'],['f',false,'0',0],true) &&
    in_array($reader['rolcreatedb'],['f',false,'0',0],true) &&
    in_array($reader['rolcreaterole'],['f',false,'0',0],true),
    'function owner cannot login, become superuser, or bypass RLS');
f384check(f384fail(
    static fn()=>$db->query('SELECT * FROM sites_core.read_my_site_projects()'),
    '42501'
),'superuser session_user explicitly denied by internal function');

$db->exec(
    "CREATE ROLE sites_f384_a LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS
      NOCREATEDB NOCREATEROLE NOREPLICATION
      PASSWORD 'f384-fictional-a-only'"
);
$db->exec(
    "CREATE ROLE sites_f384_b LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS
      NOCREATEDB NOCREATEROLE NOREPLICATION
      PASSWORD 'f384-fictional-b-only'"
);
$db->exec('GRANT CONNECT ON DATABASE sites_f383_lab TO sites_f384_a,sites_f384_b');
$a=new PDO($dsn,'sites_f384_a','f384-fictional-a-only',$options);
$b=new PDO($dsn,'sites_f384_b','f384-fictional-b-only',$options);
f384check(f384fail(
    static fn()=>$a->query('SELECT * FROM sites_core.read_my_site_projects()'),
    '42501'
),'default no schema usage or EXECUTE to application SQL role');

$db->exec('GRANT USAGE ON SCHEMA sites_core TO sites_f384_a,sites_f384_b');
$db->exec('GRANT EXECUTE ON FUNCTION sites_core.read_my_site_projects() TO sites_f384_a,sites_f384_b');
f384check(f384fail(
    static fn()=>$a->query('SELECT * FROM sites_core.read_my_site_projects()'),
    '42501'
),'missing DBA login binding denies even with EXECUTE granted');
f384check(f384fail(
    static fn()=>$b->query('SELECT * FROM sites_core.read_my_site_projects()'),
    '42501'
),'second SQL login has no implicit tenant');

$tenantA='00000000-0000-4000-8000-000000000001';
$tenantB='00000000-0000-4000-8000-000000000002';
$siteA='00000000-0000-4000-8000-000000000011';
$siteB='00000000-0000-4000-8000-000000000022';
$db->prepare(
    'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_read,active)
    VALUES (?,?::uuid,true,true),(?,?::uuid,true,true)'
)->execute(['sites_f384_a',$tenantA,'sites_f384_b',$tenantB]);
$db->prepare(
    'INSERT INTO sites_core.contract_refs(tenant_id,contract_ref,product_code,contract_revision)
    VALUES (?,? ,?,1)'
)->execute([$tenantB,'ci-contract-two','landing_page']);
$db->prepare(
    'INSERT INTO sites_core.site_projects(
      tenant_id,site_id,contract_ref,operation_ref,portal_order_ref,
      product_code,command_digest)
     VALUES (?,? ,?,? ,?,? ,?)'
)->execute([$tenantB,$siteB,'ci-contract-two','operation-ci-02',
             'order-ci-02','landing_page',str_repeat('b',64)]);
$db->prepare(
    'INSERT INTO sites_core.editorial_revisions(
       tenant_id,site_id,revision,artifact_digest,review_state)
     VALUES (?,? ,2,? ,?)'
)->execute([$tenantB,$siteB,str_repeat('c',64),'internal_review']);
f384check(f384fail(
    static fn()=>$a->query('SELECT * FROM sites_core.site_projects'),
    '42501'
),'no underlying direct SELECT, independent of RLS');
f384check(f384fail(
    static fn()=>$a->exec(
        "UPDATE sites_core.site_projects SET publishable=true"
    ),'42501'
),'untrusted runtime cannot directly alter publishability');
f384check(f384fail(
    static fn()=>$a->exec('SET ROLE sites_core_read_definer'),
    '42501'
),'NOLOGIN internal definer cannot be assumed by runtime SQL role');
f384check(f384fail(
    static fn()=>$a->exec('SET ROLE sites_f384_b'),
    '42501'
),'tenant A login cannot impersonate tenant B login');

$readA=$a->query(
    'SELECT * FROM sites_core.read_my_site_projects()'
)->fetchAll(PDO::FETCH_ASSOC);
$readB=$b->query(
    'SELECT * FROM sites_core.read_my_site_projects()'
)->fetchAll(PDO::FETCH_ASSOC);
f384check(count($readA)===1 && $readA[0]['site_id']===$siteA &&
    $readA[0]['product_code']==='onepage' &&
    (int)$readA[0]['latest_editorial_revision']===1 &&
    $readA[0]['latest_review_state']==='draft',
    'tenant A sees only own project and exact latest editorial revision');
f384check(count($readB)===1 && $readB[0]['site_id']===$siteB &&
    $readB[0]['product_code']==='landing_page' &&
    (int)$readB[0]['latest_editorial_revision']===2 &&
    $readB[0]['latest_review_state']==='internal_review',
    'tenant B sees only own project and no tenant A row');
foreach([$readA[0],$readB[0]] as $row) {
    f384check($row['lifecycle_state']==='awaiting_official_authority' &&
        in_array($row['publishable'],['f',false,0,'0'],true),
        'project metadata is nonpublishable and cannot imply payment');
}

$a->exec("SET app.tenant_id='$tenantB'");
f384check($a->query(
    'SELECT site_id FROM sites_core.read_my_site_projects()'
)->fetchColumn()===$siteA,
    'untrusted session tenant GUC cannot redirect SQL identity');
$a->exec('RESET app.tenant_id');

$db->exec(
    "UPDATE sites_core.db_login_bindings
     SET active=false WHERE login_name='sites_f384_a'"
);
f384check(f384fail(
    static fn()=>$a->query('SELECT * FROM sites_core.read_my_site_projects()'),
    '42501'
),'out-of-band DBA revocation immediately blocks reads');
$db->exec(
    "UPDATE sites_core.db_login_bindings
     SET active=true,can_read=false WHERE login_name='sites_f384_a'"
);
f384check(f384fail(
    static fn()=>$a->query('SELECT * FROM sites_core.read_my_site_projects()'),
    '42501'
),'permission revocation independently blocks reads');
$db->exec(
    "UPDATE sites_core.db_login_bindings
     SET can_read=true WHERE login_name='sites_f384_a'"
);
f384check($a->query(
    'SELECT site_id FROM sites_core.read_my_site_projects()'
)->fetchColumn()===$siteA,
    'DBA-only restoration returns exactly own project');

$policies=$db->query(
    "SELECT tablename,policyname,cmd,roles::text AS roles
       FROM pg_policies
      WHERE schemaname='sites_core'"
)->fetchAll(PDO::FETCH_ASSOC);
f384check(count($policies)===3,'only three narrowly scoped internal reader policies exist');
foreach($policies as $policy) {
    f384check($policy['policyname']==='f384_internal_reader' &&
        $policy['cmd']==='SELECT' &&
        str_contains($policy['roles'],'sites_core_read_definer') &&
        !str_contains($policy['roles'],'public'),
        'policy restricted to NOLOGIN definer, never application role or PUBLIC');
}
echo "$checks F1-384 authenticated PostgreSQL read-only tenant RLS assertions passed.\n";
echo "NO-GO: DBAs provisioned only synthetic logins; Portal user IAM, API authentication and publication remain unimplemented.\n";
