<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// This helper only authenticates the disposable CI target. Seeding and upgrade
// writes are explicit in the two entrypoints, after all identity checks below.
$upgradeStage??='entry';
$upgradeHandler=static function(Throwable $error)use(&$upgradeStage):never {
    $message=$error->getMessage();
    $reason=preg_match('/\A(?:PROVISION_DENIED|UPGRADE_FIXTURE_DENIED|UPGRADE_FIXTURE_ASSERT):[a-z0-9_]+\z/D',$message)
        ?$message:get_class($error);
    fwrite(STDERR,'PREVIEW_UPGRADE_CI_DENIED stage='.$upgradeStage.' reason='.$reason."\n");exit(2);
};
set_exception_handler($upgradeHandler);
function upgrade_fixture_deny(string $reason):never {throw new RuntimeException('UPGRADE_FIXTURE_DENIED:'.$reason);}
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' || getenv('SITES_PREVIEW_HTTP_CI')!=='1' ||
    getenv('SITES_PREVIEW_UPGRADE_CI')!=='1' || !function_exists('posix_geteuid') || posix_geteuid()!==0) {
    upgrade_fixture_deny('ci_scope');
}
$upgradeRoot='/opt/imagine';
$upgradeLegacyRoot='/tmp/legacy-v1';
$upgradeLegacyHashes=[
    'src/Production/PrivatePreviewProvisioning.php'=>'db2461b0f1326c59755ca4c86c10fa5720ad35b6d9eaf3937fdb4c200d9c5b3a',
    'src/Production/PrivatePreviewWorkspace.php'=>'7129dd89bbbde5121d9c97f45046d77e86d8145c760f4888f01e21878025aff2',
    'contracts/private-preview/001-workspace.sql'=>'5ecfa4e626c622b387047d51e3a672b733d5830283b4e34d847f7469df5279eb',
    'apps/sites/database/migrations/2026_11_16_000004_add_private_preview_workspace.php'=>'d6309a9a72353c02590392ab65e00616b3fca7acafb44315f0a7957aafddc091',
];
// These exact source bytes are from ad649b3f10bdee93837e9443efc9bf4b1edeb85c.
// Do not emulate V1 by changing the marker of a V2 installation.
foreach ($upgradeLegacyHashes as $path=>$sha) {
    if (!is_file($upgradeLegacyRoot.'/'.$path) ||
        !hash_equals($sha,hash_file('sha256',$upgradeLegacyRoot.'/'.$path))) upgrade_fixture_deny('legacy_source');
}
if (hash_file('sha256',$upgradeRoot.'/contracts/private-preview/001-workspace.sql')!==
    $upgradeLegacyHashes['contracts/private-preview/001-workspace.sql']) upgrade_fixture_deny('base_contract_changed');
if (($upgradeLoadLegacy??false)===true) {
    require $upgradeLegacyRoot.'/src/Production/PrivatePreviewProvisioning.php';
    require $upgradeLegacyRoot.'/src/Production/PrivatePreviewWorkspace.php';
}
require $upgradeRoot.'/apps/sites/vendor/autoload.php';
$app=require $upgradeRoot.'/apps/sites/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler($upgradeHandler);

$upgradePasswordFile='/run/ci/db-password';
if (!is_file($upgradePasswordFile) || is_link($upgradePasswordFile) || !is_readable('/run/ci/ca.pem')) {
    upgrade_fixture_deny('synthetic_material');
}
$upgradePassword=trim(file_get_contents($upgradePasswordFile));
if (strlen($upgradePassword)<32) upgrade_fixture_deny('synthetic_material');
$upgradeExpected=['driver'=>'pgsql','host'=>'db','port'=>'5432','database'=>'sites_preview_http_ci',
    'username'=>'postgres','password'=>$upgradePassword,'charset'=>'utf8','prefix'=>'','search_path'=>'public',
    'sslmode'=>'verify-full','sslrootcert'=>'/run/ci/ca.pem','url'=>null];
