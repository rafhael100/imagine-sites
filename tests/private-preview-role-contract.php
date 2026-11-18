<?php
declare(strict_types=1);
// Synthetic local laboratory only. No HTTP fixture, operational grant or backup evidence.
if (PHP_SAPI !== 'cli' || getenv('SITES_LOCAL_ROLE_CONTRACT') !== '1') exit(2);
require __DIR__.'/../apps/sites/vendor/autoload.php';
$app=require __DIR__.'/../apps/sites/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $e):void {
    fwrite(STDERR,'ROLE_CONTRACT_FAILED '.get_class($e).': '.$e->getMessage()."\n");exit(1);
});
use Illuminate\Support\Facades\DB;
use Imagine\Sites\Production\{PrivatePreviewComparison,PrivatePreviewOverview,PrivatePreviewWorkspace,SiteBrief};
$dir=getenv('ROLE_CONTRACT_PGDATA');$cluster=getenv('ROLE_CONTRACT_CLUSTER');
$login=getenv('ROLE_CONTRACT_LOGIN');$passwordFile=getenv('ROLE_CONTRACT_PASSWORD_FILE');
if(!$dir||!$cluster||!$login||!$passwordFile||!is_dir($dir)||
   !str_starts_with(realpath($dir),sys_get_temp_dir().'/sites486-role-contract-')||
   realpath(dirname($passwordFile))!==realpath(dirname($dir)))throw new RuntimeException('Own disposable target required');
