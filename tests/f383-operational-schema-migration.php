<?php

declare(strict_types=1);

/**
 * F1-383: disposable PostgreSQL18 schema migration validation.
 * Uses fictional accounts only; never a production migration operator.
 */
if (PHP_SAPI!=='cli' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('SITES_F383_PG_MIGRATION_LAB')!=='1' ||
    getenv('DB_DATABASE')!=='sites_f383_lab' ||
    getenv('DB_HOST')!=='127.0.0.1') {
    fwrite(STDERR,"F1-383 refuses non-disposable database settings.\n");
    exit(1);
}
$db=new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f383_lab',
    'postgres','f383-fictional-admin-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6]
);
function f383Check(bool $condition,string $reason):void {
    global $checks;
    if (!$condition)throw new RuntimeException('F1-383 FAIL: '.$reason);
    ++$checks;
}
function f383Failed(callable $work,string $sqlstate):bool {
    try{$work();}catch(PDOException $e){return $e->getCode()===$sqlstate;}
    return false;
}
$checks=0;
// This workflow applies the complete migration directory. Keep an explicit
// inventory so a missing migration or an unexpected relation cannot pass.
$names=[
    'tenant_accounts','contract_refs','site_projects',
    'replay_nonces','editorial_revisions','site_outbox',
    'db_login_bindings','editorial_operator_bindings',
    'release_registry_principals','release_artifacts','release_binding_events',
    'portal_project_intakes',
];
$registryTables=['release_registry_principals','release_artifacts','release_binding_events'];
$state=$db->query(
    "SELECT c.relname AS tablename,c.relkind,pg_get_userbyid(c.relowner) AS owner,
            c.relrowsecurity AS rowsecurity,
            c.relforcerowsecurity AS forcerowsecurity
       FROM pg_class c
       JOIN pg_namespace n ON n.oid=c.relnamespace
      WHERE n.nspname='sites_core' AND c.relkind IN ('r','p','v','m','f')"
)->fetchAll(PDO::FETCH_ASSOC);
f383Check(count($state)===count($names),
    'exact twelve core tables after all reversible Laravel migrations');
