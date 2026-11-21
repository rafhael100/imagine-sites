<?php
declare(strict_types=1);

namespace OnepageSitesTerminalDraft;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use App\Editorial\EditorialRevisionWriter;
use App\Integration\PortalBriefSnapshotRecorder;
use App\Integration\PortalProjectIntakeRecorder;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\BriefSnapshotInput;
use Imagine\Sites\Production\BriefRenderProvenance;
use Imagine\Sites\Publication\PgSiteReleaseRegistry;
use PDO;
use RuntimeException;
use Throwable;

final class IsolatedScenario
{
    private const ADMIN='sites_terminal_fixture_bootstrap';
    private const PATHS=[
        'apps/sites/database/migrations/2026_11_14_000001_create_sites_core_foundation.php'=>'40f8d64e7be6c7153410175c9f53c58e7f618fc4',
        'apps/sites/database/migrations/2026_11_14_000002_add_tenant_bound_read_api.php'=>'bda976c7386d334f6c6971e234dfd77441088d9e',
        'apps/sites/database/migrations/2026_11_14_000003_add_editorial_revision_api.php'=>'9d0725ece793e0a5be587b60dc6493efd5ca2128',
        'apps/sites/database/migrations/2026_11_17_000005_add_release_registry.php'=>'bf73498a3ecf6f7d29160539093cc50cd9b61005',
        'apps/sites/database/migrations/2026_11_17_000006_allow_editorial_reopen.php'=>'3d07f176261cc07a51c55e660b03fc5b0ec56509',
        'apps/sites/database/migrations/2026_11_18_000011_add_portal_project_intake.php'=>'6aac32b24398a69709b5588c0b408cf3941f2be8',
        'apps/sites/database/migrations/2026_11_18_000012_expand_release_registry_permission_guard.php'=>'45bd18f06b1f9da6f5d8fcf1cf2c296afec0e435',
        'apps/sites/database/migrations/2026_11_18_000013_add_portal_brief_snapshots.php'=>'ed5d9a95da610659a2359bed0ef93d1e7d16798e',
        'apps/sites/database/migrations/2026_11_18_000014_add_brief_artifact_provenance.php'=>'769f12af50e21a3db440222e312da9fc1b471ae0',
        'contracts/release-registry/001-registry.sql'=>'1f22900dcf48181ab895c6ccd21177df9e316c84',
        'contracts/editorial/002-reopen-reviewed-release.sql'=>'ad4d43594f6e715b24d3c1f498e8dc3379770615',
        'contracts/release-registry/004-expand-runtime-permissions.sql'=>'efa45152a9a78dc31bfebf769835e1e82b2edcfc',
        'contracts/portal-sites/001-project-intake.sql'=>'e738800eddcb963a52b883efb2e1f008c43278a7',
        'contracts/portal-sites/002-brief-snapshots.sql'=>'f8bf34fd8759ee863665096e0db7bc0885b4207f',
        'contracts/release-registry/005-brief-artifact-provenance.sql'=>'541270baae296cd72723b832e7650ff8fbb1c4c7',
        'contracts/portal-sites/candidates/brief-production-v1/examples/onepage-brief.synthetic.json'=>'3748c8fc543278839008ebb088df3c594b509772',
        'contracts/portal-sites/candidates/brief-production-v1/examples/render-input.synthetic.json'=>'f4243f74206fcabfcd8c1a2331e899afec005a51',
        'contracts/portal-sites/candidates/brief-production-v1/examples/rendered.synthetic.html'=>'975da5d1863c9629fd427948e346cff9a1afd195',
        'contracts/portal-sites/candidates/brief-production-v1/examples/brief-snapshot.synthetic.json'=>'f4b3e9b610e10fd69d81808a77ecd29d189ac63a',
        'contracts/portal-sites/candidates/brief-production-v1/examples/brief-render-plan.synthetic.json'=>'24150dcf7f33d671f66054ed05caa366820fa2ff',
    ];
    private const MODULES=[
      'Machine.php'=>'036a5fed115c75f0103a934f383690b664f80814081ce7996288f6933d83a9b2',
      'Race.php'=>'2910ae43b14582b5b14e91fa71c6bb2543bcff64481e1c1a0d597d1f1d48bc99',
      'schema.sql'=>'61f5a587b24d3d124596859a8411dd0d2b91a39d82a23e49894381050807c422',
    ];
    private const E='contracts/portal-sites/candidates/brief-production-v1/examples/';
    private const HTML_SHA='ab999fc8c61d4f22ab250c6d5d86442a0ceca24659d1f4173db3580e4694740e';

