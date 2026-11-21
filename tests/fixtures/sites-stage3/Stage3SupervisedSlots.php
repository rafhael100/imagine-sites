<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use Illuminate\Database\PostgresConnection;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Test-only pre-DDL witnesses for three freshly supervised databases. No migration or role creation. */
final readonly class Stage3SupervisedSlots
{
    private const BOOTSTRAP='portal_native_test_bootstrap';
    private const SLOTS=[
        'portal_native_orders_ci'=>'stage3_native_admin',
        'portal_asaas_ci'=>'stage3_inbox_admin',
        'portal_sites_pair_ci'=>'stage3_sites_admin',
    ];
    private const TTL=180;
    private const OPTIONS=[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_PERSISTENT=>false,
        PDO::ATTR_STRINGIFY_FETCHES=>false,
        PDO::ATTR_TIMEOUT=>3,
    ];
    private const WITNESS_SQL=<<<'SQL'
SELECT pg_catalog.current_database() AS db,
       session_user::text AS login,current_user::text AS actor,
       pg_catalog.host(pg_catalog.inet_server_addr()) AS server_host,
       pg_catalog.host(pg_catalog.inet_client_addr()) AS client_host,
       pg_catalog.inet_server_port()::text AS port,
       pg_catalog.current_setting('server_version_num') AS pg_version,
       pg_catalog.current_setting('data_directory') AS data_dir,
       extract(epoch FROM pg_catalog.pg_postmaster_start_time())::text AS postmaster_start,
       (SELECT system_identifier::text FROM pg_catalog.pg_control_system()) AS system_id,
       (SELECT oid::text FROM pg_catalog.pg_database WHERE datname=pg_catalog.current_database()) AS oid,
       (SELECT rolsuper AND rolcanlogin FROM pg_catalog.pg_roles WHERE rolname=current_user) AS admin,
       pg_catalog.pg_is_in_recovery() AS recovering,
       pg_catalog.current_setting('transaction_read_only') AS read_only,
       pg_catalog.pg_backend_pid()::text AS backend_pid,
       (SELECT extract(epoch FROM backend_start)::text FROM pg_catalog.pg_stat_activity
        WHERE pid=pg_catalog.pg_backend_pid() AND backend_type='client backend') AS backend_start
SQL;

    private function __construct(private array $witness, private array $slots) {}

    /** The runner verification key/nonce MUST arrive independently from the envelope.
     * Signature: raw detached Ed25519, over exact compact UTF-8 JSON with no final newline.
     * @param array<string,PostgresConnection> $connections
     */
    public static function qualify(
        string $json, string $signature, string $trustedRunnerKey, string $trustedNonce, array $connections
    ): self {
        self::need(function_exists('sodium_crypto_sign_verify_detached')
            && strlen($signature)===64 && strlen($trustedRunnerKey)===32
            && strlen($json)>0 && strlen($json)<=2048
            && self::hex($trustedNonce,64), 'WITNESS_FORMAT');
        self::need(sodium_crypto_sign_verify_detached($signature,$json,$trustedRunnerKey), 'WITNESS_SIGNATURE');
        try {
            $w=json_decode($json,true,12,JSON_THROW_ON_ERROR);
            self::need(json_encode($w,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)===$json,'WITNESS_ENCODING');
        } catch(Throwable) {
            throw new RuntimeException('STAGE3_SLOT_REFUSED:WITNESS_ENCODING');
        }
        self::need(is_array($w) && array_keys($w)===[
            'schema','nonce','issued_at','netns','postmaster_pid','data_dir','port','pg_version','start','system_id','oids'
        ] && $w['schema']==='sites-stage3-supervisor-witness.v1' && is_string($w['nonce'])
            && hash_equals($trustedNonce,$w['nonce'])
            && is_int($w['issued_at']) && is_string($w['netns'])
            && preg_match('/\Anet:\[[1-9][0-9]*\]\z/D',$w['netns'])===1
            && is_int($w['postmaster_pid']) && $w['postmaster_pid']>1
            && is_string($w['data_dir']) && preg_match('~\A/tmp/sites-stage3-[a-zA-Z0-9]{8,32}/pgdata\z~D',$w['data_dir'])===1
            && is_int($w['port']) && $w['port']>=1024 && $w['port']<=65535
            && $w['pg_version']==='180007' && self::epoch($w['start'])
            && is_string($w['system_id']) && preg_match('/\A[1-9][0-9]{14,19}\z/D',$w['system_id'])===1
            && is_array($w['oids']) && array_keys($w['oids'])===array_keys(self::SLOTS),
            'WITNESS_SCOPE');
        foreach ($w['oids'] as $oid) self::need(self::positiveIntString($oid),'WITNESS_OIDS');
        self::need(count(array_unique($w['oids'],SORT_STRING))===3,'WITNESS_OIDS');
        self::live($w);

        // Close all effective Laravel configurations first; this step must not open a PDO.
        self::need(array_keys($connections)===array_keys(self::SLOTS),'CONNECTION_SET');
        $objects=[];
        foreach ($connections as $db=>$c) {
            self::need($c instanceof PostgresConnection && $c::class===PostgresConnection::class,'LARAVEL_CONNECTION');
            $objects[]=spl_object_id($c);
            self::configuration($c,$db,$w);
        }
        self::need(count(array_unique($objects))===3,'DISTINCT_CONNECTIONS');

        $slots=[];$pids=[];$pdos=[];
        foreach ($connections as $db=>$c) {
            [$pdo,$birth]=self::witness($c,$db,$w);
            $slots[$db]=['connection'=>$c,'pdo'=>$pdo,'birth'=>$birth];
            $pids[]=$birth['backend_pid'];$pdos[]=spl_object_id($pdo);
        }
        self::need(count(array_unique($pdos))===3 && count(array_unique($pids))===3,'DISTINCT_BACKENDS');
        return new self($w,$slots);
    }

    /** A caller may use only this original independently checked admin PDO, before opening its DDL transaction. */
    public function beforeDdl(string $database, PostgresConnection $connection): PDO
    {
        $known=$this->slots[$database]??null;
        self::need(is_array($known) && $known['connection']===$connection,'SLOT');
        self::live($this->witness);
        self::configuration($connection,$database,$this->witness);
        [$pdo,$birth]=self::witness($connection,$database,$this->witness,$known['pdo']);
        self::need($birth===$known['birth'],'BACKEND_CHANGED');
        return $pdo;
    }

    private static function configuration(PostgresConnection $c,string $db,array $w): void
    {
        try {
            self::need(self::noAlternatePdo($c),'LARAVEL_CONFIG');
            $actual=$c->getConfig();
            $exact=[
                'name'=>self::SLOTS[$db], 'driver'=>'pgsql','database'=>$db,'username'=>self::BOOTSTRAP,
                'host'=>'127.0.0.1','port'=>$w['port'],'sslmode'=>'disable','charset'=>'utf8',
                'prefix'=>'','search_path'=>'public',
            ];
            // Closed key set: no URL, socket, read/write split, alternate DSN route, or server options.
            $keys=[...array_keys($exact),'password','options'];
            self::need(is_array($actual) && count($actual)===count($keys)
                && array_diff(array_keys($actual),$keys)===[]
                && $c->getName()===self::SLOTS[$db] && $c->getDatabaseName()===$db
                && $c->transactionLevel()===0,'LARAVEL_CONFIG');
            foreach($exact as $k=>$v) self::need(($actual[$k]??null)===$v,'LARAVEL_CONFIG');
            self::need(self::hex($actual['password'],64) && is_array($actual['options'])
                && count($actual['options'])===count(self::OPTIONS),'LARAVEL_CONFIG');
            foreach(self::OPTIONS as $k=>$v) self::need(
                array_key_exists($k,$actual['options']) && $actual['options'][$k]===$v,'LARAVEL_CONFIG'
            );
        } catch(Throwable) {
            throw new RuntimeException('STAGE3_SLOT_REFUSED:LARAVEL_CONFIG');
        }
    }

    private static function noAlternatePdo(PostgresConnection $c):bool
    {
        // Raw getters do not invoke a lazy resolver. Direct is another target,
        // not Laravel's ordinary required raw primary/write PDO or closure.
        return $c->getRawReadPdo()===null && $c->getRawDirectPdo()===null
            && $c->getDirectPdoConfig()===[];
    }

    private static function witness(
        PostgresConnection $c,string $db,array $w,?PDO $expected=null
    ): array {
        $phase='PDO_OPTIONS';
        try {
            self::need(self::noAlternatePdo($c),'PDO_OPTIONS');
            $pdo=$c->getPdo();
            // The trusted primary resolver has returned; still do not let it
            // introduce an alternate resolver before checking getReadPdo().
            self::need(self::noAlternatePdo($c),'PDO_OPTIONS');
            self::need($pdo instanceof PDO && ($expected===null || $pdo===$expected)
                && $c->getReadPdo()===$pdo && !$pdo->inTransaction()
                && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
                && $pdo->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
                && $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false
                && $pdo->getAttribute(PDO::ATTR_PERSISTENT)===false
                && $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)===false,'PDO_OPTIONS');
            $phase='SQL_IDENTITY';
            $result=$pdo->query(self::WITNESS_SQL);
            $rows=$result===false?[]:$result->fetchAll(PDO::FETCH_ASSOC);
            self::need(count($rows)===1 && is_array($rows[0]),'SQL_IDENTITY');
            $r=$rows[0];
            $want=[
                'db'=>$db,'login'=>self::BOOTSTRAP,'actor'=>self::BOOTSTRAP,
                'server_host'=>'127.0.0.1','client_host'=>'127.0.0.1','port'=>(string)$w['port'],
                'pg_version'=>$w['pg_version'],'data_dir'=>$w['data_dir'],'postmaster_start'=>$w['start'],
                'system_id'=>$w['system_id'],'oid'=>$w['oids'][$db],'admin'=>true,'recovering'=>false,
                'read_only'=>'off',
            ];
            self::need(count($r)===count($want)+2,'SQL_IDENTITY');
            foreach($want as $k=>$v) self::need(($r[$k]??null)===$v,'SQL_IDENTITY');
            self::need(self::positiveIntString($r['backend_pid']??null)
                && $r['backend_pid']!==(string)$w['postmaster_pid']
                && self::epoch($r['backend_start']??null),'SQL_BACKEND');
            return [$pdo,['backend_pid'=>$r['backend_pid'],'backend_start'=>$r['backend_start']]];
        } catch(Throwable) {
            // Connection errors can contain DSNs/KV; output only a fixed stage.
            throw new RuntimeException('STAGE3_SLOT_REFUSED:'.$phase);
        }
    }

    private static function live(array $w):void {
        $now=time();
        self::need($now >= $w['issued_at']-2 && $now <= $w['issued_at']+self::TTL,'WITNESS_EXPIRED');
        self::need(@readlink('/proc/self/ns/net')===$w['netns'],'WITNESS_NAMESPACE');
    }

    private static function positiveIntString(mixed $x):bool {
        return is_string($x) && preg_match('/\A[1-9][0-9]{0,9}\z/D',$x)===1;
    }
    private static function epoch(mixed $x):bool {
        return is_string($x) && preg_match('/\A[1-9][0-9]*\.[0-9]{6}\z/D',$x)===1;
    }
    private static function hex(#[SensitiveParameter] mixed $x,int $n):bool {
        return is_string($x) && strlen($x)===$n && ctype_xdigit($x) && strtolower($x)===$x;
    }
    private static function need(bool $v,string $code):void {
        if(!$v) throw new RuntimeException('STAGE3_SLOT_REFUSED:'.$code);
    }

    public function __debugInfo():array {return ['scope'=>'three disposable stage3 admin slots'];}
    public function __serialize():array {throw new RuntimeException('STAGE3_SLOT_REFUSED:SERIALIZE');}
    public function __unserialize(array $unused):void {throw new RuntimeException('STAGE3_SLOT_REFUSED:SERIALIZE');}
    private function __clone():void {}
}
