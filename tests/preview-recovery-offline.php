<?php
declare(strict_types=1);
require __DIR__.'/fixtures/preview-recovery/guard.php';
$a=['db'=>'postgres','login'=>'recovery_source','actor'=>'recovery_source','address'=>'127.0.0.1','port'=>55450,'directory'=>'/tmp/synthetic/source','cluster'=>'123','version'=>180007];
$b=array_replace($a,['login'=>'recovery_destination','actor'=>'recovery_destination','port'=>55451,'directory'=>'/tmp/synthetic/destination','cluster'=>'456']);
$count=0;
$refuse=function(callable $test)use(&$count):void{try{$test();}catch(RuntimeException $e){$count++;return;}throw new RuntimeException('Expected refusal absent');};
recoveryIdentity($a,$a);recoveryIdentity($b,$b);recoveryPair($a,$b);$count+=3;
$refuse(fn()=>recoveryIdentity($a,$b));$refuse(fn()=>recoveryIdentity($b,$a));
foreach(['db','login','actor','address','port','directory','cluster','version'] as $key) $refuse(fn()=>recoveryIdentity($a,array_replace($a,[$key=>'0'])));
foreach(['cluster','directory','login','port'] as $key) $refuse(fn()=>recoveryPair($a,array_replace($b,[$key=>$a[$key]])));
echo "OFFLINE OK: $count identity comparisons; no database or restore executed\n";
// Unexpected infrastructure failures must escape; never satisfy a domain denial.
recoveryExpectedRefusal(static fn()=>throw new RuntimeException('Private preview unavailable'),'Private preview unavailable');$count++;
foreach([new PDOException('connection lost'),new RuntimeException('other failure'),new LogicException('bug')] as $error){
    try{recoveryExpectedRefusal(static fn()=>throw $error,'Private preview unavailable');throw new Exception('Unexpected swallow');}catch(Throwable $caught){if($caught!==$error)throw $caught;$count++;}
}
$actual=['db'=>'postgres','login'=>'lab486_a_reader','actor'=>'lab486_a_reader','address'=>'127.0.0.1','port'=>55451,'version'=>180007];
recoveryRuntimeIdentity($actual,$actual);$count++;
foreach(['db','login','actor','address','port','version'] as $key)$refuse(fn()=>recoveryRuntimeIdentity($actual,array_replace($actual,[$key=>'0'])));
$ddl="\\restrict ABC123\nCREATE SCHEMA sites_preview;\nGRANT USAGE ON SCHEMA sites_preview TO lab486_a_reader;\n\\unrestrict ABC123\n";
if(recoveryCanonicalDdl($ddl)!==recoveryCanonicalDdl(str_replace('ABC123','DEF456',$ddl)))throw new RuntimeException('Key normalization failed');$count++;
if(recoveryCanonicalDdl($ddl)===recoveryCanonicalDdl(str_replace('GRANT USAGE','GRANT CREATE',$ddl)))throw new RuntimeException('ACL semantic change ignored');$count++;
$refuse(fn()=>recoveryCanonicalDdl(str_replace('unrestrict ABC123','unrestrict BAD',$ddl)));
$refuse(fn()=>recoveryCanonicalDdl($ddl.$ddl));
$base=sys_get_temp_dir().'/preview-recovery-offline-'.bin2hex(random_bytes(8));
mkdir($base,0700);file_put_contents($base.'/fixture','synthetic');chmod($base.'/fixture',0600);
try{
    recoveryPrivatePath($base,true);recoveryPrivatePath($base.'/fixture',false);$count+=2;
    chmod($base.'/fixture',0644);clearstatcache();$refuse(fn()=>recoveryPrivatePath($base.'/fixture',false));
    chmod($base.'/fixture',0600);symlink($base.'/fixture',$base.'/alias');$refuse(fn()=>recoveryPrivatePath($base.'/alias',false));
    link($base.'/fixture',$base.'/hardlink');clearstatcache();$refuse(fn()=>recoveryPrivatePath($base.'/fixture',false));
}finally{foreach(['alias','hardlink','fixture'] as $name)if(file_exists($base.'/'.$name)||is_link($base.'/'.$name))unlink($base.'/'.$name);rmdir($base);}
foreach(['PGOPTIONS','PGHOSTADDR','PGSERVICE','DB_URL','APP_CONFIG_CACHE','PHP_INI_SCAN_DIR'] as $key)putenv($key.'=synthetic-test-only');
putenv('RECOVERY_ROOT=synthetic-preserved');
recoveryCleanEnvironment(['RECOVERY_ROOT']);
foreach(['PGOPTIONS','PGHOSTADDR','PGSERVICE','DB_URL','APP_CONFIG_CACHE','PHP_INI_SCAN_DIR'] as $key){if(getenv($key)!==false)throw new RuntimeException('Ambient config retained');$count++;}
if(getenv('RECOVERY_ROOT')!=='synthetic-preserved'||$_ENV!==[]||$_SERVER!==[])throw new RuntimeException('Allowlist mismatch');$count++;
echo "OFFLINE HARDENING OK: $count total cases; no SQL/startup/restore\n";
