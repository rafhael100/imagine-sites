<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
 if(!app()->environment('staging')||config('app.debug')!==false||getenv('DB_USERNAME')!=='sites_runtime'||getenv('POSTGRES_PASSWORD')!==false||getenv('SITES_OWNER_PASSWORD')!==false||is_file('/run/secrets/owner_password')||is_file('/run/secrets/migration_owner'))throw new RuntimeException();
 if(preg_match('/^\S+\s+00000000\s+/m',file_get_contents('/proc/net/route')))throw new RuntimeException();
 $role=DB::selectOne('SELECT rolsuper,rolbypassrls,rolcreaterole,rolcreatedb FROM pg_roles WHERE rolname=current_user');
 foreach((array)$role as $v)if($v)throw new RuntimeException();
 $flags=DB::selectOne("SELECT bool_and(c.relrowsecurity AND c.relforcerowsecurity) AS safe FROM pg_class c JOIN pg_namespace n ON c.relnamespace=n.oid WHERE n.nspname='sites_core' AND c.relkind='r'");
 if($flags->safe!==true)throw new RuntimeException();
 $tests=["SELECT * FROM sites_core.read_my_site_projects()","SELECT * FROM sites_core.site_projects","CREATE TABLE public.unauthorized_runtime_write(id int)"];
 foreach($tests as $sql){$denied=false;try{DB::select($sql);}catch(\Illuminate\Database\QueryException $e){$denied=$e->getCode()==='42501';}if(!$denied)throw new RuntimeException();}
 echo "PASS: staging, isolated runtime, mounted secrets, no default IPv4 egress, nonprivileged SQL, FORCE RLS and default-deny reads/DDL.\n";
}catch(\Throwable){fwrite(STDERR,"Runtime isolation verification failed; no private context emitted.\n");exit(1);}
