<?php
declare(strict_types=1);
/** Explicit owner-only CLI operation in a NEW Sites DB. Not loaded by web. */
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Support\MountedSecret;
if(PHP_SAPI!=='cli'||!app()->environment('staging')||getenv('SITES_NEW_DATABASE_PROVISION')!=='new-isolated-sites-database'||config('database.connections.pgsql.database')!=='sites_staging'||config('database.connections.pgsql.username')!=='sites_bootstrap')throw new RuntimeException('Isolated provisioning scope denied');
$password=MountedSecret::read('/run/secrets/runtime_password');
try { DB::transaction(function()use($password){
 DB::statement("SET LOCAL log_min_error_statement='panic'");
 if(DB::selectOne("SELECT 1 FROM pg_roles WHERE rolname='sites_runtime'"))throw new RuntimeException('Runtime role already exists; owner review required');
 $db=DB::connection()->getPdo();
 DB::unprepared('CREATE ROLE sites_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD '.$db->quote($password));
 DB::unprepared('REVOKE ALL ON DATABASE sites_staging FROM PUBLIC; GRANT CONNECT ON DATABASE sites_staging TO sites_runtime; REVOKE CREATE ON SCHEMA public FROM PUBLIC; GRANT USAGE ON SCHEMA sites_core TO sites_runtime; GRANT EXECUTE ON FUNCTION sites_core.read_my_site_projects() TO sites_runtime');
}); } catch(\Throwable) {fwrite(STDERR,"Sites isolated provisioning blocked; inspect role/schema prerequisites privately.\n");exit(1);}
echo "Sites isolated runtime provisioned with read-function-only access; tenant bindings and operational admission remain denied.\n";
