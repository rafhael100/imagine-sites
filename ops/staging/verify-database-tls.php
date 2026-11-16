<?php
declare(strict_types=1);
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
 $c=config('database.connections.pgsql');
 if(!app()->environment('staging')||$c['sslmode']!=='verify-full'||$c['host']!=='db'||$c['username']!=='sites_runtime'||!is_readable($c['sslrootcert']))throw new RuntimeException();
 $ssl=DB::selectOne('SELECT ssl,version FROM pg_stat_ssl WHERE pid=pg_backend_pid()');
 if($ssl->ssl!==true||!in_array($ssl->version,['TLSv1.2','TLSv1.3'],true))throw new RuntimeException();
 $ip=gethostbyname('db');if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false)throw new RuntimeException();
 $base='pgsql:host=db;hostaddr='.$ip.';port=5432;dbname=sites_staging;sslmode=verify-full;sslrootcert=';
 $cases=[str_replace('host=db;','host=wrong.invalid;',$base).$c['sslrootcert']=>'does not match host name',
  $base.'/tmp/sites-untrusted-ca.pem'=>'certificate verify failed'];
 foreach($cases as $dsn=>$marker){$denied=false;try{new PDO($dsn,$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
  catch(PDOException $e){$denied=str_contains($e->getMessage(),$marker);}if(!$denied)throw new RuntimeException();}
 echo json_encode(['schema'=>'sites.runtime.pg.verify-full.v1','status'=>'verified','runtime_role'=>'sites_runtime','hostname'=>'db','tls_version'=>$ssl->version,'untrusted_ca_denied'=>true,'hostname_mismatch_denied'=>true,'can_accept_orders'=>false,'can_publish'=>false],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"BLOCKED: runtime database TLS verification failed; private context suppressed.\n");exit(1);}
