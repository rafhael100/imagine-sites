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
$names=[
    'tenant_accounts','contract_refs','site_projects',
    'replay_nonces','editorial_revisions','site_outbox',
];
$boundModel=(int)$db->query(
    "SELECT count(*) FROM information_schema.tables
     WHERE table_schema='sites_core' AND table_name='db_login_bindings'"
)->fetchColumn()===1;
if ($boundModel) $names[]='db_login_bindings';
$editorialModel=(int)$db->query(
    "SELECT count(*) FROM information_schema.tables
     WHERE table_schema='sites_core' AND table_name='editorial_operator_bindings'"
)->fetchColumn()===1;
if ($editorialModel) $names[]='editorial_operator_bindings';
$state=$db->query(
    "SELECT c.relname AS tablename,
            c.relrowsecurity AS rowsecurity,
            c.relforcerowsecurity AS forcerowsecurity
       FROM pg_class c
       JOIN pg_namespace n ON n.oid=c.relnamespace
      WHERE n.nspname='sites_core' AND c.relkind='r'"
)->fetchAll(PDO::FETCH_ASSOC);
f383Check(count($state)===count($names),
    'exact six core tables after reversible Laravel migration');
$seen=[];
foreach ($state as $row) {
    $seen[]=$row['tablename'];
    f383Check(in_array($row['rowsecurity'],[true,'t','1',1],true) &&
        in_array($row['forcerowsecurity'],[true,'t','1',1],true),
        'RLS forced for '.$row['tablename']);
}
sort($seen);
$expected=$names;sort($expected);
f383Check($seen===$expected,'exact table names, no unexpected public data');
$policyRows=$db->query(
    "SELECT tablename,policyname,roles,cmd FROM pg_policies
      WHERE schemaname='sites_core'"
)->fetchAll(PDO::FETCH_ASSOC);
if ($boundModel) {
    f383Check(count($policyRows)===($editorialModel?7:3),
        'only reviewed internal read/edit-definer policies expected');
    foreach($policyRows as $policy) {
        $readerOnly=$policy['policyname']==='f384_internal_reader' &&
            $policy['cmd']==='SELECT' &&
            str_contains((string)$policy['roles'],'sites_core_read_definer');
        $editorialOnly=$editorialModel &&
            in_array($policy['policyname'],[
                'f385_editorial_reader','f385_editorial_writer',
            ],true) &&
            in_array($policy['cmd'],['SELECT','INSERT'],true) &&
            str_contains((string)$policy['roles'],'sites_core_edit_definer');
        f383Check(($readerOnly || $editorialOnly) &&
            !str_contains(strtolower((string)$policy['roles']),'public'),
            'only narrow NOLOGIN internal read/editorial policy permitted');
    }
} else {
    f383Check(count($policyRows)===0,
        'foundational schema must not grant any tenant RLS policy');
}

$db->exec(
    "CREATE ROLE sites_f383_untrusted LOGIN NOINHERIT NOSUPERUSER
      NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
      PASSWORD 'f383-fictional-untrusted-only'"
);
$db->exec('GRANT CONNECT ON DATABASE sites_f383_lab TO sites_f383_untrusted');
$db->exec('GRANT USAGE ON SCHEMA sites_core TO sites_f383_untrusted');
$untrusted=new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f383_lab',
    'sites_f383_untrusted','f383-fictional-untrusted-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
f383Check(f383Failed(static fn()=> $untrusted->query(
    'SELECT * FROM sites_core.tenant_accounts'
),'42501'),'restricted SQL principal cannot read tenant table without grant');
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
