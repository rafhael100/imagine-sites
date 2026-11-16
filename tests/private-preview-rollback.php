<?php
if(getenv('CI')!=='true'||getenv('SITES_PRIVATE_PREVIEW_PG_CI')!=='1')exit(1);
require __DIR__.'/../apps/sites/vendor/autoload.php';
$app=require __DIR__.'/../apps/sites/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'PREVIEW_ROLLBACK_VERIFIER_FAILED '.get_class($e)."\n");exit(1);});
config()->set('database.default','pgsql');config()->set('database.connections.pgsql',['driver'=>'pgsql','host'=>'127.0.0.1','port'=>5432,'database'=>'sites_preview_ci','username'=>'postgres','password'=>getenv('PGPASSWORD'),'charset'=>'utf8','prefix'=>'','sslmode'=>'disable']);
use Illuminate\Support\Facades\DB;
$p=DB::selectOne('SELECT current_database() AS db,session_user AS login,host(inet_server_addr()) AS addr,inet_server_port() AS port,(SELECT system_identifier::text FROM pg_control_system()) AS cluster');
if($p->db!=='sites_preview_ci'||$p->login!=='postgres'||$p->addr!==getenv('SITES_PREVIEW_EXPECTED_DB_ADDR')||(int)$p->port!==5432||$p->cluster!==getenv('SITES_PREVIEW_EXPECTED_CLUSTER'))throw new RuntimeException('Disposable SQL destination mismatch');
$m=require __DIR__.'/../apps/sites/database/migrations/2026_11_16_000004_add_private_preview_workspace.php';
$before=DB::table('sites_preview.versions')->count();if($before!==4)throw new RuntimeException('Expected own disposable fixture');
$denied=false;try{$m->down();}catch(RuntimeException){$denied=true;}if(!$denied||DB::table('sites_preview.versions')->count()!==$before)throw new RuntimeException('Populated history rollback was not preserved');
DB::statement('SET ROLE preview_author');
try{$denied=false;try{$m->down();}catch(RuntimeException){$denied=true;}if(!$denied)throw new LogicException('Restricted role claimed complete row visibility');}
finally{DB::statement('RESET ROLE');}
if(DB::table('sites_preview.versions')->count()!==$before)throw new RuntimeException('History changed');
echo "OK: populated rollback and RLS-restricted inspection denied; all four immutable versions preserved\n";