$seen=[];
foreach ($state as $row) {
    $seen[]=$row['tablename'];
    f383Check($row['relkind']==='r' &&
        in_array($row['rowsecurity'],[true,'t','1',1],true) &&
        in_array($row['forcerowsecurity'],[true,'t','1',1],true),
        'RLS forced for '.$row['tablename']);
    if (in_array($row['tablename'],$registryTables,true)) {
        f383Check($row['owner']==='sites_core_release_owner',
            'registry table belongs only to its internal owner: '.$row['tablename']);
    }
}
sort($seen);
$expected=$names;sort($expected);
f383Check($seen===$expected,'exact table names, no unexpected public data');
$policyRows=$db->query(
    "SELECT tablename,policyname,array_to_json(roles)::text AS roles,
            cmd,permissive,qual,with_check FROM pg_policies
      WHERE schemaname='sites_core'"
)->fetchAll(PDO::FETCH_ASSOC);
$readPolicy=static fn(array $roles):array=>['SELECT',$roles,'PERMISSIVE','true',null];
$writePolicy=static fn(array $roles):array=>['INSERT',$roles,'PERMISSIVE',null,'true'];
$registryReaders=['sites_core_release_owner','sites_core_release_reader','sites_core_release_writer'];
$registryCoreReaders=['sites_core_release_reader','sites_core_release_writer'];
$expectedPolicies=[
    'db_login_bindings.f384_internal_reader'=>$readPolicy(['sites_core_read_definer']),
    'site_projects.f384_internal_reader'=>$readPolicy(['sites_core_read_definer']),
    'editorial_revisions.f384_internal_reader'=>$readPolicy(['sites_core_read_definer']),
    'editorial_operator_bindings.f385_editorial_reader'=>$readPolicy(['sites_core_edit_definer']),
    'site_projects.f385_editorial_reader'=>$readPolicy(['sites_core_edit_definer']),
    'editorial_revisions.f385_editorial_reader'=>$readPolicy(['sites_core_edit_definer']),
    'editorial_revisions.f385_editorial_writer'=>$writePolicy(['sites_core_edit_definer']),
    'release_registry_principals.release_principals_internal_read'=>$readPolicy($registryReaders),
    'release_registry_principals.release_principals_custody_lock'=>[
        'UPDATE',['sites_core_release_owner'],'PERMISSIVE','true','true',
    ],
    'release_artifacts.release_artifacts_internal_read'=>$readPolicy($registryReaders),
    'release_binding_events.release_bindings_internal_read'=>$readPolicy($registryReaders),
    'release_artifacts.release_artifacts_internal_write'=>$writePolicy(['sites_core_release_writer']),
    'release_binding_events.release_bindings_internal_write'=>$writePolicy(['sites_core_release_writer']),
    'tenant_accounts.release_registry_tenants'=>$readPolicy($registryCoreReaders),
    'contract_refs.release_registry_contracts'=>$readPolicy($registryCoreReaders),
    'site_projects.release_registry_projects'=>$readPolicy($registryCoreReaders),
    'editorial_revisions.release_registry_editorial'=>$readPolicy($registryCoreReaders),
    'tenant_accounts.publication_journal_tenants'=>$readPolicy(['sites_publication_writer']),
    'contract_refs.publication_journal_contracts'=>$readPolicy(['sites_publication_writer']),
    'site_projects.publication_journal_projects'=>$readPolicy(['sites_publication_writer']),
    'editorial_revisions.publication_journal_editorial'=>$readPolicy(['sites_publication_writer']),
    'release_registry_principals.publication_journal_registry_principals'=>$readPolicy(['sites_publication_writer']),
    'release_artifacts.publication_journal_artifacts'=>$readPolicy(['sites_publication_writer']),
    'release_binding_events.publication_journal_bindings'=>$readPolicy(['sites_publication_writer']),
    'db_login_bindings.intake_binding_read'=>$readPolicy(['sites_core_intake_definer']),
    'tenant_accounts.intake_tenant_read'=>$readPolicy(['sites_core_intake_definer']),
    'contract_refs.intake_contract_read'=>$readPolicy(['sites_core_intake_definer']),
    'site_projects.intake_project_read'=>$readPolicy(['sites_core_intake_definer']),
    'site_outbox.intake_outbox_read'=>$readPolicy(['sites_core_intake_definer']),
    'portal_project_intakes.intake_receipt_read'=>$readPolicy(['sites_core_intake_definer']),
    'contract_refs.intake_contract_insert'=>$writePolicy(['sites_core_intake_definer']),
    'site_projects.intake_project_insert'=>$writePolicy(['sites_core_intake_definer']),
    'site_outbox.intake_outbox_insert'=>$writePolicy(['sites_core_intake_definer']),
    'portal_project_intakes.intake_receipt_insert'=>$writePolicy(['sites_core_intake_definer']),
    'db_login_bindings.intake_binding_lock'=>['UPDATE',['sites_core_intake_definer'],'PERMISSIVE','true','false'],
    'tenant_accounts.intake_tenant_lock'=>['UPDATE',['sites_core_intake_definer'],'PERMISSIVE','true','false'],
    'contract_refs.intake_contract_lock'=>['UPDATE',['sites_core_intake_definer'],'PERMISSIVE','true','false'],
];
$actualPolicies=[];
foreach ($policyRows as $policy) {
    $roles=json_decode($policy['roles'],true,512,JSON_THROW_ON_ERROR);
    sort($roles);
    $actualPolicies[$policy['tablename'].'.'.$policy['policyname']]=[
        $policy['cmd'],$roles,$policy['permissive'],$policy['qual'],$policy['with_check'],
    ];
}
ksort($actualPolicies);ksort($expectedPolicies);
f383Check($actualPolicies===$expectedPolicies,
    'exact table, command, roles and predicates for all thirty-seven internal policies; no PUBLIC or extra policy');

