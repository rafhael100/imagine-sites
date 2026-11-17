<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SITES_LOCAL_RECOVERY')!=='1')exit(2);
umask(0077);ini_set('display_errors','0');ini_set('log_errors','0');
$stage='private inputs';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'RECOVERY FAILED: stage='.($GLOBALS['stage']??'bootstrap')."\n");exit(1);});
set_error_handler(static function(int $level,string $message):never{throw new ErrorException('Sanitized laboratory error',0,$level);});
register_shutdown_function(static function():void{$e=error_get_last();if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))fwrite(STDERR,"RECOVERY FAILED: fatal bootstrap error\n");});
require __DIR__.'/fixtures/preview-recovery/guard.php';
$allowed=['PATH','HOME','LC_ALL','PHP_INI_SCAN_DIR','LD_LIBRARY_PATH','SITES_LOCAL_RECOVERY','RECOVERY_ROOT','RECOVERY_SOURCE_CLUSTER','RECOVERY_DESTINATION_CLUSTER','PG_BIN'];
recoveryCleanEnvironment($allowed);
$root=getenv('RECOVERY_ROOT')?:'';
recoveryPrivatePath($root,true);
if(!preg_match('~^/tmp/sites487-recovery-[A-Za-z0-9]{8}$~D',$root))throw new RuntimeException('Own private root required');
$passwords=[];
foreach(['source','destination','runtime-source','runtime-destination'] as $side){
    recoveryPrivatePath($root.'/password-'.$side,false);
    $passwords[$side]=trim(file_get_contents($root.'/password-'.$side));
    if(!preg_match('/^[a-f0-9]{64}$/D',$passwords[$side]))throw new RuntimeException('Synthetic password required');
}
if(count(array_unique($passwords))!==4)throw new RuntimeException('Independent credentials required');
foreach(['source','destination'] as $side)recoveryPrivatePath($root.'/'.$side,true);
// Use Laravel database components only: no kernel, application/package providers, dotenv or config-cache load.
require __DIR__.'/../apps/sites/vendor/autoload.php';
use Illuminate\Support\Facades\DB;
use Imagine\Sites\Production\{PrivatePreviewWorkspace,SiteBrief};
$app=new Illuminate\Foundation\Application(realpath(__DIR__.'/../apps/sites'));
$app->instance('config',new Illuminate\Config\Repository(['database'=>['default'=>'recovery_source','connections'=>[]]]));
$app->instance('db',new Illuminate\Database\DatabaseManager($app,new Illuminate\Database\Connectors\ConnectionFactory($app)));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
putenv('HOME='.$root);
$pg=getenv('PG_BIN');
if(!$pg||realpath($pg)!==$pg)throw new RuntimeException('Canonical tools required');
$build=null;
foreach(['postgres','initdb','pg_ctl','pg_controldata','pg_dump','pg_restore'] as $tool){
    if(realpath($pg.'/'.$tool)!==$pg.'/'.$tool||!is_executable($pg.'/'.$tool))throw new RuntimeException('Canonical tool required');
    $p=proc_open([$pg.'/'.$tool,'--version'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,['PATH'=>'/usr/bin:/bin','LC_ALL'=>'C','HOME'=>$root,'LD_LIBRARY_PATH'=>getenv('LD_LIBRARY_PATH')?:'']);
    if(!is_resource($p))throw new RuntimeException('Tool validation failed');
    $version=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);
    if(proc_close($p)!==0||!preg_match('/^'.preg_quote($tool,'/').' \(PostgreSQL\) (18\..+)$/D',$version,$m)||($build!==null&&$build!==$m[1]))throw new RuntimeException('Build mismatch');
    $build=$m[1];
}
$expected=[];$db=[];
foreach(['source'=>55450,'destination'=>55451] as $side=>$port){
    $dir=realpath($root.'/'.$side);
    if($dir!==$root.'/'.$side)throw new RuntimeException('Own exact directory required');
    $login='recovery_'.$side;
    $cluster=getenv('RECOVERY_'.strtoupper($side).'_CLUSTER');
    if(!is_string($cluster)||!preg_match('/^[1-9][0-9]+$/D',$cluster))throw new RuntimeException('Expected cluster missing');
    $expected[$side]=['db'=>'postgres','login'=>$login,'actor'=>$login,'address'=>'127.0.0.1','port'=>$port,'directory'=>$dir,'cluster'=>$cluster];
    $config=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>$port,'database'=>'postgres','username'=>$login,'password'=>$passwords[$side],'charset'=>'utf8','prefix'=>'','sslmode'=>'disable'];
    config()->set('database.connections.recovery_'.$side,$config);
    DB::purge('recovery_'.$side);$connection=DB::connection('recovery_'.$side);
    foreach($config as $key=>$value)if($connection->getConfig($key)!==$value)throw new RuntimeException('Effective Laravel config mismatch');
    if($connection->getConfig('url'))throw new RuntimeException('Unexpected URL');
    $connection->setReconnector(static function():never{throw new RuntimeException('Reconnect forbidden');});
    $db[$side]=$connection->getPdo();
}
$identity=static fn(PDO $pdo):array=>$pdo->query("SELECT current_database() AS db,session_user AS login,current_user AS actor,host(inet_server_addr()) AS address,inet_server_port() AS port,current_setting('data_directory') AS directory,current_setting('server_version_num') AS version,(SELECT system_identifier::text FROM pg_control_system()) AS cluster")->fetch(PDO::FETCH_ASSOC);
$preflight=function()use($expected,$db,$identity):void{
    recoveryPair($expected['source'],$expected['destination']);
    foreach($db as $side=>$pdo)recoveryIdentity($expected[$side],$identity($pdo));
};
$stage='preflight before all SQL writes';$preflight();
$refused=static fn(callable $operation)=>recoveryExpectedRefusal($operation,'Recovery SQL identity mismatch');
// Live SQL identities, not a mock; all negative comparisons precede migrations/roles.
$refused(fn()=>recoveryIdentity($expected['source'],$identity($db['destination'])));
$refused(fn()=>recoveryIdentity($expected['destination'],$identity($db['source'])));
$wrong=$expected['source'];$wrong['cluster']=$wrong['cluster']==='1'?'2':'1';
$refused(fn()=>recoveryIdentity($wrong,$identity($db['source'])));
foreach($db as $pdo){
    if($pdo->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()!==null||(int)$pdo->query("SELECT count(*) FROM pg_roles WHERE rolname LIKE 'lab486_%'")->fetchColumn()!==0)throw new RuntimeException('Fresh laboratory required');
}
echo "NEGATIVE PREFLIGHT OK: swapped source/destination and wrong source cluster refused before SQL writes\nPREFLIGHT OK: effective Laravel plus independent SQL identities\n";
$companies=['a'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','b'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
$projects=['a'=>'11111111-1111-4111-8111-111111111111','b'=>'22222222-2222-4222-8222-222222222222'];
$profiles=['author'=>[true,false],'reviewer'=>[false,true],'reader'=>[false,false]];
$stage='synthetic profiles';
// Same NOLOGIN owner and segregated fixture profiles as #487. No product grants changed.
foreach($db as $side=>$pdo){
    $pdo->exec('CREATE ROLE lab486_owner NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
    foreach($companies as $tenant=>$company)foreach($profiles as $profile=>$_){
        $role="lab486_{$tenant}_{$profile}";
        $pdo->exec("CREATE ROLE $role LOGIN PASSWORD ".$pdo->quote($passwords['runtime-'.$side])." NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT");
    }
}
$stage='source existing migration';
config()->set('database.default','recovery_source');
$source=$db['source'];
$source->exec('GRANT CREATE ON DATABASE postgres TO lab486_owner; SET ROLE lab486_owner');
if(DB::connection()->getPdo()!==$source||$source->query('SELECT current_user')->fetchColumn()!=='lab486_owner')throw new RuntimeException('Migration PDO/role mismatch');
try{(require __DIR__.'/../apps/sites/database/migrations/2026_11_16_000004_add_private_preview_workspace.php')->up();if(DB::connection()->getPdo()!==$source||$source->query('SELECT current_user')->fetchColumn()!=='lab486_owner')throw new RuntimeException('Migration PDO/role changed');}finally{$source->exec('RESET ROLE');}
$source->exec('REVOKE CREATE ON DATABASE postgres FROM lab486_owner');
$owners=function(PDO $pdo):array{
    $rows=$pdo->query("SELECT 'schema' AS kind,nspname AS name,pg_get_userbyid(nspowner) AS owner FROM pg_namespace WHERE nspname='sites_preview' UNION ALL SELECT 'relation',c.relname,pg_get_userbyid(c.relowner) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='sites_preview' AND c.relkind IN ('r','S') UNION ALL SELECT 'function',p.oid::regprocedure::text,pg_get_userbyid(p.proowner) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='sites_preview' ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==12||array_unique(array_column($rows,'owner'))!==['lab486_owner'])throw new RuntimeException('Catalog owner mismatch');
    return $rows;
};
$sourceOwners=$owners($source);
$acls=function(PDO $pdo):array{
    return $pdo->query("SELECT 'schema' AS kind,nspname AS name,nspacl::text AS acl FROM pg_namespace WHERE nspname='sites_preview' UNION ALL SELECT 'relation',c.relname,c.relacl::text FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='sites_preview' AND c.relkind IN ('r','S') UNION ALL SELECT 'function',p.oid::regprocedure::text,p.proacl::text FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='sites_preview' ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC);
};


$runtime=function(string $side,string $tenant,string $profile)use($expected,$passwords,$db,$identity):PDO{
    recoveryIdentity($expected[$side],$identity($db[$side]));
    $role="lab486_{$tenant}_{$profile}";
    $pdo=new PDO('pgsql:host=127.0.0.1;port='.$expected[$side]['port'].';dbname=postgres;sslmode=disable;connect_timeout=5',$role,$passwords['runtime-'.$side],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $actual=$pdo->query("SELECT current_database() AS db,session_user AS login,current_user AS actor,host(inet_server_addr()) AS address,inet_server_port() AS port,current_setting('server_version_num') AS version,pg_postmaster_start_time()::text AS started")->fetch(PDO::FETCH_ASSOC);
    $pin=array_replace($expected[$side],['login'=>$role,'actor'=>$role]);
    recoveryRuntimeIdentity($pin,$actual);
    if($actual['started']!==$db[$side]->query('SELECT pg_postmaster_start_time()::text')->fetchColumn())throw new RuntimeException('Runtime server changed');
    return $pdo;
};
foreach($companies as $tenant=>$company)foreach($profiles as $profile=>[$edit,$review]){
    $role="lab486_{$tenant}_{$profile}";
    $source->exec("GRANT USAGE ON SCHEMA sites_preview TO $role; GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO $role; GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text) TO $role");
    if($edit)$source->exec("GRANT INSERT ON sites_preview.projects,sites_preview.versions TO $role");
    if($review)$source->exec("GRANT EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text) TO $role");
    $s=$source->prepare("INSERT INTO sites_preview.principals VALUES(?,?::uuid,?::boolean,?::boolean,now()+interval '1 hour',NULL)");
    $s->execute([$role,$company,$edit?'true':'false',$review?'true':'false']);
}
$stage='source synthetic history';
foreach($companies as $tenant=>$company){
    $author=new PrivatePreviewWorkspace($runtime('source',$tenant,'author'));
    $reviewer=new PrivatePreviewWorkspace($runtime('source',$tenant,'reviewer'));
    $brief=new SiteBrief($tenant==='a'?'onepage':'landing_page','Synthetic company','Recovery fixture','Synthetic only','Read more',['Example']);
    $v=$author->createDemonstration($projects[$tenant],$company,$brief);
    $reviewer->review($projects[$tenant],$company,1,$v['sha256'],'approved_internal');
    $author->revise($projects[$tenant],$company,1,new SiteBrief($brief->productCode,'Synthetic company','Second revision','Synthetic only','Read more',['Example']));
}
// Revocation captured in this snapshot only; no independent operational revocation claim.
$source->exec("UPDATE sites_preview.principals SET revoked_at=now() WHERE database_login='lab486_a_reader'");
$source->exec('SET default_transaction_read_only=on'); // Source is never a restore target.
$manifest=function(PDO $pdo):array{
    $tables=$pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='sites_preview' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    if($tables!==['audit','principals','projects','reviews','versions'])throw new RuntimeException('Namespace drift: review coverage first');
    $result=[];
    foreach($tables as $table){
        $rows=$pdo->query("SELECT row_to_json(t)::text FROM sites_preview.$table t ORDER BY row_to_json(t)::text COLLATE \"C\"")->fetchAll(PDO::FETCH_COLUMN);
        $result[$table]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
    }
    $result['sequence']=$pdo->query('SELECT last_value,is_called FROM sites_preview.audit_id_seq')->fetch(PDO::FETCH_ASSOC);
    return $result;
};
// Tools receive exact loopback coordinates and private synthetic pgpass, no inherited PG options.
$command=function(string $tool,string $side,array $args,bool $readOnly=false)use($pg,$expected,$root,$passwords,$preflight):string{
    $e=$expected[$side];$password=$passwords[$side];$passfile=$root.'/pgpass-'.$side;
    if(file_exists($passfile)||is_link($passfile))recoveryPrivatePath($passfile,false);
    file_put_contents($passfile,"127.0.0.1:{$e['port']}:postgres:{$e['login']}:$password\n");chmod($passfile,0600);
    $cmd=array_merge([$pg.'/'.$tool,'--host=127.0.0.1','--port='.(string)$e['port'],'--username='.$e['login'],'--dbname=postgres','--no-password'],$args);
    $env=['PATH'=>'/usr/bin:/bin','HOME'=>$root,'LD_LIBRARY_PATH'=>getenv('LD_LIBRARY_PATH')?:'','PGPASSFILE'=>$passfile,'PGSSLMODE'=>'disable','PGCONNECT_TIMEOUT'=>'5','PGOPTIONS'=>$readOnly?'-c default_transaction_read_only=on':'','LC_ALL'=>'C'];
    $preflight();
    $process=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,$env);
    if(!is_resource($process))throw new RuntimeException('Tool start failed');
    $output=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $exit=proc_close($process);$preflight();
    if($exit!==0)throw new RuntimeException('Tool failed');
    return $output;
};
$schema=function(string $side)use($command):string{
    $sql=$command('pg_dump',$side,['--schema=sites_preview','--schema-only'],true);
    // pg_dump generates fresh random psql restrict keys, unrelated to schema fidelity.
    return recoveryCanonicalDdl($sql);
};
$stage='source snapshot';$preflight();$before=$manifest($source);$ddl=$schema('source');$sourceAcls=$acls($source);
$expectedCounts=['audit'=>8,'principals'=>6,'projects'=>2,'reviews'=>2,'versions'=>4];
foreach($expectedCounts as $table=>$count)if($before[$table]['count']!==$count)throw new RuntimeException('Fixture cardinality mismatch');
$archive=$root.'/preview.dump';
$command('pg_dump','source',['--schema=sites_preview','--format=custom','--file='.$archive],true);
$archiveHash=hash_file('sha256',$archive);
$stage='restore destination';$preflight();
if($db['destination']->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()!==null)throw new RuntimeException('Restore target must still be empty');
if($manifest($source)!==$before||$schema('source')!==$ddl||!hash_equals($archiveHash,hash_file('sha256',$archive)))throw new RuntimeException('Snapshot changed');
// No --clean, --create, --no-owner, --no-acl or --disable-triggers. Preserve exact schema/ACL/owner.
$command('pg_restore','destination',['--exit-on-error','--single-transaction',$archive]);
$stage='readback fidelity';
if($owners($db['destination'])!==$sourceOwners||$acls($db['destination'])!==$sourceAcls)throw new RuntimeException('Restored owner differs');
if($manifest($db['destination'])!==$before||$schema('destination')!==$ddl||$manifest($source)!==$before)throw new RuntimeException('Restore fidelity mismatch');
echo 'MANIFEST OK: '.json_encode($before,JSON_THROW_ON_ERROR)."\nARCHIVE SHA256: $archiveHash\nSCHEMA SHA256: ".hash('sha256',$ddl)."\n";
$stage='restored isolation';
foreach($companies as $tenant=>$company)foreach($profiles as $profile=>[$edit,$review]){
    $pdo=$runtime('destination',$tenant,$profile);$w=new PrivatePreviewWorkspace($pdo);$w->assertSafeRuntime();
    $revoked=$tenant==='a'&&$profile==='reader';
    $s=$pdo->prepare("SELECT sites_preview.can_access(?::uuid,'read') AS r,sites_preview.can_access(?::uuid,'edit') AS e,sites_preview.can_access(?::uuid,'review') AS v");$s->execute([$company,$company,$company]);
    if($s->fetch(PDO::FETCH_ASSOC)!==['r'=>!$revoked,'e'=>!$revoked&&$edit,'v'=>!$revoked&&$review])throw new RuntimeException('Restored capabilities differ');
    if($pdo->query("SELECT has_schema_privilege(current_user,'sites_preview','CREATE') OR has_schema_privilege(current_user,'public','CREATE') OR has_database_privilege(current_user,current_database(),'CREATE')")->fetchColumn()!==false)throw new RuntimeException('Unexpected CREATE');
    $other=$tenant==='a'?'b':'a';
    $q=$pdo->prepare('SELECT count(*) FROM sites_preview.versions WHERE company_id=?::uuid');$q->execute([$companies[$other]]);
    if((int)$q->fetchColumn()!==0)throw new RuntimeException('Cross-company rows visible');
    recoveryExpectedRefusal(fn()=>$w->view($projects[$other],$companies[$other],1),'Private preview unavailable');
    if($revoked)recoveryExpectedRefusal(fn()=>$w->view($projects[$tenant],$company,1),'Private preview unavailable');
    else foreach([1,2] as $revision)$w->view($projects[$tenant],$company,$revision); // Also verifies stored HTML digest.
    if((int)$pdo->query('SELECT count(*) FROM sites_preview.reviews WHERE revision=2')->fetchColumn()!==0)throw new RuntimeException('Inherited review');
}
$owner=$db['destination']->query("SELECT NOT rolcanlogin AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication FROM pg_roles WHERE rolname='lab486_owner'")->fetchColumn();
if($owner!==true)throw new RuntimeException('Owner profile differs');
if((int)$db['destination']->query("SELECT count(*) FROM pg_tables WHERE schemaname='sites_preview' AND tablename IN ('projects','versions','reviews','audit') AND rowsecurity")->fetchColumn()!==4)throw new RuntimeException('RLS absent');
if((int)$db['destination']->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='sites_preview' AND c.relforcerowsecurity")->fetchColumn()!==4)throw new RuntimeException('FORCE RLS absent');
file_put_contents($root.'/manifest.json',json_encode(['owners'=>$sourceOwners,'acls'=>$sourceAcls,'acl_sha256'=>hash('sha256',json_encode($sourceAcls,JSON_THROW_ON_ERROR)),'tables'=>$before,'archive_sha256'=>$archiveHash,'schema_sha256'=>hash('sha256',$ddl),'base'=>'3bbc9dda632bd4405fe9cfc99249f55642a992ce'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "RECOVERY OK: full sites_preview data/schema/sequence/ACL/owner readback; two companies, snapshot revocation, version digests and segregated profiles. Synthetic local recovery only.\n";