config()->set('database.default','pgsql');config()->set('database.connections.pgsql',$upgradeExpected);
DB::purge('pgsql');$connection=DB::connection('pgsql');$db=$connection->getPdo();
foreach ($upgradeExpected as $key=>$value) if ($connection->getConfig($key)!==$value) upgrade_fixture_deny('effective_config');
$identity=$db->query("SELECT current_database() AS database,session_user::text AS login,current_user::text AS actor,
    (SELECT oid::int FROM pg_database WHERE datname=current_database()) AS database_oid,
    host(inet_server_addr()) AS server_address,inet_server_port() AS port,
    (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier,
    current_setting('server_version_num')::int AS version,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls,
    (SELECT rolsuper FROM pg_roles WHERE rolname=current_user) AS administrator")->fetch(PDO::FETCH_ASSOC);
if (!app()->environment('staging') || config('app.debug')!==false ||
    $identity['database']!=='sites_preview_http_ci' || $identity['login']!=='postgres' || $identity['actor']!=='postgres' ||
    $identity['system_identifier']!==getenv('PREVIEW_EXPECTED_CLUSTER') || (int)$identity['port']!==5432 ||
    $identity['version']<180000 || $identity['version']>=190000 || $identity['tls']!==true ||
    $identity['administrator']!==true || filter_var($identity['server_address'],FILTER_VALIDATE_IP)===false) {
    upgrade_fixture_deny('disposable_identity');
}
$upgradeTarget=['database'=>'sites_preview_http_ci','bootstrap_login'=>'postgres','host'=>'db','port'=>5432,
    'server_address'=>$identity['server_address'],'database_oid'=>(int)$identity['database_oid'],
    'system_identifier'=>$identity['system_identifier']];
$upgradeDirectory='/run/upgrade-fixture';$upgradeManifestPath='/run/secrets/preview_upgrade';
if (!is_dir($upgradeDirectory) || is_link($upgradeDirectory) || realpath($upgradeDirectory)!==$upgradeDirectory ||
    (fileperms($upgradeDirectory)&0777)!==0700 || fileowner($upgradeDirectory)!==0 ||
    !is_file($upgradeManifestPath) || is_link($upgradeManifestPath) || realpath($upgradeManifestPath)!==$upgradeManifestPath ||
    (fileperms($upgradeManifestPath)&0777)!==0600 || fileowner($upgradeManifestPath)!==0) {
    upgrade_fixture_deny('fixture_custody');
}
$material=json_decode(file_get_contents('/run/ci/preview_principals'),true,12,JSON_THROW_ON_ERROR);
if (($material['schema']??null)!=='SITES_DEMO_PRINCIPALS_V1' || !is_array($material['principals']??null)) {
    upgrade_fixture_deny('synthetic_principals');
}
$material=$material['principals'];
foreach (['author','editor','other'] as $name) {
    $p=$material[$name]??null;
    if (!is_array($p) || ($p['sql_login']??null)!=='sites_preview_'.$name ||
        !is_string($p['sql_password']??null) || strlen($p['sql_password'])<32 ||
        ($p['company_id']??null)!==($name==='other'?'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb':'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa') ||
        !is_int($p['expires_at']??null) || $p['expires_at']<=time() || ($p['revoked']??null)!==false) {
        upgrade_fixture_deny('synthetic_principals');
    }
}
$checks=0;
$ok=static function(bool $condition,string $label)use(&$checks):void {
    if (!$condition) throw new RuntimeException('UPGRADE_FIXTURE_ASSERT:'.$label);
    $checks++;
};
$denied=static function(callable $operation,string $reason)use($ok):void {
    try {$operation();} catch (RuntimeException $error) {
        $ok($error->getMessage()==='PROVISION_DENIED:'.$reason,'expected_'.$reason);return;
    }
    $ok(false,'missing_denial_'.$reason);
};
$runtimeConnection=static function(string $name)use($material):PDO {
    $p=$material[$name];
    return new PDO('pgsql:host=db;port=5432;dbname=sites_preview_http_ci;sslmode=verify-full;sslrootcert=/run/ci/ca.pem',
        $p['sql_login'],$p['sql_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
};
$writeManifest=static function(array $manifest)use($upgradeManifestPath):void {
    clearstatcache(true,$upgradeManifestPath);
    if (!is_file($upgradeManifestPath) || is_link($upgradeManifestPath) || fileowner($upgradeManifestPath)!==0 ||
        (fileperms($upgradeManifestPath)&0777)!==0600) upgrade_fixture_deny('manifest_fixture_changed');
    $json=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    if (file_put_contents($upgradeManifestPath,$json)!==strlen($json)) upgrade_fixture_deny('manifest_fixture_write');
};

/** Hash old fields independently of the additive nullable note column. */
function upgrade_fixture_history(PDO $db):array
{
    return $db->query(<<<'SQL'
SELECT
 (SELECT count(*) FROM sites_preview.projects) AS projects_count,
 (SELECT count(*) FROM sites_preview.versions) AS versions_count,
 (SELECT count(*) FROM sites_preview.reviews) AS reviews_count,
 (SELECT count(*) FROM sites_preview.audit) AS audit_count,
 (SELECT md5(string_agg(row_to_json(p)::text,'' ORDER BY id)) FROM sites_preview.projects p) AS projects,
 (SELECT md5(string_agg(row_to_json(v)::text,'' ORDER BY project_id,revision)) FROM sites_preview.versions v) AS versions,
 (SELECT md5(string_agg((to_jsonb(r)-'note')::text,'' ORDER BY project_id,revision,reviewer)) FROM sites_preview.reviews r) AS reviews,
 (SELECT md5(string_agg(row_to_json(a)::text,'' ORDER BY id)) FROM sites_preview.audit a) AS audit,
 (SELECT md5(string_agg(row_to_json(p)::text,'' ORDER BY database_login)) FROM sites_preview.principals p) AS principals
SQL)->fetch(PDO::FETCH_ASSOC);
}

/** Preserve actual catalog/ledger/history state, not just a marker string. */
function upgrade_fixture_snapshot(PDO $db):array
{
    $queries=[
        "SELECT oid::text,nspowner::regrole::text AS owner,nspacl::text AS acl,obj_description(oid,'pg_namespace') AS marker FROM pg_namespace WHERE nspname='sites_preview'",
        "SELECT oid::text,relname,relkind,relowner::regrole::text AS owner,relacl::text,relrowsecurity,relforcerowsecurity FROM pg_class WHERE relnamespace='sites_preview'::regnamespace ORDER BY relname",
        "SELECT p.oid::text,p.oid::regprocedure::text AS signature,p.proowner::regrole::text AS owner,p.proacl::text,p.prosrc,p.proconfig::text,p.prosecdef FROM pg_proc p WHERE pronamespace='sites_preview'::regnamespace ORDER BY p.oid",
        "SELECT c.relname,a.attname,a.attnum,format_type(a.atttypid,a.atttypmod) AS type,a.attnotnull,a.attidentity,a.attacl::text,pg_get_expr(d.adbin,d.adrelid) AS default_value FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum WHERE c.relnamespace='sites_preview'::regnamespace AND a.attnum>0 AND NOT a.attisdropped ORDER BY c.relname,a.attnum",
        "SELECT conname,conrelid::regclass::text AS relation,pg_get_constraintdef(oid,true) AS definition FROM pg_constraint WHERE connamespace='sites_preview'::regnamespace ORDER BY conname,conrelid::regclass::text",
        "SELECT t.tgname,c.relname,t.tgenabled,pg_get_triggerdef(t.oid,true) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='sites_preview'::regnamespace AND NOT t.tgisinternal ORDER BY c.relname,t.tgname",
        "SELECT p.polname,c.relname,p.polcmd,p.polpermissive,p.polroles::text,pg_get_expr(p.polqual,p.polrelid) AS qual,pg_get_expr(p.polwithcheck,p.polrelid) AS checked FROM pg_policy p JOIN pg_class c ON c.oid=p.polrelid WHERE c.relnamespace='sites_preview'::regnamespace ORDER BY c.relname,p.polname",
        "SELECT rolname,rolcanlogin,rolinherit,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolreplication,shobj_description(oid,'pg_authid') AS marker FROM pg_roles WHERE rolname LIKE 'sites_preview_%' ORDER BY rolname",
        'SELECT id,migration,batch FROM public.migrations ORDER BY id',
        'SELECT id,value FROM public.provision_upgrade_sentinel ORDER BY id',
    ];
    $result=[];foreach ($queries as $sql) $result[]=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $result[]=upgrade_fixture_history($db);
    // PostgreSQL sequence increments are not transactional. Their values are
    // deliberately excluded; fixture failure recovery never rewinds them.
    return $result;
}
