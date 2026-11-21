<?php
declare(strict_types=1);
// Test-only CLI. Supervisor owns the network namespace, cluster and per-DB receipt.
use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Domain\Identity\Security\{PortalPasswordCredential,PortalTotpSecretCipher};
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use App\Providers\PortalDevelopmentAuthServiceProvider;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Imagine\Sites\Production\SiteBrief;
use NativeHttpBriefing\Client;
use Stage2Fixture\Slot;
use function Stage2Fixture\{need, installProjection, runCases};

const PORTAL_DB='portal_sites_stage2_ci';
const SITES_DB='sites_portal_stage2_ci';
const AUTHOR_SQL='sites_preview_stage2_author';
const REVIEWER_SQL='sites_preview_stage2_reviewer';
const COMPANY='ac1c2d37-0995-42b2-8cec-6f574084f09e';
const AUTHOR='2be7a8ea-5afe-46cc-9517-3cb7b2a2aa31';
const REVIEWER='6c614139-5d9d-4ffa-bec0-fdf153622184';
const PROJECT='38aca841-1c56-4175-bdf1-e44f6fa1f29e';
const ORIGIN='https://portal-dev.imaginecomunica.com.br';
const BASE32_TEST_SECRET='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
const RAW_TEST_SECRET='12345678901234567890';

function protectedBytes(string $file, int $max):string {
    clearstatcache(true,$file);
    $s=lstat($file);
    need($s!==false && ($s['mode'] & 0177777)===0100600 && $s['uid']===posix_geteuid()
        && $s['nlink']===1 && $s['size']>0 && $s['size']<=$max,'PROTECTED_INPUT');
    $h=fopen($file,'rb');
    need(is_resource($h),'PROTECTED_OPEN');
    try {
        $f=fstat($h);
        need($f!==false && $f['ino']===$s['ino'] && $f['dev']===$s['dev']
            && $f['uid']===$s['uid'] && $f['mode']===$s['mode'] && $f['nlink']===1 && $f['size']===$s['size'],'PROTECTED_ID');
        $bytes=stream_get_contents($h,$max+1);
        need(is_string($bytes) && strlen($bytes)===$s['size'],'PROTECTED_BYTES');
        return $bytes;
    } finally {fclose($h);}
}

function cfg(string $db,int $port,string $role,#[SensitiveParameter] string $password):array {
    return ['driver'=>'pgsql','host'=>'127.0.0.1','port'=>$port,'database'=>$db,'username'=>$role,
        'password'=>$password,'charset'=>'utf8','prefix'=>'','sslmode'=>'disable','search_path'=>'public',
        'options'=>[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_PERSISTENT=>false,PDO::ATTR_TIMEOUT=>3]];
}

function slot($app,string $alias,string $db,string $role,#[SensitiveParameter] string $password,array $receipt):Slot {
    $config=cfg($db,$receipt['port'],$role,$password);
    $app['config']->set('database.connections.'.$alias,$config);
    $manager=$app->make('db');
    $connection=$manager->connection($alias);
    foreach ($config as $k=>$v) need($connection->getConfig($k)===$v,'EFFECTIVE_CONFIG');
    need($connection->getReadPdo()===$connection->getPdo(),'SAME_READ_WRITE');
    return Slot::qualify($connection,[
        'dir'=>$receipt['dir'],'port'=>$receipt['port'],'oid'=>$receipt['oids'][$db],
        'start'=>$receipt['start'],'system_id'=>$receipt['system_id']
    ],$db,$role);
}

function codeAt(int $counter):string {
    $h=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),RAW_TEST_SECRET,true);
    $offset=ord($h[19])&15;
    return str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
}

function passwordStep(Client $client,string $email,#[SensitiveParameter] string $pw):Request {
    [$s,$v,$request]=$client->post('/_development/auth/password',['email'=>$email,'password'=>$pw,'organization'=>COMPANY]);
    need($s===202 && $v===['next'=>'mfa'],'PORTAL_PASSWORD');
    return $request;
}

