<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\PostgresConnection;
use PDO;
use PrivateSharedIngress\SharedCatalog;
use RuntimeException;
use Throwable;

/** Exact #982 DDL only in a #590-qualified fresh inbox slot. No CREATE DATABASE or old gate. */
final class Stage3InboxSeed
{
    private const DB='portal_asaas_ci';
    private const FILES=[
        'inbox.sql'=>'292b23f7416e34e9b6f6b8ebb9dff6d056426f4b',
        'lease.sql'=>'03da8af90eca8733fb1842498fd4ac6b50fe8d7d',
        'shared-pre.sql'=>'021a55fd98fa4ccb89979c1c378577a1248b13df',
        'shared-functions.sql'=>'6e6c25ca897325f5a02dadaf9ee18335084039d1',
        'shared-post.sql'=>'ec9110841a4eddca69d15b25d992832405771c7d',
        'delta.sql'=>'3bc0c116185a5108ef93eec6b84d0071f1a507a8',
        'SharedCatalog.php'=>'0f539787138e7cc27afc2e8f6a53c19450837a3f',
    ];
    private const OPTIONS=[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_PERSISTENT=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>3,
    ];

    /** @return array{capture:PostgresConnection,worker:PostgresConnection} */
    public static function run(Stage3SupervisedSlots $slots,PostgresConnection $inbox,string $portalRoot):array {
        $phase='EXACT_SOURCES';
        try {
            self::need(realpath($portalRoot)===$portalRoot);
            $dir=$portalRoot.'/apps/portal/labs/onepage-dual-inbox';
            $original=[];
            foreach(self::FILES as $file=>$blob) {
                $path=$dir.'/'.$file;
                self::need(realpath($path)===$path && is_file($path));
                $data=file_get_contents($path,false,null,0,262145);
                self::need(is_string($data) && strlen($data)<262145
                    && sha1('blob '.strlen($data)."\0".$data)===$blob);
                $original[$file]=$data;
            }
            self::need(!class_exists(SharedCatalog::class,false));
            require $dir.'/SharedCatalog.php';
            $fragments=[];
            foreach(['pre.sql','functions.sql','post.sql'] as $file) $fragments[$file]=$original['shared-'.$file];
            $expected=SharedCatalog::expected($fragments);
            foreach(['inbox.sql','lease.sql'] as $f) {
                // Identical pinned #982 ServiceHarness PDO compatibility:
                // remove exactly its one psql ON_ERROR_STOP line; no other rewrite.
                self::need(substr_count($original[$f],"\\set ON_ERROR_STOP on\n")===1);
                $original[$f]=str_replace("\\set ON_ERROR_STOP on\n",'',$original[$f]);
                self::need(preg_match('/^\s*\\\\/m',$original[$f])===0);
            }
            foreach(array_keys(getenv()) as $name) self::need(!str_starts_with($name,'PG') && $name!=='DATABASE_URL');
            $pdo=$slots->beforeDdl(self::DB,$inbox);
            $gate=static fn():bool=>$slots->beforeDdl(self::DB,$inbox)===$pdo;
            $phase='FRESH_INBOX';
            self::need(self::one($pdo,<<<'SQL'
SELECT NOT EXISTS(SELECT 1 FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname NOT IN ('public','information_schema'))
 AND NOT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema')
 AND NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname='postgres' OR rolname ~ '^(asaas_|onepage_ingress_)')
SQL)===true);
            self::need($gate());
            $pdo->exec('CREATE ROLE postgres NOLOGIN NOSUPERUSER NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
            foreach(['inbox.sql','lease.sql'] as $f) {
                $phase=$f==='inbox.sql'?'NATIVE_INBOX':'NATIVE_LEASE';
                self::need($gate());$pdo->exec($original[$f]);self::need(!$pdo->inTransaction());
            }
            self::need(self::one($pdo,'SELECT count(*)::int FROM portal_psp.asaas_inbox')===0);
            $phase='NATIVE_SHARED_DDL';
            self::need($gate() && $pdo->beginTransaction());
            try {
                $pdo->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='3s'");
                foreach($fragments as $sql) $pdo->exec($sql);
                SharedCatalog::matches($expected,json_decode(self::one($pdo,$original['delta.sql']),true,64,JSON_THROW_ON_ERROR));
                self::audit($pdo);
                self::need($pdo->commit());
            } catch(Throwable) {
                if($pdo->inTransaction()) $pdo->rollBack();
                throw new RuntimeException();
            }

            $phase='RESTRICTED_SCRAM_CONNECTIONS';
            $anchor=self::identity($pdo);
            self::need($anchor['who']==='portal_native_test_bootstrap' && $anchor['actor']===$anchor['who']);
            self::need(PostgresConnection::getResolver('pgsql')===null);
            $factory=new ConnectionFactory(new Container());
            $named=['capture'=>['onepage_ingress_capture','stage3_inbox_capture',false],
                    'worker'=>['asaas_reconcile_worker','stage3_inbox_worker',true]];
            $out=[];$pids=[$anchor['pid']];
            foreach($named as $key=>[$role,$alias,$originalFixturePassword]) {
                self::need($gate() && self::one($pdo,<<<'SQL'
SELECT rolcanlogin AND (rolpassword IS NOT NULL)=?::boolean
 AND NOT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication)
 AND NOT EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid OR roleid=r.oid)
FROM pg_authid r WHERE rolname=?
SQL,[$originalFixturePassword?'true':'false',$role])===true);
                // #982 worker shipped only a historical fake SCRAM string and
                // capture shipped no password. Replace neither outside scratch.
                $password=bin2hex(random_bytes(32));
                self::need($pdo->beginTransaction());
                try {
                    $pdo->exec("SET LOCAL password_encryption='scram-sha-256'");
                    $pdo->exec('ALTER ROLE '.$role." PASSWORD '".$password."'");
                    self::need($pdo->commit());
                } catch(Throwable) {
                    if($pdo->inTransaction()) $pdo->rollBack();
                    throw new RuntimeException();
                }
                self::need($gate());
                $c=$factory->make(['driver'=>'pgsql','database'=>self::DB,'username'=>$role,
                    'host'=>'127.0.0.1','port'=>55483,'sslmode'=>'disable','charset'=>'utf8','prefix'=>'',
                    'search_path'=>'public','password'=>$password,'options'=>self::OPTIONS],$alias);
                unset($password);
                self::need($c::class===PostgresConnection::class && self::plain($c));
                $p=$c->getPdo();
                self::need(self::plain($c) && $c->getReadPdo()===$p);
                $r=self::identity($p);
                foreach(['db','server','client','port','start','oid'] as $field) self::need($r[$field]===$anchor[$field]);
                self::need($r['who']===$role && $r['actor']===$role && $r['unsafe']===false);
                $pids[]=$r['pid'];$out[$key]=$c;
            }
            self::need(count(array_unique($pids))===3 && $gate());
            self::audit($pdo);
            self::need(self::one($pdo,'SELECT count(*)::int FROM portal_psp.asaas_inbox')===0);
            fwrite(STDOUT,"STAGE3_PINNED_INBOX_SHARED_AND_ROLE_FENCES_OK\n");
            return $out;
        } catch(Throwable) {throw new RuntimeException('STAGE3_INBOX_SEED_REFUSED:'.$phase);}
    }

    private static function audit(PDO $p):void {
        $rows=$p->query(<<<'SQL'
SELECT rolname,rolcanlogin,rolsuper,rolinherit,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls
FROM pg_roles WHERE rolname IN ('onepage_ingress_owner','onepage_ingress_capture','onepage_ingress_revoker')
ORDER BY rolname
SQL)->fetchAll(PDO::FETCH_NUM);
        self::need($rows===[
            ['onepage_ingress_capture',true,false,false,false,false,false,false],
            ['onepage_ingress_owner',false,false,false,false,false,false,false],
            ['onepage_ingress_revoker',true,false,false,false,false,false,false],
        ]);
        self::need(self::one($p,<<<'SQL'
SELECT NOT EXISTS(SELECT 1 FROM pg_auth_members
 WHERE roleid IN (SELECT oid FROM pg_roles WHERE rolname LIKE 'onepage_ingress_%' OR rolname='asaas_reconcile_worker')
    OR member IN (SELECT oid FROM pg_roles WHERE rolname LIKE 'onepage_ingress_%' OR rolname='asaas_reconcile_worker'))
AND NOT EXISTS(SELECT 1 FROM (VALUES('onepage_ingress_capture'),('onepage_ingress_revoker')) r(n)
CROSS JOIN (VALUES('portal_psp.asaas_inbox'),('onepage_ingress_lab.admissions'),('onepage_ingress_lab.inbox')) t(n)
WHERE has_table_privilege(r.n,t.n,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
   OR has_any_column_privilege(r.n,t.n,'SELECT,INSERT,UPDATE,REFERENCES'))
AND has_table_privilege('asaas_reconcile_worker','portal_psp.asaas_inbox','SELECT')
AND NOT has_table_privilege('asaas_reconcile_worker','portal_psp.asaas_inbox','INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
AND (SELECT array_agg(attname::text ORDER BY attname::text) FROM pg_attribute
     WHERE attrelid='portal_psp.asaas_inbox'::regclass AND attnum>0 AND NOT attisdropped
     AND has_column_privilege('asaas_reconcile_worker',attrelid,attnum,'UPDATE'))
    =ARRAY['attempts','last_checked_at','lease_expires_at','lease_token','retry_after','state']::text[]
AND (SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='portal_psp.asaas_inbox'::regclass)
SQL)===true);
    }

    private static function plain(PostgresConnection $c):bool {
        return $c->getRawReadPdo()===null && $c->getRawDirectPdo()===null && $c->getDirectPdoConfig()===[];
    }

    private static function identity(PDO $p):array {
        self::need(!$p->inTransaction() && $p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            && $p->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false && $p->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
            && $p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)===false && $p->getAttribute(PDO::ATTR_PERSISTENT)===false);
        $q=$p->query(<<<'SQL'
SELECT current_database() AS db, session_user::text AS who,current_user::text AS actor,
       host(inet_server_addr()) AS server,host(inet_client_addr()) AS client,inet_server_port() AS port,
       extract(epoch FROM pg_postmaster_start_time())::text AS start,pg_backend_pid() AS pid,
       (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
       (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
        OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid OR roleid=r.oid)) AS unsafe
FROM pg_roles r WHERE rolname=current_user
SQL);
        self::need($q!==false);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);self::need(count($rows)===1);$r=$rows[0];
        self::need($r['db']===self::DB && $r['server']==='127.0.0.1' && $r['client']==='127.0.0.1' && $r['port']===55483
            && is_int($r['pid']) && $r['pid']>1);
        return $r;
    }

    private static function one(PDO $p,string $sql,array $args=[]):mixed {
        $q=$p->prepare($sql);self::need($q!==false && $q->execute($args));
        $r=$q->fetchAll(PDO::FETCH_NUM);self::need(count($r)===1 && count($r[0])===1);return $r[0][0];
    }
    private static function need(bool $ok):void {if(!$ok)throw new RuntimeException();}
}