$registryRoles=$db->query(
    "SELECT rolname,rolcanlogin,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolreplication,rolinherit
       FROM pg_roles WHERE rolname IN
       ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader')
      ORDER BY rolname"
)->fetchAll(PDO::FETCH_ASSOC);
f383Check(array_column($registryRoles,'rolname')===[
    'sites_core_release_owner','sites_core_release_reader','sites_core_release_writer',
],'exact three registry internal roles exist');
foreach ($registryRoles as $role) {
    foreach (['rolcanlogin','rolsuper','rolbypassrls','rolcreatedb','rolcreaterole','rolreplication','rolinherit'] as $attribute) {
        f383Check(in_array($role[$attribute],[false,'f',0,'0'],true),
            $role['rolname'].' has no '.$attribute.' capability');
    }
}
foreach (['db_login_bindings','editorial_operator_bindings','portal_project_intakes',...$registryTables] as $table) {
    f383Check((int)$db->query('SELECT count(*) FROM sites_core.'.$table)->fetchColumn()===0,
        'migration seeds no principal, artifact or binding: '.$table);
}

$journalTables=['journal_events','journal_operations','journal_principals'];
$journalState=$db->query(
    "SELECT c.relname,c.relkind,pg_get_userbyid(c.relowner) AS owner,
            c.relrowsecurity,c.relforcerowsecurity
       FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
      WHERE n.nspname='sites_publication' AND c.relkind IN ('r','p','v','m','f')
      ORDER BY c.relname"
)->fetchAll(PDO::FETCH_ASSOC);
f383Check(array_column($journalState,'relname')===$journalTables,
    'exact three journal tables, no additional relations');
foreach ($journalState as $row) {
    f383Check($row['relkind']==='r' && $row['owner']==='sites_publication_owner' &&
        in_array($row['relrowsecurity'],[true,'t','1',1],true) &&
        in_array($row['relforcerowsecurity'],[true,'t','1',1],true),
        'journal internal owner and FORCE RLS: '.$row['relname']);
    f383Check((int)$db->query('SELECT count(*) FROM sites_publication.'.$row['relname'])->fetchColumn()===0,
        'journal migration seeds no principal or publication history: '.$row['relname']);
}
$journalRoles=$db->query(
    "SELECT rolname,rolcanlogin,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolreplication,rolinherit
       FROM pg_roles WHERE rolname IN
       ('sites_publication_owner','sites_publication_writer','sites_publication_reader')
      ORDER BY rolname"
)->fetchAll(PDO::FETCH_ASSOC);
f383Check(array_column($journalRoles,'rolname')===[
    'sites_publication_owner','sites_publication_reader','sites_publication_writer',
],'exact three journal internal roles exist');
foreach ($journalRoles as $role) {
    foreach (['rolcanlogin','rolsuper','rolbypassrls','rolcreatedb','rolcreaterole','rolreplication','rolinherit'] as $attribute) {
        f383Check(in_array($role[$attribute],[false,'f',0,'0'],true),
            $role['rolname'].' has no '.$attribute.' capability');
    }
}

$db->exec(
    "CREATE ROLE sites_f383_untrusted LOGIN NOINHERIT NOSUPERUSER
      NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
      PASSWORD 'f383-fictional-untrusted-only'"
);
$db->exec('GRANT CONNECT ON DATABASE sites_f383_lab TO sites_f383_untrusted');
$db->exec('GRANT USAGE ON SCHEMA sites_core TO sites_f383_untrusted');
$db->exec('GRANT USAGE ON SCHEMA sites_publication TO sites_f383_untrusted');
$untrusted=new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f383_lab',
    'sites_f383_untrusted','f383-fictional-untrusted-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