function mfaStep(Client $client, PDO $admin, string $subject, int $last=-1):array {
    $deadline=hrtime(true)+35_000_000_000;
    do {
        $counter=(int)$admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
        if ($counter>$last) {
            $v=$client->post('/_development/auth/mfa',['code'=>codeAt($counter)]);
            need($v[0]===200 && $v[1]===['authenticated'=>true],'PORTAL_MFA');
            [$s,$json,$r]=$client->call('GET','/_development/auth/session');
            need($s===200 && $json===['authenticated'=>true],'PORTAL_NATIVE_SESSION');
            $q=$admin->prepare('SELECT mfa_last_used_counter FROM public.portal_identities WHERE id=?::uuid');
            $q->execute([$subject]);
            $used=$q->fetchColumn();
            need((is_int($used) || (is_string($used)&&ctype_digit($used)))
                && (int)$used>$last && abs((int)$used-$counter)<=1,'MFA_COUNTER_READBACK');
            return [$r,(int)$used];
        }
        usleep(100_000);
    } while (hrtime(true)<$deadline);
    throw new RuntimeException('STAGE2_FIXTURE_REFUSED:TOTP_WINDOW');
}

function seedPortal(Slot $portal, string $base, string $root, string &$phase):array {
    $portal->assertSql();
    $p=$portal->pdo;
    foreach ([
        'DDL_PORTAL_BROWSER_AUTH'=>'portal_browser_auth.sql',
        'DDL_PORTAL_BROWSER_ENVELOPES'=>'portal_browser_envelopes.sql',
    ] as $step=>$file) {
        $phase=$step;
        $portal->assertSql();
        $p->exec(file_get_contents($base.'/database/security/'.$file));
    }
    $phase='DDL_PORTAL_ROLE_BOOTSTRAP';
    $proof=bin2hex(random_bytes(32));
    $totpKey=random_bytes(32);
    $identityPassword=bin2hex(random_bytes(32));
    $envelopePassword=bin2hex(random_bytes(32));
    $appPassword=bin2hex(random_bytes(32));
    $p->beginTransaction();
    try {
        $p->exec("SET LOCAL password_encryption='scram-sha-256'");
        $p->exec('REVOKE CONNECT,TEMP ON DATABASE '.PORTAL_DB.' FROM PUBLIC');
        foreach (['portal_browser_runtime'=>$identityPassword,'portal_browser_envelope_runtime'=>$envelopePassword] as $role=>$pw) {
            $p->exec('ALTER ROLE '.$role.' LOGIN PASSWORD '.$p->quote($pw));
            $p->exec('GRANT CONNECT ON DATABASE '.PORTAL_DB.' TO '.$role);
        }
        $phase='DDL_PORTAL_PROOF';
        $p->prepare('INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)')->execute([$proof]);
        $phase='DDL_PORTAL_ORGANIZATION';
        $p->prepare("INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision) VALUES(?,'fixture-stage-two','Synthetic Stage Two','active',1)")->execute([COMPANY]);
        foreach ([AUTHOR=>'author@example.invalid',REVIEWER=>'reviewer@example.invalid'] as $id=>$mail) {
            $phase='DDL_PORTAL_IDENTITY';
            $hash=PortalPasswordCredential::makeHash($appPassword);
            need(is_string($hash),'PASSWORD_FIXTURE');
            $p->prepare("INSERT INTO public.portal_identities(id,email_normalized,password_hash,status,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision) VALUES(?,?,?,'active',?,clock_timestamp(),clock_timestamp(),1)")
                ->execute([$id,$mail,$hash,PortalTotpSecretCipher::seal(BASE32_TEST_SECRET,$totpKey)]);
            $phase='DDL_PORTAL_MEMBERSHIP';
            $p->prepare("INSERT INTO public.portal_memberships(id,identity_id,organization_id,role,status,membership_revision) VALUES(gen_random_uuid(),?,?,'member','active',1)")->execute([$id,COMPANY]);
        }
        $phase='DDL_PORTAL_COMMIT';
        $p->commit();
    } catch(Throwable $e) {
        if ($p->inTransaction()) $p->rollBack();
        throw $e;
    }
    $phase='DDL_PORTAL_CUSTODY_FILE';
    $file=$root.'/portal-fixture.json';
    $f=fopen($file,'x');
    need(is_resource($f),'PORTAL_FILE');
    try {
        $json=json_encode(['origin'=>ORIGIN,'host'=>'127.0.0.1','port'=>$portal->witness['port'],'database'=>PORTAL_DB,
            'identity_password'=>$identityPassword,'envelope_password'=>$envelopePassword,
            'proof_key'=>$proof,'totp_key'=>base64_encode($totpKey),'scope_pepper'=>bin2hex(random_bytes(32))],JSON_THROW_ON_ERROR);
        need(fwrite($f,$json)===strlen($json),'PORTAL_FILE_BYTES');
    } finally {fclose($f);}
    return [$file,$appPassword,$identityPassword];
}

