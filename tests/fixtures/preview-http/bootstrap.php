<?php
declare(strict_types=1);

// Disposable CI database only. Real provisioning code is used below; all LOGINs,
// passwords, company bindings and certificates here remain synthetic fixtures.
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Imagine\Sites\Production\PrivatePreviewProvisioning;
use Imagine\Sites\Production\PrivatePreviewWorkspace;

$stage = 'entry';
$handler = static function (Throwable $e) use (&$stage): never {
    $reason = preg_match('/\APROVISION_DENIED:[a-z_]+\z/D', $e->getMessage()) ? $e->getMessage() : get_class($e);
    fwrite(STDERR, "PREVIEW_HTTP_BOOTSTRAP_DENIED stage=$stage reason=$reason\n"); exit(2);
};
set_exception_handler($handler);
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SITES_PREVIEW_HTTP_CI') !== '1') throw new RuntimeException('CI scope denied');
$root = '/opt/imagine/apps/sites';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler($handler);
$password = trim(file_get_contents('/run/ci/db-password'));
$expected = ['driver'=>'pgsql','host'=>'db','port'=>'5432','database'=>'sites_preview_http_ci',
    'username'=>'postgres','password'=>$password,'charset'=>'utf8','prefix'=>'','search_path'=>'public',
    'sslmode'=>'verify-full','sslrootcert'=>'/run/ci/ca.pem','url'=>null];