$password=trim(file_get_contents($passwordFile));
if(strlen($password)<32)throw new RuntimeException('Synthetic lab password missing');
$expected=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>55448,'database'=>'postgres','username'=>$login,'password'=>$password,'charset'=>'utf8','prefix'=>'','sslmode'=>'disable'];
config()->set('database.default','role_contract');
config()->set('database.connections.role_contract',$expected);
DB::purge('role_contract');$connection=DB::connection('role_contract');
foreach($expected as $key=>$value)if($connection->getConfig($key)!==$value)throw new RuntimeException('Effective Laravel config mismatch');
if($connection->getConfig('url'))throw new RuntimeException('Unexpected URL');
$admin=$connection->getPdo();
$id=$admin->query("SELECT current_database() AS db,session_user AS login,current_user AS actor,host(inet_server_addr()) AS address,inet_server_port() AS port,current_setting('data_directory') AS directory,current_setting('server_version_num') AS version,(SELECT system_identifier::text FROM pg_control_system()) AS cluster")->fetch(PDO::FETCH_ASSOC);
if($id['db']!=='postgres'||$id['login']!==$login||$id['actor']!==$login||$id['address']!=='127.0.0.1'||(int)$id['port']!==55448||realpath($id['directory'])!==realpath($dir)||$id['cluster']!==$cluster||(int)$id['version']<180000||(int)$id['version']>=190000)throw new RuntimeException('Disposable SQL identity mismatch');
if($admin->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()!==null)throw new RuntimeException('Expected absent namespace');
if((int)$admin->query("SELECT count(*) FROM pg_roles WHERE rolname LIKE 'lab486_%'")->fetchColumn()!==0)throw new RuntimeException('Expected fresh synthetic roles');
echo "PREFLIGHT OK: effective Laravel config and PG18 database/cluster/login/data-directory/loopback verified before writes\n";
if(getenv('ROLE_CONTRACT_PREFLIGHT_ONLY')==='1')exit(0);
// Only this guarded, newly initialized cluster receives role/DDL fixtures.
$admin->exec('CREATE ROLE lab486_owner NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
$admin->exec('GRANT CREATE ON DATABASE postgres TO lab486_owner');
$admin->exec('SET ROLE lab486_owner');
try {
    $migration=require __DIR__.'/../apps/sites/database/migrations/2026_11_16_000004_add_private_preview_workspace.php';
    $migration->up(); // Existing migration unchanged; creates objects under the non-login owner.
} finally {$admin->exec('RESET ROLE');}
$admin->exec('REVOKE CREATE ON DATABASE postgres FROM lab486_owner');
$companies=['a'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','b'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
$profiles=['author'=>[true,false],'reviewer'=>[false,true],'reader'=>[false,false]];
$connections=[];$workspaces=[];
foreach($companies as $tenant=>$company)foreach($profiles as $profile=>[$edit,$review]) {
    $role="lab486_{$tenant}_{$profile}";
    $admin->exec("CREATE ROLE $role LOGIN PASSWORD ".$admin->quote($password)." NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT");
    $admin->exec("GRANT USAGE ON SCHEMA sites_preview TO $role; GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO $role; GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text) TO $role");
    if($edit)$admin->exec("GRANT INSERT ON sites_preview.projects,sites_preview.versions TO $role");
    if($review)$admin->exec("GRANT EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text) TO $role");
    $s=$admin->prepare("INSERT INTO sites_preview.principals VALUES(?,?::uuid,?::boolean,?::boolean,now()+interval '1 hour',NULL)");
    $s->execute([$role,$company,$edit?'true':'false',$review?'true':'false']);
    $connections[$tenant][$profile]=new PDO('pgsql:host=127.0.0.1;port=55448;dbname=postgres',$role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $workspaces[$tenant][$profile]=new PrivatePreviewWorkspace($connections[$tenant][$profile]);
}
$count=0;
$ok=function(bool $condition,string $label)use(&$count):void {if(!$condition)throw new RuntimeException('ASSERT: '.$label);$count++;};
$denied=function(callable $operation,string $label)use($ok):void {
    try{$operation();}catch(PDOException|RuntimeException $e){$ok(true,$label);return;}
    $ok(false,$label);
};
require __DIR__.'/fixtures/preview-feedback/upgrade.php';
$owner=$admin->query("SELECT NOT rolcanlogin AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AS safe FROM pg_roles WHERE rolname='lab486_owner'")->fetchColumn();
$ok($owner===true,'NOLOGIN non-bypass owner');
$ok((int)$admin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='sites_preview' AND c.relkind IN ('r','S') AND c.relowner='lab486_owner'::regrole")->fetchColumn()===6,'all five tables and audit sequence owned by synthetic owner');
$ok((int)$admin->query("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='sites_preview' AND p.proowner='lab486_owner'::regrole")->fetchColumn()===6,'all six functions have non-bypass owner');
$ok($admin->query("SELECT nspowner='lab486_owner'::regrole FROM pg_namespace WHERE nspname='sites_preview'")->fetchColumn()===true,'schema owner');
$ok((int)$admin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='sites_preview' AND c.relname IN ('projects','versions','reviews','audit') AND c.relrowsecurity AND c.relforcerowsecurity")->fetchColumn()===4,'FORCE RLS on all four scoped tables');
// PUBLIC ACL inspection only: do not alter migration grants or invoke helper functions.
$ok((int)$admin->query("SELECT count(*) FROM pg_namespace n CROSS JOIN LATERAL aclexplode(coalesce(n.nspacl,acldefault('n',n.nspowner))) a WHERE n.nspname='sites_preview' AND a.grantee=0")->fetchColumn()===0,'no PUBLIC schema privileges');
$ok((int)$admin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace CROSS JOIN LATERAL aclexplode(coalesce(c.relacl,acldefault(CASE WHEN c.relkind='S' THEN 'S'::\"char\" ELSE 'r'::\"char\" END,c.relowner))) a WHERE n.nspname='sites_preview' AND c.relkind IN ('r','S') AND a.grantee=0")->fetchColumn()===0,'no PUBLIC table/sequence privileges');
$publicFunctions=$admin->query("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE n.nspname='sites_preview' AND a.grantee=0 AND a.privilege_type='EXECUTE' ORDER BY p.proname")->fetchAll(PDO::FETCH_COLUMN);
$ok($publicFunctions===['check_writer_identity','immutable'],'document existing PUBLIC helper EXECUTE, not a blanket no-PUBLIC-functions claim');
$ok((int)$admin->query("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='sites_preview' AND p.proname IN ('can_access','record_change','review_version') AND p.prosecdef AND EXISTS(SELECT 1 FROM unnest(p.proconfig) setting WHERE replace(setting,' ','')='search_path=pg_catalog,sites_preview')")->fetchColumn()===4,'security definer functions preserve fixed search path');
foreach($connections as $tenant=>$roles)foreach($roles as $profile=>$db) {
    $row=$db->query("SELECT NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND current_user=session_user AND NOT pg_has_role(current_user,'lab486_owner','MEMBER') AS safe FROM pg_roles WHERE rolname=current_user")->fetchColumn();
    $ok($row===true,'native unprivileged non-owner runtime');
    $ok($db->query("SELECT has_schema_privilege(current_user,'sites_preview','USAGE') AND NOT has_schema_privilege(current_user,'sites_preview','CREATE') AND NOT has_schema_privilege(current_user,'public','CREATE') AND NOT has_database_privilege(current_user,current_database(),'CREATE')")->fetchColumn()===true,'schema/database CREATE absent');
    foreach(['projects','versions','reviews','audit','principals'] as $table) {
        $r=$db->query("SELECT has_table_privilege(current_user,'sites_preview.$table','SELECT') AS can_read,has_table_privilege(current_user,'sites_preview.$table','INSERT') AS can_insert,has_table_privilege(current_user,'sites_preview.$table','UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') AS extra")->fetch(PDO::FETCH_ASSOC);
        $ok($r['can_read']===($table!=='principals')&&$r['can_insert']===($profile==='author'&&in_array($table,['projects','versions'],true))&&$r['extra']===false,'exact effective table privileges');
    }
    $ok($db->query("SELECT has_function_privilege(current_user,'sites_preview.review_version(uuid,uuid,integer,text,text)','EXECUTE')")->fetchColumn()===($profile==='reviewer'),'review entry point only reviewer');
    $ok($db->query("SELECT NOT has_sequence_privilege(current_user,'sites_preview.audit_id_seq','USAGE,SELECT,UPDATE')")->fetchColumn()===true,'audit sequence unavailable directly');
    $ok($db->query("SELECT has_function_privilege(current_user,'sites_preview.can_access(uuid,text)','EXECUTE') AND NOT has_function_privilege(current_user,'sites_preview.record_change()','EXECUTE')")->fetchColumn()===true,'only scoped access check exposed directly');
    $s=$db->prepare("SELECT sites_preview.can_access(?::uuid,'read') AS r,sites_preview.can_access(?::uuid,'edit') AS e,sites_preview.can_access(?::uuid,'review') AS v");$s->execute([$companies[$tenant],$companies[$tenant],$companies[$tenant]]);$caps=$s->fetch(PDO::FETCH_ASSOC);
    $ok($caps===['r'=>true,'e'=>$profile==='author','v'=>$profile==='reviewer'],'segregated principal capabilities');
}
$projects=['a'=>'11111111-1111-4111-8111-111111111111','b'=>'22222222-2222-4222-8222-222222222222'];
foreach($companies as $tenant=>$company) {
    $other=$tenant==='a'?'b':'a';$project=$projects[$tenant];
    $brief=new SiteBrief($tenant==='a'?'onepage':'landing_page','Synthetic company','Fixture headline','Laboratory content','Read more',['Example']);
    $workspaces[$tenant]['author']->createDemonstration($project,$company,$brief);
    $v=$workspaces[$tenant]['reader']->view($project,$company,1);
    $ok(hash('sha256',$v['html'])===$v['sha256'],'reader sees persisted own-company version');
    $workspaces[$tenant]['reviewer']->review($project,$company,1,$v['sha256'],'approved_internal');
    $db=$connections[$tenant]['reader'];
    $ok((int)$db->query('SELECT count(*) FROM sites_preview.reviews')->fetchColumn()===1,'reviewer writes through owner function under FORCE RLS');
    foreach($profiles as $profile=>$_) {
        $w=$workspaces[$tenant][$profile];
        $ok($w->view($project,$company,1)['sha256']===$v['sha256'],'each own-company profile can read');
        $denied(fn()=>$workspaces[$other][$profile]->view($project,$company,1),'other company read refused');
    }
    foreach(['author','reader'] as $profile)$denied(fn()=>$workspaces[$tenant][$profile]->review($project,$company,1,$v['sha256'],'approved_internal'),'non-reviewer routine review refused');
    foreach(['reviewer','reader'] as $profile)$denied(fn()=>$workspaces[$tenant][$profile]->revise($project,$company,1,$brief),'non-author routine revision refused');
    $denied(fn()=>$workspaces[$other]['author']->revise($project,$company,1,$brief),'other company revision refused');
    $denied(fn()=>$workspaces[$other]['reviewer']->review($project,$company,1,$v['sha256'],'approved_internal'),'other company review refused');
    $workspaces[$tenant]['author']->revise($project,$company,1,new SiteBrief($brief->productCode,'Synthetic company','Revised fixture','New laboratory content','Read more',['Example']));
    $ok((int)$workspaces[$tenant]['reader']->view($project,$company,2)['revision']===2,'author revises own project');
    $ok((int)$db->query('SELECT count(*) FROM sites_preview.audit')->fetchColumn()===4,'scoped audit includes create/version/review/revision');
    $ok((int)$db->query('SELECT count(*) FROM sites_preview.reviews WHERE revision=2')->fetchColumn()===0,'revision has no inherited internal review');
}
// A correctly provisioned fixture alone does not prove fail-closed behavior after
// grant drift. Temporarily introduce effective privileges on the disposable
// cluster and require rejection by the runtime guard before an actual revision.
// A generic SQL exception is NOT accepted as evidence that the guard rejected it.
$driftCases=0;
$driftRole='lab486_a_author';
$driftWorkspace=$workspaces['a']['author'];
$original=$driftWorkspace->view($projects['a'],$companies['a'],2);
$driftBrief=new SiteBrief('onepage','Synthetic company','Uncommitted drift fixture','Must never be written','Read more',['Example']);
$guardDenied=function(callable $operation,string $label)use($ok):void {
    try{$operation();}catch(RuntimeException $e){
        $ok($e->getMessage()==='Preview runtime denied','runtime guard: '.$label);return;
    }
    $ok(false,'runtime accepted unsafe privilege: '.$label);
};
$drift=function(string $grant,string $revoke,string $label)use($admin,$driftWorkspace,$projects,$companies,$driftBrief,$guardDenied,$original,$ok,&$driftCases):void {
    $admin->exec($grant);
    try {
        $guardDenied(fn()=>$driftWorkspace->revise($projects['a'],$companies['a'],2,$driftBrief),$label);
        $driftCases++;
    } finally {$admin->exec($revoke);}
    // The same authenticated PDO connection sees revocation immediately and
    // retains access to the unchanged committed version once safe again.
    $ok($driftWorkspace->view($projects['a'],$companies['a'],2)['sha256']===$original['sha256'],'safe access restored: '.$label);
};
foreach(['projects','versions','reviews','audit','principals'] as $table) {
    foreach(['UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER','MAINTAIN'] as $privilege) {
        $drift("GRANT $privilege ON sites_preview.$table TO $driftRole",
            "REVOKE $privilege ON sites_preview.$table FROM $driftRole","$table $privilege");
    }
}
foreach(['principals'=>['SELECT','INSERT'],'reviews'=>['INSERT'],'audit'=>['INSERT']] as $table=>$privileges) {
    foreach($privileges as $privilege)$drift("GRANT $privilege ON sites_preview.$table TO $driftRole",
        "REVOKE $privilege ON sites_preview.$table FROM $driftRole","direct $table $privilege");
}
foreach([
    ['versions','UPDATE','brief'],['projects','UPDATE','product'],
    ['reviews','INSERT','decision'],['audit','INSERT','event'],
    ['principals','SELECT','database_login'],['principals','INSERT','database_login'],
    ['versions','REFERENCES','project_id'],
] as [$table,$privilege,$column]) {
    $drift("GRANT $privilege ($column) ON sites_preview.$table TO $driftRole",
        "REVOKE $privilege ($column) ON sites_preview.$table FROM $driftRole","column $table.$column $privilege");
}
$drift("GRANT INSERT ON sites_preview.projects TO $driftRole WITH GRANT OPTION",
    "REVOKE GRANT OPTION FOR INSERT ON sites_preview.projects FROM $driftRole",'table grant delegation');
$drift("GRANT SELECT (html) ON sites_preview.versions TO $driftRole WITH GRANT OPTION",
    "REVOKE SELECT (html) ON sites_preview.versions FROM $driftRole",'column grant delegation');
$drift("GRANT USAGE ON SCHEMA sites_preview TO $driftRole WITH GRANT OPTION",
    "REVOKE GRANT OPTION FOR USAGE ON SCHEMA sites_preview FROM $driftRole",'schema grant delegation');
$drift("GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text) TO $driftRole WITH GRANT OPTION",
    "REVOKE GRANT OPTION FOR EXECUTE ON FUNCTION sites_preview.can_access(uuid,text) FROM $driftRole",'function grant delegation');
foreach(['USAGE','SELECT','UPDATE'] as $privilege) {
    $drift("GRANT $privilege ON SEQUENCE sites_preview.audit_id_seq TO $driftRole",
        "REVOKE $privilege ON SEQUENCE sites_preview.audit_id_seq FROM $driftRole","audit sequence $privilege");
}
$drift("GRANT EXECUTE ON FUNCTION sites_preview.record_change() TO $driftRole",
    "REVOKE EXECUTE ON FUNCTION sites_preview.record_change() FROM $driftRole",'direct audit function access');
foreach(['sites_preview','public'] as $schema) {
    $drift("GRANT CREATE ON SCHEMA $schema TO $driftRole",
        "REVOKE CREATE ON SCHEMA $schema FROM $driftRole","schema $schema CREATE");
}
$drift("GRANT CREATE ON DATABASE postgres TO $driftRole",
    "REVOKE CREATE ON DATABASE postgres FROM $driftRole",'database CREATE');
$drift('GRANT INSERT ON sites_preview.reviews TO PUBLIC',
    'REVOKE INSERT ON sites_preview.reviews FROM PUBLIC','PUBLIC review write');
$admin->exec('CREATE ROLE lab486_acl_delegate NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION');
$admin->exec('GRANT INSERT ON sites_preview.reviews TO lab486_acl_delegate');
$drift("GRANT lab486_acl_delegate TO $driftRole WITH INHERIT TRUE",
    "REVOKE lab486_acl_delegate FROM $driftRole",'inherited review write');
$drift("GRANT lab486_owner TO $driftRole WITH INHERIT FALSE",
    "REVOKE lab486_owner FROM $driftRole",'owner membership without inheritance');
foreach(['SUPERUSER'=>'NOSUPERUSER','BYPASSRLS'=>'NOBYPASSRLS','CREATEDB'=>'NOCREATEDB','CREATEROLE'=>'NOCREATEROLE','REPLICATION'=>'NOREPLICATION'] as $unsafe=>$safe) {
    $drift("ALTER ROLE $driftRole $unsafe","ALTER ROLE $driftRole $safe","role attribute $unsafe");
}
$drift('ALTER TABLE sites_preview.versions NO FORCE ROW LEVEL SECURITY',
    'ALTER TABLE sites_preview.versions FORCE ROW LEVEL SECURITY','FORCE RLS drift');
$drift('ALTER TABLE sites_preview.versions DISABLE ROW LEVEL SECURITY',
    'ALTER TABLE sites_preview.versions ENABLE ROW LEVEL SECURITY','RLS drift');
$admin->exec("GRANT lab486_a_reader TO $driftRole WITH INHERIT FALSE");
try {
    $connections['a']['author']->exec('SET ROLE lab486_a_reader');
    $guardDenied(fn()=>$driftWorkspace->view($projects['a'],$companies['a'],2),'changed current_user');
    $driftCases++;
} finally {
    $connections['a']['author']->exec('RESET ROLE');
    $admin->exec("REVOKE lab486_a_reader FROM $driftRole");
}
$ok($driftWorkspace->view($projects['a'],$companies['a'],2)['sha256']===$original['sha256'],'safe access restored after SET ROLE');
$ok((int)$admin->query('SELECT count(*) FROM sites_preview.versions')->fetchColumn()===4+$notesLegacyCounts['versions']&&
    (int)$admin->query('SELECT count(*) FROM sites_preview.audit')->fetchColumn()===8+$notesLegacyCounts['audit']&&
    (int)$admin->query('SELECT count(*) FROM sites_preview.reviews')->fetchColumn()===2+$notesLegacyCounts['reviews'],
    'all denied drift attempts preserve versions, reviews and audit');
foreach($connections as $tenant=>$roles)foreach($roles as $profile=>$db) {
    $ok((int)$workspaces[$tenant][$profile]->view($projects[$tenant],$companies[$tenant],2)['revision']===2,
        'all legitimate profiles remain available after grant restoration');
}
echo "ACL DRIFT OK: $driftCases unsafe runtime configurations refused before revision; grants restored, same connections reusable, committed history unchanged.\n";
// The editorial queue is scoped to each principal and to the exact newest
// version. Its reads must not create audit events, versions or reviews.
$historyDigest=fn()=>$admin->query("SELECT
 (SELECT md5(string_agg(row_to_json(v)::text,'' ORDER BY project_id,revision)) FROM sites_preview.versions v) AS versions,
 (SELECT md5(string_agg(row_to_json(r)::text,'' ORDER BY project_id,revision,reviewer)) FROM sites_preview.reviews r) AS reviews,
 (SELECT md5(string_agg(row_to_json(a)::text,'' ORDER BY id)) FROM sites_preview.audit a) AS audit")->fetch(PDO::FETCH_ASSOC);
$beforeOverview=$historyDigest();
$overviews=[];
foreach($companies as $tenant=>$company) {
    $queues=[];
    foreach($profiles as $profile=>$_) {
        $overviews[$tenant][$profile]=new PrivatePreviewOverview($connections[$tenant][$profile]);
        $queues[$profile]=$overviews[$tenant][$profile]->projects($company);
    }
    $v=$workspaces[$tenant]['reader']->view($projects[$tenant],$company,2);
    $ok($queues['author']===$queues['reader']&&$queues['reader']===$queues['reviewer']&&
        count($queues['reader'])===1&&$queues['reader'][0]['id']===$projects[$tenant]&&
        $queues['reader'][0]['latest_revision']===2&&$queues['reader'][0]['sha256']===$v['sha256']&&
        $queues['reader'][0]['editorial_status']==='awaiting_review',
        'overview exact latest revision, no inherited review, same own-company queue for all profiles: '.$tenant);
}
$ok($historyDigest()===$beforeOverview,'overview reads leave versions, reviews and audit unchanged');
$current=$workspaces['a']['reader']->view($projects['a'],$companies['a'],2);
$workspaces['a']['reviewer']->review($projects['a'],$companies['a'],2,$current['sha256'],'approved_internal');
$approved=$overviews['a']['reader']->projects($companies['a']);
$next=$workspaces['a']['author']->revise($projects['a'],$companies['a'],2,
    new SiteBrief('onepage','Synthetic company','Third queue fixture','Newest revision awaiting editorial review','Read more',['Example']));
$pending=$overviews['a']['reader']->projects($companies['a']);
$ok($approved[0]['editorial_status']==='approved_internal'&&$approved[0]['latest_revision']===2&&
    $approved[0]['sha256']===$current['sha256']&&$pending[0]['latest_revision']===3&&
    $pending[0]['sha256']===$next['sha256']&&$pending[0]['editorial_status']==='awaiting_review',
    'overview approval applies only to its exact revision and digest');
$workspaces['a']['reviewer']->review($projects['a'],$companies['a'],3,$next['sha256'],'approved_internal');
// A second independent reviewer disagrees on the same immutable version.
// These temporary grants are confined to the already verified disposable lab.
$admin->exec("GRANT EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text),sites_preview.review_version(uuid,uuid,integer,text,text,text) TO lab486_a_reader");
$admin->exec("UPDATE sites_preview.principals SET can_review=true WHERE database_login='lab486_a_reader'");
try {
    $workspaces['a']['reader']->review($projects['a'],$companies['a'],3,$next['sha256'],'changes_requested','Explique melhor o benefício principal neste exemplo fictício.');
    $mixed=$overviews['a']['author']->projects($companies['a']);
    $ok($mixed[0]['editorial_status']==='changes_requested'&&$mixed[0]['latest_revision']===3&&$mixed[0]['sha256']===$next['sha256'],
        'changes requested prevails over approval for the same current revision');
} finally {
    $admin->exec("UPDATE sites_preview.principals SET can_review=false WHERE database_login='lab486_a_reader'");
    $admin->exec("REVOKE EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text),sites_preview.review_version(uuid,uuid,integer,text,text,text) FROM lab486_a_reader");
}
$overviewDenied=function(callable $operation,string $label)use($ok):void {
    try{$operation();}catch(RuntimeException $e){
        $ok($e->getMessage()==='Private preview overview unavailable',$label);return;
    }
    $ok(false,$label);
};
$overviewDenied(fn()=>$overviews['a']['reader']->projects($companies['b']),'overview denies company from another tenant');
foreach(['revoked_at=now()'=>'revoked','expires_at=now()-interval \'1 minute\''=>'expired'] as $mutation=>$label) {
    $admin->exec("UPDATE sites_preview.principals SET $mutation WHERE database_login='lab486_a_reader'");
    try{$overviewDenied(fn()=>$overviews['a']['reader']->projects($companies['a']),'overview denies '.$label.' principal on a reused reader');}
    finally{$admin->exec("UPDATE sites_preview.principals SET revoked_at=NULL,expires_at=now()+interval '1 hour' WHERE database_login='lab486_a_reader'");}
}
echo "OVERVIEW OK: 8 tenant/current-revision/digest/editorial/readonly/revocation assertions; synthetic data only.\n";

// Product comparison uses the existing persisted versions and SQL principals.
// It never creates another authority, approval or an alternative HTML source.
$comparisonChecksBefore=$count;
$comparisonRefused=function(callable $operation,string $class,string $label)use($ok):void {
    try{$operation();}catch(Throwable $error){
        $expected=$class===InvalidArgumentException::class
            ?'Invalid private preview comparison':'Private preview comparison unavailable';
        $ok(get_class($error)===$class&&$error->getMessage()===$expected,$label.' exact sanitized refusal');return;
    }
    $ok(false,'comparison unexpectedly accepted: '.$label);
};
$comparisons=[];
foreach($companies as $tenant=>$company) {
    $results=[];
    foreach($profiles as $profile=>$_) {
        $comparisons[$tenant][$profile]=new PrivatePreviewComparison($connections[$tenant][$profile]);
        $results[$profile]=$comparisons[$tenant][$profile]->compare($company,$projects[$tenant],1,2);
    }
    $ok($results['reader']===$results['author']&&$results['reader']===$results['reviewer'],
        'all existing own-company profiles read the same immutable comparison: '.$tenant);
    $ok($results['reader']['from']['revision']===1&&$results['reader']['to']['revision']===2&&
        $results['reader']['changed_fields']===['headline','description']&&
        $results['reader']['from']['brief']['productCode']===($tenant==='a'?'onepage':'landing_page'),
        'comparison selects exact revisions and fields for both product formats: '.$tenant);
}
$comparisonProject='33333333-3333-4333-8333-333333333333';
$comparisonBriefOne=new SiteBrief('onepage','Marca fictícia','Primeira versão','Descrição original.',
    'Conheça o exemplo',['Primeiro benefício','Segundo benefício']);
$comparisonBriefTwo=new SiteBrief('onepage','Marca fictícia','Revisão "nova" <em>texto</em> 😀',
    "Descrição revisada com ação e atenção.\nSegunda linha.",'Confira os detalhes',
    ['Segundo benefício','Primeiro benefício']);
$comparisonBriefFour=new SiteBrief('onepage','Marca atual','Versão corrente','A edição permanece na head.',
    'Confira a versão atual',['Benefício atual']);
$workspaces['a']['author']->createDemonstration($comparisonProject,$companies['a'],$comparisonBriefOne);
$comparisonVersionTwo=$workspaces['a']['author']->revise($comparisonProject,$companies['a'],1,$comparisonBriefTwo);
$workspaces['a']['author']->revise($comparisonProject,$companies['a'],2,$comparisonBriefTwo);
$workspaces['a']['author']->revise($comparisonProject,$companies['a'],3,$comparisonBriefFour);
$comparisonState=fn()=>[$historyDigest(),$admin->query(
    "SELECT md5(string_agg(row_to_json(p)::text,'' ORDER BY id)) FROM sites_preview.projects p"
)->fetchColumn()];
$comparisonBefore=$comparisonState();
$historical=$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,2);
$ok(array_keys($historical)===['from','to','changed_fields']&&
    array_keys($historical['from'])===['revision','sha256','created_at','brief']&&
    array_keys($historical['to'])===['revision','sha256','created_at','brief'],
    'comparison returns only version metadata and structured text, never stored HTML or approval flags');
$ok($historical['from']['brief']===get_object_vars($comparisonBriefOne)&&
    $historical['to']['brief']===get_object_vars($comparisonBriefTwo)&&
    $historical['changed_fields']===['headline','description','callToAction','benefits'],
    'historical comparison preserves Unicode, literal markup and ordered benefits in canonical field order');
$ok($historical['to']['sha256']===$comparisonVersionTwo['sha256']&&
    $historical['to']['sha256']===$workspaces['a']['reader']->view($comparisonProject,$companies['a'],2)['sha256']&&
    $historical['from']['created_at']!==''&&$historical['to']['created_at']!=='',
    'comparison retains the hashes of full stored bytes including the demonstration marker');
$sameText=$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,2,3);
$ok($sameText['changed_fields']===[]&&$sameText['from']['revision']===2&&$sameText['to']['revision']===3,
    'distinct revisions with identical text remain distinct and have no invented changes');
$currentComparison=$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,4);
$ok($currentComparison['to']['brief']===get_object_vars($comparisonBriefFour)&&
    $comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,2)===$historical,
    'current head does not replace either selected historical version');
$readOnlyBefore=$connections['a']['reader']->query('SHOW default_transaction_read_only')->fetchColumn();
$connections['a']['reader']->exec('SET default_transaction_read_only=on');
try {
    $ok($connections['a']['reader']->query('SHOW transaction_read_only')->fetchColumn()==='on'&&
        $comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,2)===$historical,
        'comparison works through an actual read-only PostgreSQL session');
} finally {
    $connections['a']['reader']->exec($readOnlyBefore==='on'
        ?'SET default_transaction_read_only=on':'SET default_transaction_read_only=off');
}
foreach([[0,2],[2,2],[3,2],[1,2147483648]] as [$from,$to]) {
    $comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,$from,$to),
        InvalidArgumentException::class,'invalid revision range');
}
$comparisonRefused(fn()=>$comparisons['a']['reader']->compare('invalid',$comparisonProject,1,2),
    InvalidArgumentException::class,'invalid company selector');
$comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['a'],'../project',1,2),
    InvalidArgumentException::class,'invalid project selector');
$comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,99),
    OutOfBoundsException::class,'one requested version does not exist');
$comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['a'],$projects['b'],1,2),
    OutOfBoundsException::class,'another company project is not visible inside the authorized scope');
$comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['b'],$projects['b'],1,2),
    RuntimeException::class,'company scope is never supplied as authority');
$connections['a']['reader']->beginTransaction();
try {
    $comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,2),
        RuntimeException::class,'caller-owned transaction is refused');
} finally {$connections['a']['reader']->rollBack();}
foreach(['revoked_at=now()'=>'revoked','expires_at=now()-interval \'1 minute\''=>'expired'] as $mutation=>$label) {
    $admin->exec("UPDATE sites_preview.principals SET $mutation WHERE database_login='lab486_a_reader'");
    try {
        $comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,2),
            RuntimeException::class,'comparison denies '.$label.' principal on the existing connection');
    } finally {
        $admin->exec("UPDATE sites_preview.principals SET revoked_at=NULL,expires_at=now()+interval '1 hour' WHERE database_login='lab486_a_reader'");
    }
}
$admin->exec('GRANT UPDATE ON sites_preview.versions TO lab486_a_reader');
try {
    $comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,2),
        RuntimeException::class,'comparison refuses excessive runtime privileges');
} finally {$admin->exec('REVOKE UPDATE ON sites_preview.versions FROM lab486_a_reader');}
$ok($comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,2)===$historical,
    'legitimate access resumes with the same immutable content after fixture grant restoration');