    public static function run(SitesTlsAdmission $gate,string $sites,PDO $writer,PDO $observer,string $run):bool
    {
        self::modules();
        foreach(self::PATHS as $path=>$pin) self::blob($sites.'/'.$path,$pin);
        self::need($gate->assertBootstrap($writer)!==$gate->assertBootstrap($observer));
        $identity=self::identity($observer,true);
        $identity['ca_sha']=hash_file('sha256',$run.'/tls/ca.pem');
        self::need($identity['dir']===$run.'/data');
        self::need(self::q($observer,
            "SELECT to_regnamespace('sites_core') IS NULL AND to_regnamespace('sites_onepage_terminal_candidate') IS NULL
                 AND NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname LIKE 'sites_core_%' OR rolname LIKE 'terminal_fixture_%')")
            ->fetchColumn()===true);

        require $sites.'/apps/sites/vendor/autoload.php';
        $app=new Application($sites.'/apps/sites');
        $connection=self::wrap($writer);
        $app->instance('db',new class($connection) {
            public function __construct(private PostgresConnection $db) {}
            public function connection():PostgresConnection {return $this->db;}
            public function __call(string $method,array $args): mixed {
                if (!in_array($method,['getDriverName','transaction','unprepared','select','selectOne','statement'],true)) throw new RuntimeException();
                return $this->db->{$method}(...$args);
            }
        });
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        foreach(self::PATHS as $path=>$pin) {
            if (!str_starts_with($path,'apps/sites/database/migrations/')) continue;
            $gate->assertBootstrap($writer);
            $m=require $sites.'/'.$path;
            self::need($m instanceof Migration);
            $m->up();
            self::need($connection->getPdo()===$writer);
        }
        $gate->assertBootstrap($writer);
        self::need($writer->exec(self::file(__DIR__.'/schema.sql'))!==false);
        $gate->assertBootstrap($writer);

        $tenant=self::uuid($writer);
        $org='terminal-fixture-org';
        self::q($writer,'INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES(?::uuid,?)',[$tenant,$org]);
        $map=[
          'intake'=>['sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)',null,null],
          'source'=>['sites_core.record_portal_brief_snapshot(text,text)','portal_brief_principals','receiver'],
          'artifact'=>['sites_core.record_brief_release_artifact(text,text,text)','release_registry_principals','artifact_writer'],
          'editor'=>['sites_core.advance_editorial_revision(uuid,integer,text,text)','editorial_operator_bindings','editor'],
          'reviewer'=>['sites_core.advance_editorial_revision(uuid,integer,text,text)','editorial_operator_bindings','reviewer'],
          'binding'=>['sites_core.bind_release_artifact(uuid,text,integer,bigint)','release_registry_principals','binding_writer'],
          'revoke'=>['sites_core.revoke_release_binding(uuid,bigint)','release_registry_principals','revoker'],
        ];
        $sessions=[];
        foreach($map as $key=>[$call,$table,$duty]) {
            $role='terminal_fixture_'.$key;
            $gate->assertBootstrap($writer);
            // One independent password per least-privilege fixture LOGIN.
            // Plaintext stays in memory solely for its matching connection.
            $password=bin2hex(random_bytes(32));
            try {
                $verifier=self::scram($password);
                try {
                    $writer->exec("CREATE ROLE $role LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD ".$writer->quote($verifier));
                } finally { sodium_memzero($verifier); }
                $writer->exec("GRANT CONNECT ON DATABASE postgres TO $role; GRANT USAGE ON SCHEMA sites_core TO $role; GRANT EXECUTE ON FUNCTION $call TO $role");
                if ($key==='intake') {
                    self::q($writer,'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_receive_project,active) VALUES(?,?::uuid,true,true)',[$role,$tenant]);
                } elseif ($table==='editorial_operator_bindings') {
                    self::q($writer,'INSERT INTO sites_core.editorial_operator_bindings(login_name,tenant_id,duty,active) VALUES(?,?::uuid,?,true)',[$role,$tenant,$duty]);
                } else {
                    self::q($writer,"INSERT INTO sites_core.$table(login_name,tenant_id,duty,active,authorization_epoch,expires_at)
                         VALUES(?,?::uuid,?,true,1,clock_timestamp()+interval '5 minutes')",[$role,$tenant,$duty]);
                }
                $sessions[$key]=self::connect($run,$role,$password,$identity,false);
            } finally { sodium_memzero($password); }
        }
        $order='terminal-fixture-order'; $contract='terminal-fixture-contract'; $release='terminal-fixture-release-a';
        $command=new SiteProjectCommand('1','terminal-fixture-intake-operation',$org,$order,$contract,1,'onepage','terminal-fixture-intake-key');
        $intake=(new PortalProjectIntakeRecorder(self::wrap($sessions['intake'])))->record($command);
        $site=$intake['siteRef'];
        foreach(['authorityVerified','clientAccepted','canPublish'] as $flag) self::need(($intake[$flag]??null)===false);
        $snap=self::uuid($writer);
        $source=self::file($sites.'/'.self::E.'onepage-brief.synthetic.json');
        $editorial=self::file($sites.'/'.self::E.'render-input.synthetic.json');
        $golden=self::file($sites.'/'.self::E.'rendered.synthetic.html');
        self::need(strlen($golden)===5223 && hash('sha256',$golden)===self::HTML_SHA);
        $manifest=self::decode(self::file($sites.'/'.self::E.'brief-snapshot.synthetic.json'));
        $manifest=array_replace($manifest,['tenantRef'=>$org,'siteRef'=>$site,'orderRef'=>$order,'contractRef'=>$contract,'snapshotRef'=>$snap]);
        $manifest['payload']['sizeBytes']=strlen($source); $manifest['payload']['sha256']=hash('sha256',$source);
        $input=BriefSnapshotInput::fromJson(self::json($manifest),$source);
        $snapReceipt=(new PortalBriefSnapshotRecorder(self::wrap($sessions['source'])))->record($input);
        self::need($snapReceipt['snapshotRef']===$snap && $snapReceipt['payloadSha256']===$input->payloadSha256);
        $plan=self::decode(self::file($sites.'/'.self::E.'brief-render-plan.synthetic.json'));
        $plan=array_replace($plan,['tenantRef'=>$org,'siteRef'=>$site,'releaseRef'=>$release]);
        $plan['snapshot']=['snapshotRef'=>$snap,'briefRevision'=>1,'manifestSha256'=>$input->manifestSha256];
        $plan['renderInput']['sizeBytes']=strlen($editorial); $plan['renderInput']['sha256']=hash('sha256',$editorial);
        $planBytes=self::json($plan);
        $render=BriefRenderProvenance::render($planBytes,$input->snapshotJson,$input->sourceJson,$editorial);
        self::need($render['artifact']->html===$golden);
        $reg=self::q($sessions['artifact'],'SELECT * FROM sites_core.record_brief_release_artifact(?::text,?::text,?::text)',
            [$planBytes,$editorial,$golden])->fetchAll(PDO::FETCH_ASSOC);
        self::need(count($reg)===1 && $reg[0]['artifact_digest']===self::HTML_SHA);
        $e=new EditorialRevisionWriter(self::wrap($sessions['editor']));
        $review=new EditorialRevisionWriter(self::wrap($sessions['reviewer']));
        self::need($e->transition($site,0,'register',self::HTML_SHA)['revision']===1);
        self::need($e->transition($site,1,'submit',self::HTML_SHA)['revision']===2);
        self::need($review->transition($site,2,'approve_internal',self::HTML_SHA)['state']==='awaiting_portal_acceptance');
        $binding=(new PgSiteReleaseRegistry($sessions['binding']))->bind($site,$release,3,0);
        self::need($binding->bindingRevision===1 && $binding->editorialRevision===3);
        $bytes=self::q($observer,'SELECT html FROM sites_core.release_artifacts WHERE tenant_id=?::uuid AND site_id=?::uuid AND release_ref=?',
            [$tenant,$site,$release])->fetchColumn();
        self::need($bytes===$golden);

        // Portal facts here are fictitious values. The D worker has no Eligible path.
        $intent=new Intent('terminal-fixture-operation-a',[
            'tenantRef'=>$org,'buyerRef'=>'fixture-buyer','orderRef'=>$order,'siteRef'=>$site,'contractRef'=>$contract,
            'snapshotRef'=>$snap,'releaseRef'=>$release,'presentationRef'=>'fixture-presentation-a','actionRef'=>'fixture-action-a',
            'commandDigest'=>$intake['commandDigest'],'manifestSha256'=>$input->manifestSha256,'htmlSha256'=>self::HTML_SHA,
            'sessionBinding'=>hash('sha256','non-secret fixture label'),'contractRevision'=>1,'sourceVersion'=>$input->sourceVersion,
            'briefRevision'=>1,'approvalGeneration'=>1,'editorialRevision'=>3,'bindingRevision'=>1,'sessionEpoch'=>1,
        ]);
        self::race($gate,$observer,$sessions['revoke'],$run,$tenant,$intent,$identity);
        return true;
    }

    private static function race(SitesTlsAdmission $gate,PDO $observer,PDO $revoker,string $run,string $tenant,Intent $intent,array $identity):void
    {
        $worker=null; $pipes=[];
        try {
            $start=static function(string $t,Intent $i)use($run,$identity,&$worker,&$pipes):array {
                $ini=php_ini_loaded_file();
                self::need(is_string($ini) && realpath($ini)===$ini);
                $worker=proc_open([PHP_BINARY,'-c',$ini,'-d','display_errors=0','-d','log_errors=0',
                    __FILE__,'--fixture-terminal-worker'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,
                    null,['PATH'=>'/usr/bin:/bin','LC_ALL'=>'C','HOME'=>$run,'TMPDIR'=>$run,'PHP_INI_SCAN_DIR'=>'']);
                self::need(is_resource($worker));
                $raw=self::json([$run,$t,$i->operationRef,$i->tuple,$identity,posix_getpid(),readlink('/proc/self/ns/net')]);
                self::need(strlen($raw)<4096 && fwrite($pipes[0],$raw)===strlen($raw));
                fclose($pipes[0]); unset($pipes[0]);
                stream_set_timeout($pipes[1],2);
                $line=fgets($pipes[1],101);
                self::need(is_string($line) && str_ends_with($line,"\n"));
                $pid=self::decode($line);
                return $pid;
            };
            $finish=static function(array $info)use(&$worker,&$pipes):array {
                $deadline=hrtime(true)+8_000_000_000;
                do { $status=proc_get_status($worker); if(!$status['running']) break; usleep(5000); }
                while(hrtime(true)<$deadline);
                self::need(!$status['running'] && $status['exitcode']===0);
                $stdout=stream_get_contents($pipes[1],257); $stderr=stream_get_contents($pipes[2],257);
                self::need(is_string($stdout) && strlen($stdout)<=256 && $stderr==='');
                foreach($pipes as $p) fclose($p);
                $pipes=[]; proc_close($worker); $worker=null;
                return self::decode($stdout);
            };
            RevokeFirstFixture::run($revoker,$observer,$tenant,$intent,$start,$finish,self::capture(...),
                static fn():PDO=>$gate->openBootstrap(true));
        } finally {
            if (is_resource($worker)) {
                proc_terminate($worker);
                foreach($pipes as $p) if(is_resource($p)) fclose($p);
                $end=hrtime(true)+1_000_000_000;
                do { $state=proc_get_status($worker); if(!$state['running']) break; usleep(5000); } while(hrtime(true)<$end);
                if($state['running']) proc_terminate($worker,9);
                proc_close($worker);
            }
        }
    }

    public static function worker():never {
        try {
            self::modules();
            $raw=stream_get_contents(STDIN,4097);
            self::need(is_string($raw) && strlen($raw)<4096);
            $payload=self::decode($raw);
            self::need(array_is_list($payload) && count($payload)===7);
            [$run,$tenant,$op,$tuple,$identity,$parent,$netns]=$payload;
            self::need(PHP_SAPI==='cli' && posix_geteuid()>0 && posix_getppid()===$parent &&
                readlink('/proc/self/ns/net')===$netns && posix_getuid()===posix_geteuid());
            $status=file_get_contents('/proc/self/status',false,null,0,65536);
            self::need(is_string($status) && preg_match('/^NoNewPrivs:\\s*1$/m',$status)===1);
            foreach(['Eff','Prm','Inh','Bnd','Amb'] as $cap) self::need(preg_match('/^Cap'.$cap.':\\s*0000000000000000$/m',$status)===1);
            // Only the isolated, identity-rechecking instrumentation worker
            // may use the segregated bootstrap credential, never a native fixture role.
            $password=self::file($run.'/password',65);
            self::need(preg_match('/\A[a-f0-9]{64}\z/D',$password)===1);
            $db=self::connect($run,self::ADMIN,$password,$identity,false);
            sodium_memzero($password);
            $result=RevokeFirstFixture::worker($db,$tenant,new Intent($op,$tuple),static function(int $pid):void {
                echo self::json(['pid'=>$pid]); flush();
            });
            echo self::json($result);
            exit(0);
        } catch(Throwable) {fwrite(STDERR,"ONEPAGE_TERMINAL_SCENARIO_WORKER_REFUSED\n");exit(1);}
    }

    private static function scram(#[\SensitiveParameter] string $password):string
    {
        $salt=random_bytes(16);
        $salted=hash_pbkdf2('sha256',$password,$salt,4096,32,true);
        try {
            $client=hash_hmac('sha256','Client Key',$salted,true);
            try {
                $stored=hash('sha256',$client,true);
                $server=hash_hmac('sha256','Server Key',$salted,true);
                try {
                    return 'SCRAM-SHA-256$4096:'.base64_encode($salt).'$'.base64_encode($stored).':'.base64_encode($server);
                } finally { sodium_memzero($stored); sodium_memzero($server); }
            } finally { sodium_memzero($client); }
        } finally { sodium_memzero($salted); }
    }

    private static function connect(string $run,string $role,#[\SensitiveParameter] string $password,array $expected,bool $readOnly):PDO {
        self::need(preg_match('~\A/tmp/sites-release-registry-[A-Za-z0-9]{8,32}\z~D',$run)===1 && realpath($run)===$run &&
            $expected['dir']===$run.'/data' && hash_file('sha256',$run.'/tls/ca.pem')===$expected['ca_sha']);
        $db=new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=verify-full;sslrootcert='.$run.
            '/tls/ca.pem;gssencmode=disable;connect_timeout=3',$role,$password,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
                PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_PERSISTENT=>false]);
        $a=self::identity($db,$role===self::ADMIN);
        foreach(['db','oid','start','ip','port','version','tls','native'] as $k) self::need($a[$k]===$expected[$k]);
        if($role!==self::ADMIN) self::need($a['login']===$role && $a['unsafe']===false);
        else self::need($a['login']===self::ADMIN && $a['dir']===$expected['dir'] && $a['system_id']===$expected['system_id']);
        $db->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET row_security=on;
            SET default_transaction_isolation='read committed'; SET statement_timeout='10s'; SET lock_timeout='3s'");
        $db->exec('SET default_transaction_read_only='.($readOnly?'on':'off'));
        return $db;
    }
    private static function identity(PDO $db,bool $admin):array {
        $sql="SELECT current_database() AS db,(SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
          current_user=session_user AS native,session_user::text AS login,current_setting('server_version_num') AS version,
          extract(epoch FROM pg_postmaster_start_time())::text AS start,host(inet_server_addr()) AS ip,inet_server_port() AS port,
          (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls,
          (SELECT rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb OR rolinherit OR rolreplication OR
             EXISTS (SELECT 1 FROM pg_auth_members WHERE member=r.oid OR roleid=r.oid) FROM pg_roles r WHERE rolname=current_user) AS unsafe";
        if($admin) $sql.=",current_setting('data_directory') AS dir,(SELECT system_identifier::text FROM pg_control_system()) AS system_id";
        $r=self::q($db,$sql)->fetch(PDO::FETCH_ASSOC);
        self::need(is_array($r) && $r['db']==='postgres' && $r['native']===true &&
            $r['ip']==='127.0.0.1' && $r['port']===55449 && $r['version']==='180007' && $r['tls']===true);
        return $r;
    }
    private static function capture(PDO $db):array {
        $out=[];
        foreach(self::q($db,"SELECT n.nspname,c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
          WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p') ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC) as $t){
            self::need(preg_match('/\A[a-z_][a-z_0-9]*\z/D',$t['relname'])===1);
            $k=$t['nspname'].'.'.$t['relname'];
            $out[$k]=self::q($db,"SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text FROM $k t")->fetchColumn();
        }
        return $out;
    }
    private static function wrap(PDO $pdo):PostgresConnection {
        $c=new PostgresConnection($pdo,'postgres','',['driver'=>'pgsql']);
        $c->setReconnector(static function():never{throw new RuntimeException();});
        return $c;
    }
    private static function modules():void {
        foreach(self::MODULES as $n=>$h) self::need(hash('sha256',self::file(__DIR__.'/'.$n,32768))===$h);
        require_once __DIR__.'/Machine.php'; require_once __DIR__.'/Race.php';
    }
    private static function blob(string $p,string $pin):void {
        $b=self::file($p); self::need(hash('sha1','blob '.strlen($b)."\0".$b)===$pin);
    }
    private static function file(string $p,int $limit=300000):string {
        self::need(realpath($p)===$p && is_file($p));
        $b=file_get_contents($p,false,null,0,$limit+1);
        self::need(is_string($b) && strlen($b)<=$limit);
        return $b;
    }
    private static function q(PDO $db,string $sql,array $p=[]):\PDOStatement {
        $q=$db->prepare($sql); self::need($q->execute($p)); return $q;
    }
    private static function uuid(PDO $db):string{return self::q($db,'SELECT gen_random_uuid()::text')->fetchColumn();}
    private static function json(array $a):string{return json_encode($a,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";}
    private static function decode(string $s):array{return json_decode($s,true,16,JSON_THROW_ON_ERROR);}
    private static function need(bool $ok):void {if(!$ok) throw new RuntimeException('ONEPAGE_TERMINAL_SCENARIO_REFUSED');}
}

if (($argv[1]??null)==='--fixture-terminal-worker') IsolatedScenario::worker();
return IsolatedScenario::run(...);
