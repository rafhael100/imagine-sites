<?php
declare(strict_types=1);

namespace OnepageSitesTerminalDraft;

use PDO;
use RuntimeException;
use SensitiveParameter;
use SitesStage3Fixture\Stage3ReceiptChannel;
use Throwable;

/** Admission component only: no DDL, seed, network outside the receipt, or role minting. */
final readonly class SitesTlsAdmission
{
    private const PORT=55449;
    private const ADMIN='sites_terminal_fixture_bootstrap';
    private const SCHEMA='sites-onepage-terminal-tls-witness.v1';
    private const IDENTITY=<<<'SQL'
SELECT current_database() AS db, session_user::text AS login, current_user::text AS actor,
       host(inet_server_addr()) AS host, host(inet_client_addr()) AS client, inet_server_port() AS port,
       current_setting('listen_addresses') AS listen, current_setting('data_directory') AS dir,
       current_setting('server_version_num') AS version, current_setting('server_encoding') AS encoding,
       current_setting('role') AS role, current_setting('transaction_read_only') AS readonly,
       (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
       (SELECT system_identifier::text FROM pg_control_system()) AS system_id,
       extract(epoch FROM pg_postmaster_start_time())::text AS started,
       (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls,
       (SELECT rolsuper FROM pg_roles WHERE rolname=current_user) AS admin,
       pg_is_in_recovery() AS recovery, pg_backend_pid() AS backend,
       current_setting('client_encoding') AS client_encoding
SQL;

    private \WeakMap $opened;
    private function __construct(private string $run, private array $receipt) { $this->opened=new \WeakMap(); }

    public static function receive(string $run,string $outerNamespace,string $control,string $body):self
    {
        self::need(PHP_SAPI==='cli' && PHP_INT_SIZE===8 && posix_getuid()===posix_geteuid() && posix_geteuid()>0);
        $status=file_get_contents('/proc/self/status',false,null,0,65536);
        self::need(is_string($status) && preg_match('/^NoNewPrivs:\s*1$/m',$status)===1);
        foreach (['Eff','Prm','Inh','Bnd','Amb'] as $cap) self::need(
            preg_match('/^Cap'.$cap.':\s*0000000000000000$/m',$status)===1);
        self::need(preg_match('~\A/tmp/sites-release-registry-[A-Za-z0-9]{8,32}\z~D',$run)===1);
        self::file($run,0040700);
        foreach (array_keys(getenv()) as $env) self::need(!str_starts_with($env,'PG') &&
            !str_starts_with($env,'GITHUB_') && $env!=='DATABASE_URL');
        self::need($control!==$body && self::namespace($outerNamespace));
        $r=(new Stage3ReceiptChannel())->receive(self::pipe($control),self::pipe($body));
        self::need(sodium_crypto_sign_verify_detached($r['signature'],$r['json'],$r['trustedRunnerKey']));
        $w=json_decode($r['json'],true,12,JSON_THROW_ON_ERROR);
        self::need(is_array($w) && json_encode($w,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)===$r['json'] &&
            array_keys($w)===['schema','nonce','issued_at','netns','postmaster_pid','data_dir','port','ca_sha256',
                'pg_version','start','system_id','oids'] && $w['schema']===self::SCHEMA &&
            is_string($w['nonce']) && hash_equals($r['trustedNonce'],$w['nonce']) &&
            is_int($w['issued_at']) && self::namespace($w['netns']) && $w['netns']!==$outerNamespace &&
            is_int($w['postmaster_pid']) && $w['postmaster_pid']>1 && $w['data_dir']===$run.'/data' &&
            $w['port']===self::PORT && $w['pg_version']==='180007' &&
            self::match('/\A[a-f0-9]{64}\z/D',$w['ca_sha256']) &&
            self::match('/\A[1-9][0-9]*\.[0-9]{6}\z/D',$w['start']) &&
            self::match('/\A[1-9][0-9]{14,19}\z/D',$w['system_id']) &&
            is_array($w['oids']) && array_keys($w['oids'])===['postgres'] &&
            self::match('/\A[1-9][0-9]{0,9}\z/D',$w['oids']['postgres']));
        $self=new self($run,$w);
        $self->live();
        return $self;
    }

    public function openBootstrap(bool $readOnly): PDO
    {
        $this->live();
        $ca=$this->run.'/tls/ca.pem';
        self::file($this->run.'/tls',0040700);
        $st=self::file($ca,0100600);
        self::need($st['size']>=128 && $st['size']<=8192 && hash_file('sha256',$ca)===$this->receipt['ca_sha256']);
        $path=$this->run.'/password';
        self::need(self::file($path,0100600)['size']===64);
        $pw=file_get_contents($path,false,null,0,65);
        self::need(self::match('/\A[a-f0-9]{64}\z/D',$pw));
        try {
            $pdo=self::connect($ca,$pw);
        } finally {
            sodium_memzero($pw);
        }
        $this->opened[$pdo]=true;
        $this->assertBootstrap($pdo);
        $pdo->exec('SET search_path=pg_catalog; SET client_encoding=\'UTF8\'; SET row_security=on; '.
            'SET default_transaction_isolation=\'read committed\'; SET statement_timeout=\'10s\'; SET lock_timeout=\'3s\'');
        $pdo->exec($readOnly ? 'SET default_transaction_read_only=on' : 'SET default_transaction_read_only=off');
        $this->assertBootstrap($pdo);
        return $pdo;
    }

    private static function connect(string $ca,#[SensitiveParameter] string $pw):PDO
    {
        return new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=verify-full;sslrootcert='.$ca.
            ';gssencmode=disable;connect_timeout=3',self::ADMIN,$pw,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
                PDO::ATTR_PERSISTENT=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>3]);
    }

    /** Revalidate the same PDO immediately before exact admitted native/fixture DDL. */
    public function assertBootstrap(PDO $pdo):int
    {
        $this->live();
        self::need(isset($this->opened[$pdo]) && $pdo::class===PDO::class && !$pdo->inTransaction() &&
            $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql' &&
            $pdo->getAttribute(PDO::ATTR_PERSISTENT)===false &&
            $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false &&
            $pdo->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION);
        self::need($pdo->beginTransaction());
        try {
            $pdo->exec('SET TRANSACTION READ ONLY; SET LOCAL statement_timeout=3000; SET LOCAL search_path=pg_catalog');
            $r=$pdo->query(self::IDENTITY)->fetchAll(PDO::FETCH_ASSOC);
            $expected=['db'=>'postgres','login'=>self::ADMIN,'actor'=>self::ADMIN,'host'=>'127.0.0.1',
                'client'=>'127.0.0.1','port'=>self::PORT,'listen'=>'127.0.0.1','dir'=>$this->receipt['data_dir'],
                'version'=>'180007','encoding'=>'UTF8','client_encoding'=>'UTF8','role'=>'none','readonly'=>'on','oid'=>$this->receipt['oids']['postgres'],
                'system_id'=>$this->receipt['system_id'],'started'=>$this->receipt['start'],'tls'=>true,
                'admin'=>true,'recovery'=>false];
            self::need(count($r)===1 && count($r[0])===count($expected)+1);
            foreach ($expected as $key=>$value) self::need(($r[0][$key]??null)===$value);
            $pid=$r[0]['backend'];
            self::need(is_int($pid) && $pid>1 && $pid!==$this->receipt['postmaster_pid'] && $pdo->commit());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new RuntimeException('SITES_TERMINAL_TLS_IDENTITY_REFUSED');
        }
        $this->live();
        return $pid;
    }

    private function live():void
    {
        $now=time();
        self::need($now >= $this->receipt['issued_at']-2 && $now <= $this->receipt['issued_at']+120 &&
            readlink('/proc/self/ns/net')===$this->receipt['netns']);
    }
    private static function pipe(string $fd)
    {
        self::need(self::match('/\A(?:[3-9]|[1-9][0-9]{1,5})\z/D',$fd));
        $p='/proc/self/fd/'.$fd;
        $what=readlink($p);
        self::need(is_string($what) && preg_match('/\Apipe:\[([1-9][0-9]*)\]\z/D',$what,$ino)===1);
        $info=file_get_contents('/proc/self/fdinfo/'.$fd,false,null,0,1024);
        self::need(is_string($info) && preg_match('/^flags:\s*([0-7]{1,12})$/m',$info,$flags)===1 &&
            (octdec($flags[1])&3)===0);
        $h=fopen('php://fd/'.$fd,'rb'); self::need(is_resource($h));
        $s=fstat($h);
        self::need(is_array($s) && ($s['mode']&0170000)===0010000 && $s['uid']===0 && (string)$s['ino']===$ino[1]);
        return $h;
    }
    private static function file(string $path,int $mode):array
    {
        $s=lstat($path);
        self::need(realpath($path)===$path && is_array($s) && ($s['mode']&0177777)===$mode &&
            $s['uid']===posix_geteuid() && $s['gid']===posix_getegid());
        return $s;
    }
    private static function namespace(mixed $s):bool {return self::match('/\Anet:\[[1-9][0-9]*\]\z/D',$s);}
    private static function match(string $p,mixed $s):bool {return is_string($s) && preg_match($p,$s)===1;}
    private static function need(bool $ok):void {if(!$ok) throw new RuntimeException('SITES_TERMINAL_TLS_ADMISSION_REFUSED');}
}
