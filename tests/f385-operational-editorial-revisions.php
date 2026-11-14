<?php

declare(strict_types=1);

/**
 * F1-385: real PG18, separate fictional SQL logins; test the versioned
 * operational editorial migration with immutable revisions and no release.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('SITES_F385_EDITORIAL_LAB')!=='1' ||
    getenv('DB_DATABASE')!=='sites_f383_lab') {
    fwrite(STDERR,"F1-385 must run against disposable PostgreSQL18 CI.\n");
    exit(1);
}
const F385_TENANT_A='00000000-0000-4000-8000-000000000001';
const F385_TENANT_B='00000000-0000-4000-8000-000000000002';
const F385_SITE_A='00000000-0000-4000-8000-000000000033';
const F385_SITE_B='00000000-0000-4000-8000-000000000044';
const F385_SITE_LARAVEL='00000000-0000-4000-8000-000000000055';
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f383_lab';
$opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$admin=new PDO($dsn,'postgres','f383-fictional-admin-only',$opt);
function f385Check(bool $yes,string $message):void {
    global $checks;
    if (!$yes) throw new RuntimeException('F1-385 FAIL: '.$message);
    ++$checks;
}
function f385Denied(callable $f,string $state):bool {
    try {$f();} catch(PDOException $e) {return $e->getCode()===$state;}
    return false;
}
function f385Transition(
    PDO $db,string $site,int $revision,string $action,string $digest
):array {
    $stmt=$db->prepare(
        'SELECT * FROM sites_core.advance_editorial_revision('.
        '?::uuid,?::integer,?::text,?::text)'
    );
    $stmt->execute([$site,$revision,$action,$digest]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row))throw new RuntimeException('No SQL transition result.');
    return $row;
}
$checks=0;
$reader=$admin->query(
    "SELECT rolsuper,rolbypassrls,rolcanlogin,rolcreatedb,rolcreaterole
     FROM pg_roles WHERE rolname='sites_core_edit_definer'"
)->fetch(PDO::FETCH_ASSOC);
f385Check(is_array($reader) &&
    in_array($reader['rolsuper'],[false,'f',0,'0'],true) &&
    in_array($reader['rolbypassrls'],[false,'f',0,'0'],true) &&
    in_array($reader['rolcanlogin'],[false,'f',0,'0'],true),
    'editorial function owner cannot login, escalate or bypass RLS');
f385Check(f385Denied(static fn()=>f385Transition(
    $admin,F385_SITE_A,0,'register',str_repeat('a',64)
),'42501'),'DBA session cannot impersonate authenticated Imagine editor');

foreach([
    'sites_f385_editor_a'=>'f385-fictional-editor-a',
    'sites_f385_reviewer_a'=>'f385-fictional-reviewer-a',
    'sites_f385_editor_b'=>'f385-fictional-editor-b',
    'sites_f385_reviewer_b'=>'f385-fictional-reviewer-b',
] as $login=>$password) {
    $admin->exec(
        'CREATE ROLE '.$login.
        " LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS
          NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '".$password."'"
    );
    $admin->exec('GRANT CONNECT ON DATABASE sites_f383_lab TO '.$login);
}
$editorA=new PDO($dsn,'sites_f385_editor_a','f385-fictional-editor-a',$opt);
$reviewerA=new PDO($dsn,'sites_f385_reviewer_a','f385-fictional-reviewer-a',$opt);
$editorB=new PDO($dsn,'sites_f385_editor_b','f385-fictional-editor-b',$opt);
$reviewerB=new PDO($dsn,'sites_f385_reviewer_b','f385-fictional-reviewer-b',$opt);
f385Check(f385Denied(static fn()=>f385Transition(
    $editorA,F385_SITE_A,0,'register',str_repeat('a',64)
),'42501'),'no database schema/EXECUTE granted by migration');

foreach([
    'sites_f385_editor_a','sites_f385_reviewer_a',
    'sites_f385_editor_b','sites_f385_reviewer_b',
] as $role) {
    $admin->exec('GRANT USAGE ON SCHEMA sites_core TO '.$role);
    $admin->exec(
        'GRANT EXECUTE ON FUNCTION sites_core.advance_editorial_revision('.
        'uuid,integer,text,text) TO '.$role
    );
}
f385Check(f385Denied(static fn()=>f385Transition(
    $editorA,F385_SITE_A,0,'register',str_repeat('a',64)
),'42501'),'grant without DBA binding still denied');

$bind=$admin->prepare(
    'INSERT INTO sites_core.editorial_operator_bindings
    (login_name,tenant_id,duty,active)
    VALUES (?,?::uuid,?,true)'
);
foreach([
    ['sites_f385_editor_a',F385_TENANT_A,'editor'],
    ['sites_f385_reviewer_a',F385_TENANT_A,'reviewer'],
    ['sites_f385_editor_b',F385_TENANT_B,'editor'],
    ['sites_f385_reviewer_b',F385_TENANT_B,'reviewer'],
] as $record) $bind->execute($record);

$insert=$admin->prepare(
    'INSERT INTO sites_core.site_projects
    (tenant_id,site_id,contract_ref,operation_ref,portal_order_ref,
    product_code,command_digest)
    VALUES (?::uuid,?::uuid,?,?,?,?,?)'
);
$insert->execute([
    F385_TENANT_A,F385_SITE_A,'ci-contract-one','operation-f385-a',
    'order-f385-a','onepage',str_repeat('a',64),
]);
$insert->execute([
    F385_TENANT_B,F385_SITE_B,'ci-contract-two','operation-f385-b',
    'order-f385-b','landing_page',str_repeat('b',64),
]);
$insert->execute([
    F385_TENANT_A,F385_SITE_LARAVEL,'ci-contract-one',
    'operation-f385-laravel','order-f385-laravel','onepage',
    str_repeat('c',64),
]);

f385Check(f385Denied(static fn()=> $editorA->query(
    'SELECT * FROM sites_core.editorial_revisions'
),'42501'),'editor cannot read underlying table directly');
f385Check(f385Denied(static fn()=> $reviewerA->exec(
    "UPDATE sites_core.editorial_revisions SET publishable=true"
),'42501'),'reviewer cannot mutate any revision directly');
f385Check(f385Denied(static fn()=> $editorA->exec(
    'SET ROLE sites_core_edit_definer'
),'42501'),'editor cannot impersonate non-login security definer');
f385Check(f385Denied(static fn()=> $editorA->exec(
    'SET ROLE sites_f385_reviewer_a'
),'42501'),'editor cannot impersonate independent reviewer');
f385Check(f385Denied(static fn()=>f385Transition(
    $reviewerA,F385_SITE_A,0,'register',str_repeat('a',64)
),'42501'),'reviewer cannot create initial draft');
f385Check(f385Denied(static fn()=>f385Transition(
    $editorA,F385_SITE_B,0,'register',str_repeat('a',64)
),'42501'),'editor cannot create draft for neighboring company');
f385Check(f385Denied(static fn()=>f385Transition(
    $editorA,F385_SITE_A,0,'register',str_repeat('F',64)
),'22023'),'invalid SHA-256 must fail before insertion');

$originalA=str_repeat('a',64);
$originalB=str_repeat('b',64);
$revisedB=str_repeat('c',64);
f385Check((int)f385Transition(
    $editorA,F385_SITE_A,0,'register',$originalA
)['next_revision']===1,'Onepage first immutable revision');
f385Check(f385Denied(static fn()=>f385Transition(
    $editorA,F385_SITE_A,0,'register',$originalA
),'40001'),'exact replay cannot create a duplicate first revision');
f385Check(f385Denied(static fn()=>f385Transition(
    $editorA,F385_SITE_A,1,'approve_internal',$originalA
),'42501'),'editor cannot approve own Onepage');
f385Check(f385Denied(static fn()=>f385Transition(
    $editorA,F385_SITE_A,1,'submit',str_repeat('d',64)
),'42501'),'editor must submit the exact existing digest');
f385Check((int)f385Transition(
    $editorA,F385_SITE_A,1,'submit',$originalA
)['next_revision']===2,'editor submits Onepage for independent review');
f385Check(f385Denied(static fn()=>f385Transition(
    $reviewerA,F385_SITE_A,2,'approve_internal',str_repeat('d',64)
),'42501'),'reviewer cannot accept a mismatched digest');
$approvedA=f385Transition(
    $reviewerA,F385_SITE_A,2,'approve_internal',$originalA
);
f385Check((int)$approvedA['next_revision']===3 &&
    $approvedA['next_state']==='awaiting_portal_acceptance',
    'reviewer approves Onepage internally but not as customer');

f385Check((int)f385Transition(
    $editorB,F385_SITE_B,0,'register',$originalB
)['next_revision']===1,'Landing first draft');
f385Check((int)f385Transition(
    $editorB,F385_SITE_B,1,'submit',$originalB
)['next_revision']===2,'Landing submitted');
f385Check((int)f385Transition(
    $reviewerB,F385_SITE_B,2,'rework',$originalB
)['next_revision']===3,'reviewer demands Landing rework');
f385Check((int)f385Transition(
    $editorB,F385_SITE_B,3,'revise',$revisedB
)['next_revision']===4,'editor submits a new digest after rework');
f385Check(f385Denied(static fn()=>f385Transition(
    $editorB,F385_SITE_B,3,'submit',$revisedB
),'40001'),'stale editor revision denied');
f385Check((int)f385Transition(
    $editorB,F385_SITE_B,4,'submit',$revisedB
)['next_revision']===5,'Landing revised submission');
f385Check((int)f385Transition(
    $reviewerB,F385_SITE_B,5,'approve_internal',$revisedB
)['next_revision']===6,'independent reviewer approves new Landing digest');
f385Check(f385Denied(static fn()=>f385Transition(
    $reviewerB,F385_SITE_B,6,'approve_internal',$revisedB
),'42501'),'no transition can auto-publish after internal approval');

$rows=$admin->query(
    "SELECT site_id,revision,artifact_digest,review_state,
            client_accepted,publishable
     FROM sites_core.editorial_revisions
     WHERE site_id IN ('".F385_SITE_A."','".F385_SITE_B."')
     ORDER BY site_id,revision"
)->fetchAll(PDO::FETCH_ASSOC);
f385Check(count($rows)===9,'append-only history has exactly nine revisions');
foreach ($rows as $row) {
    f385Check(in_array($row['client_accepted'],[false,'f',0,'0'],true) &&
        in_array($row['publishable'],[false,'f',0,'0'],true),
        'all historical editorial states permanently nonpublishable');
}
f385Check(f385Denied(static fn()=> $admin->exec(
    "UPDATE sites_core.editorial_revisions SET client_accepted=true
     WHERE site_id='".F385_SITE_A."'"
),'23514'),'even DBA fixture cannot forge client acceptance by row update');
$admin->exec(
    "UPDATE sites_core.editorial_operator_bindings
      SET active=false WHERE login_name='sites_f385_editor_a'"
);
f385Check(f385Denied(static fn()=>f385Transition(
    $editorA,F385_SITE_LARAVEL,0,'register',$originalA
),'42501'),'DBA revocation immediately denies pending operator action');
$admin->exec(
    "UPDATE sites_core.editorial_operator_bindings
      SET active=true WHERE login_name='sites_f385_editor_a'"
);
f385Check(f385Denied(static fn()=>f385Transition(
    $editorB,F385_SITE_A,0,'register',$originalA
),'42501'),'editor B cannot write a site from A using own valid login');
echo "$checks F1-385 PG18 editorial permission, RLS, CAS and audit assertions passed.\n";
echo "NO-GO: all operator identities synthetic, no Portal acceptance and no publisher.\n";