$ok($comparisonState()===$comparisonBefore,'successful and denied comparisons leave projects, versions, reviews and audit unchanged');

// A valid DB digest alone does not establish that HTML or structured metadata
// obey the private preview dialect. Insert adversarial rows with the existing
// author capability; no constraints, triggers or permissions are weakened.
$comparisonHtml=$workspaces['a']['reader']->view($comparisonProject,$companies['a'],1)['html'];
$comparisonGoodBrief=get_object_vars($comparisonBriefOne);
$comparisonBadBrief=$comparisonGoodBrief;$comparisonBadBrief['productCode']='landing_page';
$comparisonObjectBenefits=$comparisonGoodBrief;$comparisonObjectBenefits['benefits']=['label'=>'Not a list'];
$comparisonBadRows=[
    [$comparisonGoodBrief,str_replace('</html>','<script>alert(1)</script></html>',$comparisonHtml)],
    [$comparisonGoodBrief,preg_replace('~(<aside role="note"[^>]*>.*?</aside>)~s','$1$1',$comparisonHtml,1)],
    [$comparisonGoodBrief,preg_replace('~<aside role="note"[^>]*>.*?</aside>~s','',$comparisonHtml,1)],
    [$comparisonGoodBrief,str_replace('<body data-site-product="onepage">','<body data-site-product="landing-page">',$comparisonHtml)],
    [$comparisonBadBrief,$comparisonHtml],
    [$comparisonObjectBenefits,$comparisonHtml],
];
$insertComparison=$connections['a']['author']->prepare(
    'INSERT INTO sites_preview.versions(project_id,company_id,revision,brief,html,sha256) VALUES(?::uuid,?::uuid,?,?::jsonb,?,?)'
);
foreach($comparisonBadRows as $index=>[$badBrief,$badHtml]) {
    if(!is_string($badHtml))throw new RuntimeException('Invalid adversarial fixture');
    $badRevision=5+$index;
    $insertComparison->execute([$comparisonProject,$companies['a'],$badRevision,
        json_encode($badBrief,JSON_THROW_ON_ERROR),$badHtml,hash('sha256',$badHtml)]);
    $beforeBadRead=$comparisonState();
    $comparisonRefused(fn()=>$comparisons['a']['reader']->compare($companies['a'],$comparisonProject,1,$badRevision),
        RuntimeException::class,'digest-valid unsupported content '.$index);
    $ok($comparisonState()===$beforeBadRead,'rejected content comparison appends or changes nothing '.$index);
}
echo 'COMPARISON OK: '.($count-$comparisonChecksBefore).
    " real SQL assertions for exact historical versions, text, Unicode, isolation, runtime, integrity and readonly behavior.\n";
require __DIR__.'/fixtures/preview-feedback/contract.php';
require __DIR__.'/fixtures/preview-search/contract.php';
require __DIR__.'/fixtures/preview-brief/contract.php';
require __DIR__.'/fixtures/preview-inert-text/contract.php';
echo "OK: $count synthetic ownership/ACL/RLS/profile assertions; real existing migration, NOLOGIN/NOBYPASSRLS owner, two companies. No operational acceptance or backup evidence.\n";