config()->set('database.default','pgsql'); config()->set('database.connections.pgsql',$expected);
DB::purge('pgsql'); $connection = DB::connection('pgsql'); $db = $connection->getPdo();
foreach ($expected as $key=>$value) if ($connection->getConfig($key)!==$value) throw new RuntimeException('Effective configuration mismatch');
$identity=$db->query("SELECT current_database() AS database,session_user::text AS login,current_user::text AS actor,
    (SELECT oid::int FROM pg_database WHERE datname=current_database()) AS database_oid,
    host(inet_server_addr()) AS server_address,inet_server_port() AS port,
    (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier,
    current_setting('server_version_num')::int AS version,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls")->fetch(PDO::FETCH_ASSOC);
if ($identity['database']!=='sites_preview_http_ci'||$identity['login']!=='postgres'||$identity['actor']!=='postgres'||
    $identity['system_identifier']!==getenv('PREVIEW_EXPECTED_CLUSTER')||(int)$identity['port']!==5432||
    $identity['version']<180000||$identity['version']>=190000||$identity['tls']!==true||
    $db->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()!==null||
    (int)$db->query("SELECT count(*) FROM pg_roles WHERE rolname LIKE 'sites_preview_%'")->fetchColumn()!==0) throw new RuntimeException('Fresh disposable identity required');
$stage='synthetic-existing-logins';
$material=json_decode(file_get_contents('/run/ci/preview_principals'),true,12,JSON_THROW_ON_ERROR)['principals'];
$material['editor_second']=['sql_login'=>'sites_preview_editor_second','sql_password'=>bin2hex(random_bytes(32)),
    'company_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','expires_at'=>time()+3600];
$previewCompanies=['a'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','b'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
$manifest=['schema'=>'SITES_PREVIEW_PROVISION_V1','contract_sha256'=>PrivatePreviewProvisioning::contractSha(),
    'target'=>['database'=>'sites_preview_http_ci','bootstrap_login'=>'postgres','host'=>'db','port'=>5432,
        'server_address'=>$identity['server_address'],'database_oid'=>(int)$identity['database_oid'],'system_identifier'=>$identity['system_identifier']],
    'principals'=>[]];
foreach (['author','editor','other','editor_second'] as $name) {
    $p=$material[$name]; $role=$p['sql_login'];
    if (!preg_match('/\Asites_preview_[a-z0-9_]{1,48}\z/D',$role)||strlen($p['sql_password'])<32) throw new RuntimeException('Synthetic material invalid');
    $db->exec('CREATE ROLE "'.$role.'" LOGIN PASSWORD '.$db->quote($p['sql_password']).' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION');
    $manifest['principals'][]=['login'=>$role,'company_id'=>$p['company_id'],
        'profile'=>in_array($name,['editor','editor_second'],true)?'reviewer':'author','expires_at'=>$p['expires_at']];
}
$repository=new DatabaseMigrationRepository(app('db'),'migrations'); $repository->setSource('pgsql'); $repository->createRepository();
$db->exec("CREATE TABLE public.provision_fixture_sentinel(id integer PRIMARY KEY,value text NOT NULL); INSERT INTO public.provision_fixture_sentinel VALUES(1,'unrelated synthetic state')");
$service=new PrivatePreviewProvisioning($connection); $checks=0;
$ok=static function(bool $condition,string $label)use(&$checks):void { if(!$condition)throw new RuntimeException('Fixture assertion '.$label); $checks++; };
$denied=static function(callable $operation,string $expectedReason)use($ok):void {
    try{$operation();}catch(RuntimeException $e){$ok($e->getMessage()==='PROVISION_DENIED:'.$expectedReason,$expectedReason);return;}
    $ok(false,'expected denial '.$expectedReason);
};
$absent=static fn():bool=>$db->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()===null&&
    (int)$db->query("SELECT count(*) FROM pg_roles WHERE rolname='sites_preview_owner'")->fetchColumn()===0&&
    (int)$db->query("SELECT count(*) FROM public.migrations")->fetchColumn()===0;
$stage='plan-refusals';
$plan=$service->plan($manifest); $ok($plan['status']==='ready'&&$absent(),'readonly plan');
$denied(fn()=>$service->check($manifest),'not_applied');
$denied(fn()=>$service->apply($manifest,$plan['plan_sha256']),'explicit_apply_required');
$denied(fn()=>$service->apply($manifest,str_repeat('0',64),true),'stale_plan');
$wrong=$manifest; $wrong['target']['system_identifier']=$identity['system_identifier']==='1'?'2':'1';
$denied(fn()=>$service->plan($wrong),'sql_identity'); $ok($absent(),'wrong cluster unchanged');
$db->exec('ALTER ROLE sites_preview_author NOINHERIT');
try{$denied(fn()=>$service->apply($manifest,$plan['plan_sha256'],true),'stale_plan');}
finally{$db->exec('ALTER ROLE sites_preview_author INHERIT');}
$ok($absent(),'stale privileges unchanged');
$stage='atomic-rollback';
$originalDispatcher=$connection->getEventDispatcher(); $faults=new Dispatcher(); $injected=false;
$faults->listen(QueryExecuted::class,static function(QueryExecuted $event)use(&$injected):void {
    if(str_starts_with($event->sql,'GRANT USAGE ON SCHEMA sites_preview')) {$injected=true;throw new RuntimeException('Synthetic post-migration failure');}
});
$connection->setEventDispatcher($faults);
try{$denied(fn()=>$service->apply($manifest,$plan['plan_sha256'],true),'database_or_manifest_operation');}
finally{$connection->setEventDispatcher($originalDispatcher);}
$ok($injected&&$absent()&&$db->query('SELECT current_user=session_user')->fetchColumn()===true,'DDL ledger role and bindings rolled back');
$stage='apply-check-noop';
$result=$service->apply($manifest,$service->plan($manifest)['plan_sha256'],true);
$ok($result['status']==='applied'&&$result['changed']===true,'real candidate applied');
$ok($service->check($manifest)['status']==='verified','real candidate check');
$ready=$service->plan($manifest); $noop=$service->apply($manifest,$ready['plan_sha256'],true);
$ok($noop['status']==='already_applied'&&$noop['changed']===false,'idempotent no-op');
$ok((int)$db->query('SELECT count(*) FROM public.migrations')->fetchColumn()===1&&
    (int)$db->query('SELECT count(*) FROM sites_preview.audit')->fetchColumn()===0&&
    $db->query('SELECT value FROM public.provision_fixture_sentinel WHERE id=1')->fetchColumn()==='unrelated synthetic state',
    'ledger singular no data writes unrelated state preserved');
$stage='managed-drift-refusals';
$db->exec('GRANT INSERT ON sites_preview.reviews TO sites_preview_author');
try{$denied(fn()=>$service->check($manifest),'table_acl');$denied(fn()=>$service->apply($manifest,$ready['plan_sha256'],true),'table_acl');}
finally{$db->exec('REVOKE INSERT ON sites_preview.reviews FROM sites_preview_author');}
$db->exec('ALTER ROLE sites_preview_owner LOGIN');
try{$denied(fn()=>$service->check($manifest),'owner_or_migration_drift');}
finally{$db->exec('ALTER ROLE sites_preview_owner NOLOGIN');}
$db->exec("UPDATE sites_preview.principals SET revoked_at=now() WHERE database_login='sites_preview_editor'");
try{$denied(fn()=>$service->check($manifest),'binding_drift');}
finally{$db->exec("UPDATE sites_preview.principals SET revoked_at=NULL WHERE database_login='sites_preview_editor'");}
$db->exec("ALTER FUNCTION sites_preview.can_access(uuid,text) SET search_path=public");
try{$denied(fn()=>$service->check($manifest),'structure_drift');}
finally{$db->exec("ALTER FUNCTION sites_preview.can_access(uuid,text) SET search_path=pg_catalog,sites_preview");}
$ok($service->check($manifest)['status']==='verified','restored candidate verifies');
$previewConnections=[];
foreach (['author','editor','other','editor_second'] as $name) {
    $p=$material[$name];
    $previewConnections[$name]=new PDO('pgsql:host=db;port=5432;dbname=sites_preview_http_ci;sslmode=verify-full;sslrootcert=/run/ci/ca.pem',
        $p['sql_login'],$p['sql_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    (new PrivatePreviewWorkspace($previewConnections[$name]))->assertSafeRuntime();
    $ok(true,'minimal runtime '.$name);
}
echo "PROVISIONING CI PASS: $checks checks; readonly plan, exact target, reviewed hash, atomic migration/ledger/owner/grants, no-op, drift refusal, owner NOLOGIN and existing synthetic logins.\n";
$stage='navigation-fixtures';
require __DIR__.'/seed-navigation.php';
$service->check($manifest);
echo "OK: CI PostgreSQL verified, real provisioner prepared owned schema and synthetic scoped roles; operational approval remains absent\n";
