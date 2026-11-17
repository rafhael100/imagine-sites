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
use Imagine\Sites\Production\{PrivatePreviewWorkspace,SiteBrief};
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
$owner=$admin->query("SELECT NOT rolcanlogin AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AS safe FROM pg_roles WHERE rolname='lab486_owner'")->fetchColumn();
$ok($owner===true,'NOLOGIN non-bypass owner');
$ok((int)$admin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='sites_preview' AND c.relkind IN ('r','S') AND c.relowner='lab486_owner'::regrole")->fetchColumn()===6,'all five tables and audit sequence owned by synthetic owner');
$ok((int)$admin->query("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='sites_preview' AND p.proowner='lab486_owner'::regrole")->fetchColumn()===5,'all five functions have non-bypass owner');
$ok($admin->query("SELECT nspowner='lab486_owner'::regrole FROM pg_namespace WHERE nspname='sites_preview'")->fetchColumn()===true,'schema owner');
$ok((int)$admin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='sites_preview' AND c.relname IN ('projects','versions','reviews','audit') AND c.relrowsecurity AND c.relforcerowsecurity")->fetchColumn()===4,'FORCE RLS on all four scoped tables');
// PUBLIC ACL inspection only: do not alter migration grants or invoke helper functions.
$ok((int)$admin->query("SELECT count(*) FROM pg_namespace n CROSS JOIN LATERAL aclexplode(coalesce(n.nspacl,acldefault('n',n.nspowner))) a WHERE n.nspname='sites_preview' AND a.grantee=0")->fetchColumn()===0,'no PUBLIC schema privileges');
$ok((int)$admin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace CROSS JOIN LATERAL aclexplode(coalesce(c.relacl,acldefault(CASE WHEN c.relkind='S' THEN 'S'::\"char\" ELSE 'r'::\"char\" END,c.relowner))) a WHERE n.nspname='sites_preview' AND c.relkind IN ('r','S') AND a.grantee=0")->fetchColumn()===0,'no PUBLIC table/sequence privileges');
$publicFunctions=$admin->query("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE n.nspname='sites_preview' AND a.grantee=0 AND a.privilege_type='EXECUTE' ORDER BY p.proname")->fetchAll(PDO::FETCH_COLUMN);
$ok($publicFunctions===['check_writer_identity','immutable'],'document existing PUBLIC helper EXECUTE, not a blanket no-PUBLIC-functions claim');
$ok((int)$admin->query("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='sites_preview' AND p.proname IN ('can_access','record_change','review_version') AND p.prosecdef AND EXISTS(SELECT 1 FROM unnest(p.proconfig) setting WHERE replace(setting,' ','')='search_path=pg_catalog,sites_preview')")->fetchColumn()===3,'security definer functions preserve fixed search path');
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
echo "OK: $count synthetic ownership/ACL/RLS/profile assertions; real existing migration, NOLOGIN/NOBYPASSRLS owner, two companies. No operational acceptance or backup evidence.\n";