function seedSites(Slot $sites, string $sitesRoot, string &$phase):array {
    $sites->assertSql();
    $p=$sites->pdo;
    need($p->query("SELECT to_regnamespace('sites_preview') IS NULL")->fetchColumn()===true,'SITES_ABSENT');
    $logins=[AUTHOR_SQL=>bin2hex(random_bytes(32)),REVIEWER_SQL=>bin2hex(random_bytes(32))];
    $sql=file_get_contents($sitesRoot.'/contracts/private-preview/001-workspace.sql');
    need(is_string($sql) && sha1('blob '.strlen($sql).chr(0).$sql)==='d1a3ec8ad66cb32f2911f73d3654ee3c857b4f50','SITES_DDL_PIN');
    $p->beginTransaction();
    try {
        $p->exec("SET LOCAL password_encryption='scram-sha-256'; SET LOCAL lock_timeout=2000; SET LOCAL statement_timeout=5000");
        $p->exec('REVOKE CONNECT,TEMP ON DATABASE '.SITES_DB.' FROM PUBLIC');
        $p->exec('CREATE ROLE sites_preview_stage2_owner NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
        $p->exec('GRANT CREATE ON DATABASE '.SITES_DB.' TO sites_preview_stage2_owner');
        $phase='DDL_SITES_NATIVE_SQL';
        $p->exec('SET LOCAL ROLE sites_preview_stage2_owner');
        $p->exec($sql); // exact native SQL, under the non-login runtime-independent owner
        $phase='DDL_SITES_GRANTS';
        $p->exec('RESET ROLE');
        $p->exec('REVOKE CREATE ON DATABASE '.SITES_DB.' FROM sites_preview_stage2_owner');
        foreach ($logins as $role=>$pw) {
            $p->exec('CREATE ROLE '.$role.' LOGIN PASSWORD '.$p->quote($pw).' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
            $p->exec('GRANT CONNECT ON DATABASE '.SITES_DB.' TO '.$role);
            $p->exec('GRANT USAGE ON SCHEMA sites_preview TO '.$role);
            $p->exec('GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO '.$role);
            $p->exec('GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text) TO '.$role);
            if ($role===AUTHOR_SQL) {
                $p->exec('GRANT INSERT ON sites_preview.projects,sites_preview.versions TO '.$role);
            } else {
                $p->exec('GRANT EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text) TO '.$role);
            }
            $p->prepare("INSERT INTO sites_preview.principals(database_login,company_id,can_edit,can_review,expires_at,revoked_at) VALUES(?,?::uuid,?::boolean,?::boolean,clock_timestamp()+interval '15 minutes',NULL)")
                ->execute([$role,COMPANY,$role===AUTHOR_SQL?'true':'false',$role===REVIEWER_SQL?'true':'false']);
        }
        $phase='DDL_SITES_COMMIT';
        $p->commit();
    } catch(Throwable $e) {
        if ($p->inTransaction()) $p->rollBack();
        throw $e;
    }
    return $logins;
}

$phase='ENTRY';
$fixtureFile=null;
$failure=true;
ini_set('display_errors','0');
ini_set('log_errors','0');
error_reporting(E_ALL);
umask(0077);
try {
    require __DIR__.'/stage2.php';
    need(PHP_SAPI==='cli' && $argc===4 && function_exists('posix_geteuid') && posix_geteuid()!==0,'CLI_ONLY');
    [$unused,$portalRepo,$sitesRoot,$witnessFile]=$argv;
    $root=dirname($witnessFile);
    $base=$portalRepo.'/apps/portal';
    need(preg_match('~^/tmp/sites-stage2-[a-zA-Z0-9]+$~D',$root)===1
        && basename($witnessFile)==='witness.json' && realpath($root)===$root
        && is_dir($root) && (fileperms($root)&0777)===0700 && fileowner($root)===posix_geteuid()
        && realpath($portalRepo)===$portalRepo && realpath($sitesRoot)===$sitesRoot
        && $portalRepo!==$sitesRoot && realpath($base)===$base,'RUN_ROOTS');
    need(glob($base.'/.env*')===[$base.'/.env.example'] && !is_file($base.'/bootstrap/cache/config.php')
        && !is_file($base.'/bootstrap/cache/routes-v7.php'),'NO_EXTERNAL_CONFIG');
    foreach (['DB_URL','DATABASE_URL','APP_CONFIG_CACHE','APP_ROUTES_CACHE','APP_EVENTS_CACHE','APP_PACKAGES_CACHE','APP_SERVICES_CACHE','PGHOST','PGPORT','PGDATABASE','PGUSER','PGPASSWORD','PGPASSFILE','PGSERVICE','PGOPTIONS'] as $key) {
        need(getenv($key)===false,'NO_INHERITED_RUNTIME');
    }
    $receipt=json_decode(protectedBytes($witnessFile,2048),true,10,JSON_THROW_ON_ERROR);
    need(is_array($receipt) && array_diff(array_keys($receipt),['port','dir','system_id','start','oids'])===[]
        && count($receipt)===5 && ($receipt['dir']??null)===$root.'/pgdata'
        && is_int($receipt['port']) && $receipt['port']>=1024 && $receipt['port']<=65535
        && realpath($receipt['dir'])===$receipt['dir'] && is_dir($receipt['dir'])
        && is_array($receipt['oids']) && count($receipt['oids'])===2
        && is_string($receipt['oids'][PORTAL_DB]??null) && is_string($receipt['oids'][SITES_DB]??null)
        && $receipt['oids'][PORTAL_DB]!==$receipt['oids'][SITES_DB],'RECEIPT_SHAPE');
    $password=protectedBytes($root.'/admin.password',65);
    need(preg_match('/\A[a-f0-9]{64}\n\z/D',$password)===1,'ADMIN_PASSWORD_FORMAT');
    $password=rtrim($password,"\n");

    $phase='SOURCE_BYTES';
    foreach ([$base.'/labs/onepage-d0-http-ci/Client.php'=>'fd9ddf2c8eaa989233455b277fec46454d48b774',
        $base.'/database/security/portal_browser_auth.sql'=>'42bb622b01e1b89f7ec99214ac104da2262c3774',
        $base.'/database/security/portal_browser_envelopes.sql'=>'b39bbcdb9156ffe884d21d1b208d842f8cb7942d',
        $sitesRoot.'/src/Production/PrivatePreviewWorkspace.php'=>'0f674ca32091357580e7ae8af9be205591c752b7'] as $path=>$blob) {
        $bytes=file_get_contents($path);
        need(is_string($bytes)&&sha1('blob '.strlen($bytes).chr(0).$bytes)===$blob,'SOURCE_PIN');
    }
    unset($bytes);
    // The outer guard validates both complete Git trees, vendor lock and workspace
    // ownership. Never load a second Composer vendor/overlapping Laravel app.
    $phase='AUTOLOAD';
    $loader=require $base.'/vendor/autoload.php';
    $phase='PSR4';
    $loader->addPsr4('Imagine\\Sites\\',$sitesRoot.'/src/',true);
    $phase='HTTP_FIXTURE';
    require $base.'/labs/onepage-d0-http-ci/Client.php';
    $phase='APP_FACTORY';
    $app=require $base.'/bootstrap/app.php';
    $phase='CACHE';
    // Laravel registers the filesystem binding during kernel bootstrap.
    // Verify the same native cache targets without resolving that service early.
    $configCache=$app->getCachedConfigPath();
    $routesCache=$app->getCachedRoutesPath();
    $cacheDir=$base.'/bootstrap/cache';
    need($configCache===$cacheDir.'/config.php' && $routesCache===$cacheDir.'/routes-v7.php'
        && realpath($cacheDir)===$cacheDir && !is_link($cacheDir)
        && !file_exists($configCache) && !is_link($configCache)
        && !file_exists($routesCache) && !is_link($routesCache)
        && (!$app->bound('config_loaded_from_cache') || $app->make('config_loaded_from_cache')===false)
        && (!$app->bound('routes.cached') || $app->make('routes.cached')===false),
        'UNCACHED_BOOT');
    $phase='KERNEL_RESOLVE';
    $console=$app->make(ConsoleKernel::class);
    $phase='CONSOLE_BOOT';
    $consoleStage='REGISTER_OBSERVER';
    foreach ([
        \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class=>'LOAD_ENVIRONMENT',
        \Illuminate\Foundation\Bootstrap\LoadConfiguration::class=>'LOAD_CONFIGURATION',
        \Illuminate\Foundation\Bootstrap\HandleExceptions::class=>'HANDLE_EXCEPTIONS',
        \Illuminate\Foundation\Bootstrap\RegisterFacades::class=>'REGISTER_FACADES',
        \Illuminate\Foundation\Bootstrap\SetRequestForConsole::class=>'SET_REQUEST_FOR_CONSOLE',
        \Illuminate\Foundation\Bootstrap\RegisterProviders::class=>'REGISTER_PROVIDERS',
        \Illuminate\Foundation\Bootstrap\BootProviders::class=>'BOOT_PROVIDERS',
    ] as $bootstrapper=>$marker) {
        $app->beforeBootstrapping($bootstrapper,static function() use (&$consoleStage,$marker):void {
            $consoleStage=$marker;
        });
    }
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class,static function() use (&$consoleStage):void {
        $consoleStage='AFTER_BOOT_PROVIDERS';
    });
    $consoleStage='PRE_BOOTSTRAPPERS';
    $console->bootstrap();
    echo "STAGE2_PORTAL_CONSOLE_BOOT_OK\n";
    $phase='CONFIG';
    $app['config']->set(['app.env'=>'staging','app.debug'=>false,'app.url'=>ORIGIN,'logging.default'=>'null',
        'portal_development_auth.enabled'=>false,'portal_onepage.enabled'=>false,'portal_onepage_d0.enabled'=>false,
        'database.default'=>'stage2_portal_admin']);
    $phase='BEFORE_DDL';
    $portal=slot($app,'stage2_portal_admin',PORTAL_DB,'postgres',$password,$receipt);
    $sites=slot($app,'stage2_sites_admin',SITES_DB,'postgres',$password,$receipt);
    unset($password);
    need($app['db']->connection()->getPdo()===$portal->pdo
        && $portal->pdo->query("SELECT to_regclass('public.portal_identities') IS NULL AND to_regnamespace('portal_browser_private') IS NULL")->fetchColumn()===true
        && $sites->pdo->query("SELECT to_regnamespace('sites_preview') IS NULL")->fetchColumn()===true
        && $portal->pdo->query("SELECT count(*)=0 FROM pg_roles WHERE rolname ~ '^(portal_browser_|sites_preview_stage2_)'")->fetchColumn()===true,'FRESH_BOTH_DBS');
    echo "PASS STAGE2_DUAL_DB_BEFORE_DDL\n";

    $phase='DDL_PRESTATE';
    foreach ([
        'DDL_MIG_000002'=>'2026_11_09_000002_create_portal_identity_foundation.php',
        'DDL_MIG_000003'=>'2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php',
        'DDL_MIG_000004'=>'2026_11_10_000004_add_org_revision_to_portal_sessions.php',
        'DDL_MIG_000005'=>'2026_11_10_000005_create_portal_auth_throttle_buckets.php',
        'DDL_MIG_000008'=>'2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php',
        'DDL_MIG_000012'=>'2026_11_11_000012_fence_portal_membership_revision.php',
        'DDL_MIG_000013'=>'2026_11_11_000013_fence_portal_organization_revision.php',
        'DDL_MIG_000014'=>'2026_11_11_000014_guard_portal_session_issuance.php',
        'DDL_MIG_000016'=>'2026_11_12_000016_fence_portal_identity_revision.php',
    ] as $step=>$name) {
        $phase=$step;
        $portal->assertSql();
        need($app['db']->connection()->getPdo()===$portal->pdo,'DDL_SAME_PDO');
        (require $base.'/database/migrations/'.$name)->up();
    }
    $phase='DDL_PORTAL_PRESTATE';
    [$fixtureFile,$appPassword,$identityPassword]=seedPortal($portal,$base,$root,$phase);
    $phase='DDL_PORTAL_PROJECTION';
    installProjection($portal,file_get_contents(__DIR__.'/stage2-fixture.sql'));
    $phase='DDL_SITES_PRESTATE';
    $sitesPasswords=seedSites($sites,$sitesRoot,$phase);
    $phase='DDL_RUNTIME_SLOTS';
    $authPdo=slot($app,'stage2_portal_projection',PORTAL_DB,'portal_browser_runtime',$identityPassword,$receipt);
    $author=slot($app,'stage2_sites_author',SITES_DB,AUTHOR_SQL,$sitesPasswords[AUTHOR_SQL],$receipt);
    $reviewer=slot($app,'stage2_sites_reviewer',SITES_DB,REVIEWER_SQL,$sitesPasswords[REVIEWER_SQL],$receipt);
    $readback=slot($app,'stage2_sites_readback',SITES_DB,REVIEWER_SQL,$sitesPasswords[REVIEWER_SQL],$receipt);
    unset($identityPassword,$sitesPasswords);
    echo "PASS STAGE2_NATIVE_FIXTURE\n";

    $phase='PORTAL_HTTP';
    $app['config']->set(['portal_development_auth.enabled'=>true,'portal_development_auth.organization'=>COMPANY,
        'portal_development_auth.secret_file'=>$fixtureFile,'session.driver'=>'portal_private_pg',
        'session.cookie'=>'__Host-imagine-portal-session','session.secure'=>true,'session.http_only'=>true,
        'session.domain'=>null,'session.path'=>'/','session.same_site'=>'strict','session.serialization'=>'json','session.encrypt'=>false]);
    $app->instance('encrypter',new Encrypter(random_bytes(32),'AES-256-CBC'));
    $provider=new PortalDevelopmentAuthServiceProvider($app);
    $provider->register();
    $provider->boot();
    $app['router']->getRoutes()->refreshNameLookups();
    $app['router']->getRoutes()->refreshActionLookups();
    $kernel=$app->make(HttpKernel::class);
    $newClient=static fn()=>new Client($app,$kernel);
    $pendingClient=$newClient();
    $authorClient=$newClient();
    $reviewerClient=$newClient();
    $revokeClient=$newClient();
    $pendingRequest=passwordStep($pendingClient,'author@example.invalid',$appPassword);
    passwordStep($authorClient,'author@example.invalid',$appPassword);
    [$authorRequest]=mfaStep($authorClient,$portal->pdo,AUTHOR);
    passwordStep($reviewerClient,'reviewer@example.invalid',$appPassword);
    [$reviewerRequest,$last]=mfaStep($reviewerClient,$portal->pdo,REVIEWER);
    passwordStep($revokeClient,'reviewer@example.invalid',$appPassword);
    [$revokeRequest]=mfaStep($revokeClient,$portal->pdo,REVIEWER,$last);
    unset($appPassword);
    $auth=$app->make(PortalBrowserAuthentication::class);
    need($auth instanceof PostgresPortalBrowserAuthentication
        && (int)$portal->pdo->query('SELECT count(*) FROM public.portal_browser_envelopes')->fetchColumn()>=4,'NATIVE_IDENTITY');
    echo "PASS STAGE2_PORTAL_NATIVE_MFA\n";

    $phase='SITES_NATIVE_WRITE';
    runCases($authPdo,$author,$reviewer,$readback,$auth,$pendingRequest,$authorRequest,$reviewerRequest,$revokeRequest,
        ['subject'=>AUTHOR,'company'=>COMPANY],['subject'=>REVIEWER,'company'=>COMPANY],PROJECT,
        new SiteBrief('onepage','Synthetic Stage Two','Synthetic preview','Laboratory content only','Read more',['Example']));
    echo "PASS STAGE2_EXACT_REVIEW_AND_ZERO_REFUSED_WRITES\n";
    $failure=false;
} catch(Throwable $e) {
    // Never print session material, DB diagnostics, synthetic passwords or raw errors.
    $ddl=str_starts_with($phase,'DDL_');
    $ddlReason='';
    if ($ddl) {
        // Laravel v13 QueryException extends PDOException and forwards the code.
        // Do not emit the exception string, SQL, binding values, or unlisted state.
        $state=$e instanceof \PDOException ? (string)$e->getCode() : null;
        $ddlReason=match($state) {
            null => ' NON_SQL_EXCEPTION',
            '0','' => ' SQLSTATE_UNAVAILABLE',
            '08001','08006','0A000','22001','22023','22P02','23502','23503','23505','23514',
            '25001','25P01','25P02','28000','28P01','2BP01','3D000','3F000',
            '42601','42501','42703','42704','42710','42804','42883','42P01','42P07',
            '55000','55P03','57014','58P01','XX000' => ' SQLSTATE_'.$state,
            default => ' SQLSTATE_OTHER',
        };
    }
    $known=!$ddl && !in_array($phase,['KERNEL_RESOLVE','CONSOLE_BOOT'],true) && preg_match('/\ASTAGE2_FIXTURE_REFUSED:[A-Z_]+\z/D',$e->getMessage())===1 ? ' '.$e->getMessage():'';
    $family=in_array($phase,['KERNEL_RESOLVE','CONSOLE_BOOT'],true) ? ' '.match(true) {
        $e instanceof \Illuminate\Contracts\Container\BindingResolutionException => 'CONTAINER_RESOLUTION',
        $e instanceof \PDOException => 'PDO_EXCEPTION',
        $e instanceof \ErrorException => 'PHP_ERROR_EXCEPTION',
        $e instanceof \TypeError => 'TYPE_ERROR',
        $e instanceof \Error => 'PHP_ERROR',
        $e instanceof \RuntimeException => 'RUNTIME_EXCEPTION',
        default => 'OTHER_THROWABLE',
    } : '';
    $stage=$phase==='CONSOLE_BOOT' ? ' '.($consoleStage??'PRE_BOOTSTRAPPERS'):'';
    fwrite(STDERR,'STAGE2_FAILED_'.$phase.$stage.$family.$ddlReason.$known."\n");
} finally {
    // Folder and cluster teardown are supervised independently, including abrupt exits.
    if ($fixtureFile!==null && is_file($fixtureFile) && !unlink($fixtureFile)) $failure=true;
}
exit($failure?1:0);