foreach ($names as $table) {
    f383Check(f383Failed(static fn()=> $untrusted->query(
        'SELECT * FROM sites_core.'.$table
    ),'42501'),'restricted SQL principal cannot read table without grant: '.$table);
}
foreach ($journalTables as $table) {
    f383Check(f383Failed(static fn()=> $untrusted->query(
        'SELECT * FROM sites_publication.'.$table
    ),'42501'),'restricted SQL principal cannot read journal table: '.$table);
}
f383Check(f383Failed(static fn()=> $untrusted->exec(
    'INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref)
     VALUES (\'00000000-0000-4000-8000-000000000003\',\'forged\')'
),'42501'),'restricted SQL principal cannot write tables');
f383Check(f383Failed(static fn()=> $untrusted->exec(
    'SET ROLE postgres'
),'42501'),'untrusted SQL principal cannot impersonate privileged DBA');

$a='00000000-0000-4000-8000-000000000001';
$b='00000000-0000-4000-8000-000000000002';
$s='00000000-0000-4000-8000-000000000011';
$e='00000000-0000-4000-8000-000000000012';
$hash=str_repeat('a',64);
$insert=$db->prepare(
    'INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES (?,?)'
);
$insert->execute([$a,'ci-company-a']);
$insert->execute([$b,'ci-company-b']);
$c=$db->prepare(
    'INSERT INTO sites_core.contract_refs
    (tenant_id,contract_ref,product_code,contract_revision) VALUES (?,?,?,?)'
);
$c->execute([$a,'ci-contract-one','onepage',1]);
f383Check(f383Failed(static fn()=> $c->execute([
    $b,'ci-contract-invalid','performance',1
]),'23514'),'non-Sites product prohibited');
f383Check(f383Failed(static fn()=> $db->exec(
    "UPDATE sites_core.contract_refs
        SET external_payment_verified=true WHERE tenant_id='$a'"
),'23514'),'fictional contract cannot be marked externally paid');
$p=$db->prepare(
    'INSERT INTO sites_core.site_projects
    (tenant_id,site_id,contract_ref,operation_ref,
     portal_order_ref,product_code,command_digest)
    VALUES (?,?,?,?,?,?,?)'
);
$p->execute([$a,$s,'ci-contract-one','operation-ci-01',
              'order-ci-01','onepage',$hash]);
f383Check(f383Failed(static fn()=> $p->execute([
    $b,$e,'ci-contract-one','operation-ci-02',
    'order-ci-02','onepage',$hash
]),'23503'),'cross-tenant project contract FK fails');
f383Check(f383Failed(static fn()=> $p->execute([
    $a,$e,'ci-contract-one','operation-ci-01',
    'order-ci-02','onepage',$hash
]),'23505'),'idempotent operation unique within tenant');
f383Check(f383Failed(static fn()=> $db->exec(
    "UPDATE sites_core.site_projects
        SET publishable=true WHERE site_id='$s'"
),'23514'),'publication cannot be enabled by a database update');
f383Check(f383Failed(static fn()=> $db->exec(
    "UPDATE sites_core.site_projects
        SET authority_verified=true WHERE site_id='$s'"
),'23514'),'official authority cannot be self-asserted');
$d=$db->prepare(
    'INSERT INTO sites_core.editorial_revisions
    (tenant_id,site_id,revision,artifact_digest)
    VALUES (?,?,?,?)'
);
$d->execute([$a,$s,1,$hash]);
f383Check(f383Failed(static fn()=> $db->exec(
    "UPDATE sites_core.editorial_revisions
        SET client_accepted=true WHERE site_id='$s'"
),'23514'),'client acceptance cannot be forged by raw SQL');
$out=$db->prepare(
    'INSERT INTO sites_core.site_outbox
    (tenant_id,event_id,site_id,event_type,payload_sha256)
    VALUES (?,?,?,?,?)'
);
$out->execute([$a,$e,$s,'ProjectQueued',$hash]);
f383Check(f383Failed(static fn()=> $db->exec(
    "UPDATE sites_core.site_outbox
        SET delivery_state='delivered' WHERE site_id='$s'"
),'23514'),'outbox cannot bypass blocked delivery state');
$nonce=$db->prepare(
    'INSERT INTO sites_core.replay_nonces(issuer,nonce,tenant_id,expires_at)
    VALUES (?,?,?,clock_timestamp()+interval \'60 seconds\')'
);
$nonce->execute(['imagine-portal','nonce-ci-one',$a]);
f383Check(f383Failed(static fn()=> $nonce->execute(
    ['imagine-portal','nonce-ci-one',$b]
),'23505'),'nonce globally unique across company boundaries');
f383Check((int)$db->query(
    "SELECT count(*) FROM sites_core.site_projects WHERE publishable=false"
)->fetchColumn()===1,
    'inert dummy project persists for schema validation');
echo "$checks F1-383 irreversible authority gates and versioned PG18 schema assertions passed.\n";
echo "NO-GO: no RLS service policy, official Portal IAM, PSP or publishing function installed.\n";
