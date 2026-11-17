<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
set_error_handler(static function():never{throw new RuntimeException('PHP preflight');});
set_exception_handler(static function():void{fwrite(STDERR,"PHP PREFLIGHT REFUSED\n");exit(2);});
if(PHP_SAPI!=='cli'||PHP_VERSION_ID<80300||PHP_VERSION_ID>=80500||
   !isset($argv[1])||realpath($argv[1])!==$argv[1]||php_ini_loaded_file()!==$argv[1]||
   (php_ini_scanned_files()!==false&&php_ini_scanned_files()!=='')||
   ini_get('auto_prepend_file')!==''||ini_get('auto_append_file')!==''||
   ini_get('display_errors')!=='0'||ini_get('log_errors')!=='0')throw new RuntimeException('PHP configuration mismatch');
foreach(['PDO','pdo_pgsql','posix','mbstring','tokenizer','ctype','dom','xml','fileinfo'] as $ext)if(!extension_loaded($ext))throw new RuntimeException('Required extension absent');
foreach(['proc_open','random_bytes','posix_geteuid'] as $fn)if(!function_exists($fn))throw new RuntimeException('Required function absent');
if(!in_array('pgsql',PDO::getAvailableDrivers(),true))throw new RuntimeException('PDO pgsql absent');
echo "PHP PREFLIGHT OK: explicit INI, no scan/prepend/append, CLI and extensions verified; no cluster started\n";
