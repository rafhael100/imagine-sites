<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use App\Domain\Commerce\OnepageAcceptedOrder;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\PostgresConnection;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Stage 3 Step 2 disposable fixture only: two scoped logins, never a product provisioning API. */
final class Stage3ExactBriefSlots
{
    private const DB='portal_sites_pair_ci';
    private const RECEIVER='stage3_brief_fixture_receiver';
    private const READER='stage3_brief_fixture_reader';
    private const RECORD='sites_core.record_portal_brief_snapshot(text,text)';
    private const READ='sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)';
    private const OPTIONS=[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_PERSISTENT=>false,PDO::ATTR_TIMEOUT=>3,
    ];

    /**
     * Call only after the exact #541 000012/000013 installer on existing #591
     * Sites and the original native pending-intake comparison. No credentials
     * supplied by the caller, and no Web/Portal/old intake connection is opened.
     * @return array{receiver:PostgresConnection,reader:PostgresConnection,openFreshReader:Closure}
     */
    public static function prepare(
        Stage3SupervisedSlots $slots, PostgresConnection $sitesAdmin, OnepageAcceptedOrder $order,
        string $siteTenant, string $siteRef
    ):array {
        $phase='TEMPORARY_SCOPE';
        try {
            self::need(PHP_SAPI==='cli' && $siteTenant!==$siteRef && $siteRef!==$order->id
                && $siteTenant!==$order->companyId);
            foreach([$siteTenant,$siteRef,$order->id] as $id)
                self::need(preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$id)===1);
            foreach(array_keys(getenv()) as $name) self::need(!str_starts_with($name,'PG') && $name!=='DATABASE_URL');
            $a=$slots->beforeDdl(self::DB,$sitesAdmin);
            $root=static fn():bool=>$slots->beforeDdl(self::DB,$sitesAdmin)===$a;
            $anchor=self::identity($a);
            self::need($anchor['session']==='portal_native_test_bootstrap' && $anchor['actor']===$anchor['session']);
            self::need(self::one($a,<<<'SQL'
SELECT count(*)::int AS n FROM sites_core.portal_project_intakes i
JOIN sites_core.site_projects p ON p.tenant_id=i.tenant_id AND p.site_id=i.site_id
WHERE i.tenant_id=?::uuid AND i.site_id=?::uuid AND i.portal_order_ref=? AND i.portal_org_ref=?
  AND i.contract_ref=? AND i.contract_revision=?::integer AND i.product_code='onepage'
  AND p.portal_order_ref=i.portal_order_ref AND p.command_digest=i.command_digest
  AND p.lifecycle_state='awaiting_official_authority' AND NOT p.authority_verified AND NOT p.publishable
SQL,[$siteTenant,$siteRef,$order->id,$order->companyReference,$order->contractRef,$order->contractRevision])===['n'=>1]);

            $phase='NATIVE_CATALOG_BEFORE_GRANTS';
            self::catalog($a);
            self::need(self::one($a,<<<'SQL'
SELECT NOT EXISTS(SELECT 1 FROM pg_catalog.pg_roles WHERE rolname IN (?,?))
 AND NOT EXISTS(SELECT 1 FROM sites_core.portal_brief_principals)
 AND NOT EXISTS(SELECT 1 FROM sites_core.portal_brief_snapshots) AS empty
SQL,[self::RECEIVER,self::READER])===['empty'=>true]);
            $roles=[
                'receiver'=>[self::RECEIVER,'stage3_snapshot_write',self::RECORD,'off',bin2hex(random_bytes(32))],
                'reader'=>[self::READER,'stage3_snapshot_read',self::READ,'on',bin2hex(random_bytes(32))],
            ];

            $phase='EXACT_FIXTURE_ROLES';
            self::need($root() && $a->beginTransaction());
            try {
                self::need($a->exec("SET LOCAL password_encryption='scram-sha-256'; SET LOCAL lock_timeout='1500ms'; SET LOCAL statement_timeout='5000ms'")!==false);
                foreach($roles as $duty=>[$role,$alias,$api,$readOnly,$password]) {
                    // Fixed names and exact granted endpoint only, following #519.
                    self::need($a->exec('CREATE ROLE '.$role.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.
                        $a->quote($password))!==false);
                    self::need($a->exec('GRANT CONNECT ON DATABASE '.self::DB.' TO '.$role)!==false);
                    self::need($a->exec('GRANT USAGE ON SCHEMA sites_core TO '.$role)!==false);
                    self::need($a->exec('GRANT EXECUTE ON FUNCTION '.$api.' TO '.$role)!==false);
                    $q=$a->prepare(<<<'SQL'
INSERT INTO sites_core.portal_brief_principals(login_name,tenant_id,duty,active,authorization_epoch,expires_at)
VALUES(?,?::uuid,?,true,1,pg_catalog.clock_timestamp()+interval '5 minutes')
SQL);
                    self::need($q!==false && $q->execute([$role,$siteTenant,$duty]) && $q->rowCount()===1);
                }
                unset($password);
                self::need($a->commit());
            } catch(Throwable) {
                if($a->inTransaction()) $a->rollBack();
                throw new RuntimeException();
            }
            self::need($root());
            self::catalog($a);
            $q=$a->prepare(<<<'SQL'
SELECT login_name::text AS login,tenant_id::text AS tenant,duty,active,authorization_epoch::text AS epoch,
       (expires_at>pg_catalog.clock_timestamp() AND expires_at<=pg_catalog.clock_timestamp()+interval '5 minutes' AND revoked_at IS NULL) AS fresh
FROM sites_core.portal_brief_principals
ORDER BY login_name::text
SQL);
            self::need($q!==false && $q->execute());
            self::need($q->fetchAll(PDO::FETCH_ASSOC)===[
                ['login'=>self::READER,'tenant'=>$siteTenant,'duty'=>'reader','active'=>true,'epoch'=>'1','fresh'=>true],
                ['login'=>self::RECEIVER,'tenant'=>$siteTenant,'duty'=>'receiver','active'=>true,'epoch'=>'1','fresh'=>true],
            ]);
            foreach($roles as [$role,$alias,$api]) self::acl($a,$role,$api);

            $phase='NATIVE_RESTRICTED_PDO';
            self::need(PostgresConnection::getResolver('pgsql')===null);
            $factory=new ConnectionFactory(new Container());
            $knownPids=[$anchor['pid']];
            $open=static function(array $spec)use($root,$anchor,$factory,&$knownPids):PostgresConnection {
                self::need($root());
                [$role,$alias,$api,$readOnly,$password]=$spec;
                $c=self::connect($factory,$role,$alias,$password,$readOnly);
                $r=self::identity($c->getPdo());
                foreach(['db','oid','start','client','server','port','version'] as $k) self::need($r[$k]===$anchor[$k]);
                self::need($r['session']===$role && $r['actor']===$role && !$r['unsafe']
                    && $r['default_ro']===$readOnly && $r['current_ro']===$readOnly
                    && !in_array($r['pid'],$knownPids,true));
                $knownPids[]=$r['pid'];
                return $c;
            };
            $receiver=$open($roles['receiver']);
            $reader=$open($roles['reader']);
            self::need($receiver!==$reader && $receiver->getPdo()!==$reader->getPdo());
            $readSpec=$roles['reader'];unset($roles);
            // This optional historical reread does not reset the principal,
            // grant or role. Reattest the original server before each new PDO.
            $openFreshReader=static fn():PostgresConnection=>$open($readSpec);
            fwrite(STDOUT,"STAGE3_SNAPSHOT_WRITER_READER_EXACT_ROLE_FENCES_OK\n");
            return ['receiver'=>$receiver,'reader'=>$reader,'openFreshReader'=>$openFreshReader];
        } catch(Throwable) {throw new RuntimeException('STAGE3_SNAPSHOT_SLOTS_REFUSED:'.$phase);}
    }

    private static function catalog(PDO $a):void {
        self::need(self::one($a,<<<'SQL'
SELECT (SELECT count(*)::int FROM pg_catalog.pg_roles r
        WHERE r.rolname IN ('sites_core_brief_owner','sites_core_brief_receiver','sites_core_brief_reader')
          AND NOT (rolcanlogin OR rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication)
          AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members WHERE member=r.oid OR roleid=r.oid))=3
 AND (SELECT count(*)::int FROM pg_catalog.pg_class c
      WHERE c.oid IN ('sites_core.portal_brief_principals'::regclass,'sites_core.portal_brief_snapshots'::regclass)
        AND c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity
        AND c.relowner='sites_core_brief_owner'::regrole)=2
 AND (SELECT count(*)::int FROM (VALUES
       ('sites_core.record_portal_brief_snapshot(text,text)'::regprocedure,'sites_core_brief_receiver'::regrole),
       ('sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)'::regprocedure,'sites_core_brief_reader'::regrole)
     ) v(fn, owner_role) JOIN pg_catalog.pg_proc p ON p.oid=v.fn
     WHERE p.proowner=v.owner_role AND p.prosecdef AND p.provolatile='v'
       AND p.proconfig=ARRAY['search_path=pg_catalog','row_security=on']
       AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
                      WHERE a.grantee=0))=2 AS sealed
SQL)===['sealed'=>true]);
    }

    private static function acl(PDO $a,string $role,string $api):void {
        $safe=self::one($a,<<<'SQL'
WITH fixture_role AS (SELECT * FROM pg_catalog.pg_roles WHERE rolname=?),
 allowed AS (SELECT pg_catalog.to_regprocedure(?) AS fn)
SELECT rolcanlogin AND NOT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members WHERE member=l.oid OR roleid=l.oid)
 AND pg_catalog.has_database_privilege(l.oid,pg_catalog.current_database(),'CONNECT')
 AND NOT pg_catalog.has_database_privilege(l.oid,pg_catalog.current_database(),'CREATE,CONNECT WITH GRANT OPTION')
 AND pg_catalog.has_schema_privilege(l.oid,'sites_core','USAGE')
 AND NOT pg_catalog.has_schema_privilege(l.oid,'sites_core','CREATE,USAGE WITH GRANT OPTION')
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_namespace n
   WHERE n.nspname IN ('sites_preview','sites_publication')
     AND pg_catalog.has_schema_privilege(l.oid,n.oid,'USAGE,CREATE'))
 AND pg_catalog.has_function_privilege(l.oid,allowed.fn,'EXECUTE')
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication')
     AND ((p.oid<>allowed.fn AND pg_catalog.has_function_privilege(l.oid,p.oid,'EXECUTE'))
       OR pg_catalog.has_function_privilege(l.oid,p.oid,'EXECUTE WITH GRANT OPTION')))
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname IN ('sites_core','sites_preview','sites_publication') AND
     (CASE WHEN c.relkind='S' THEN pg_catalog.has_sequence_privilege(l.oid,c.oid,'USAGE,SELECT,UPDATE')
       WHEN c.relkind IN ('r','p','v','m','f') THEN
         pg_catalog.has_table_privilege(l.oid,c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
         OR pg_catalog.has_any_column_privilege(l.oid,c.oid,'SELECT,INSERT,UPDATE,REFERENCES')
       ELSE false END)) AS sealed
FROM fixture_role l CROSS JOIN allowed
SQL,[$role,$api]);
        self::need($safe===['sealed'=>true]);
    }

    private static function connect(ConnectionFactory $factory,string $role,string $alias,
        #[SensitiveParameter] string $password,string $readOnly):PostgresConnection {
        $config=['driver'=>'pgsql','database'=>self::DB,'username'=>$role,
            'host'=>'127.0.0.1','port'=>55483,'sslmode'=>'disable','charset'=>'utf8','prefix'=>'',
            'search_path'=>'pg_catalog','password'=>$password,'options'=>self::OPTIONS];
        $c=$factory->make($config,$alias);
        $expected=$config+['name'=>$alias];
        $actual=$c->getConfig();
        self::need($c::class===PostgresConnection::class && $c->getName()===$alias
            && $c->getDatabaseName()===self::DB && $c->transactionLevel()===0 && self::plain($c)
            && count($actual)===count($expected));
        foreach($expected as $key=>$value) self::need(($actual[$key]??null)===$value);
        $p=$c->getPdo();
        self::need(self::plain($c) && $c->getReadPdo()===$p);
        $c->disableQueryLog();
        $c->setReconnector(static function():never {throw new RuntimeException();});
        self::need($p->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_isolation='read committed'; SET statement_timeout='5000ms'; SET lock_timeout='1500ms'")!==false);
        self::need($p->exec($readOnly==='on'?"SET default_transaction_read_only='on'":"SET default_transaction_read_only='off'")!==false);
        return $c;
    }

    private static function plain(PostgresConnection $c):bool {
        return $c->getRawReadPdo()===null && $c->getRawDirectPdo()===null && $c->getDirectPdoConfig()===[];
    }

    private static function identity(PDO $p):array {
        self::need(!$p->inTransaction() && $p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            && $p->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
            && $p->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false
            && $p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)===false && $p->getAttribute(PDO::ATTR_PERSISTENT)===false);
        $r=self::one($p,<<<'SQL'
SELECT pg_catalog.current_database() AS db,session_user::text AS session,current_user::text AS actor,
       pg_catalog.pg_backend_pid() AS pid,pg_catalog.host(pg_catalog.inet_server_addr()) AS server,
       pg_catalog.host(pg_catalog.inet_client_addr()) AS client,pg_catalog.inet_server_port() AS port,
       extract(epoch FROM pg_catalog.pg_postmaster_start_time())::text AS start,
       (SELECT oid::text FROM pg_catalog.pg_database WHERE datname=pg_catalog.current_database()) AS oid,
       pg_catalog.current_setting('server_version_num') AS version,
       pg_catalog.current_setting('server_encoding') AS server_encoding,
       pg_catalog.current_setting('client_encoding') AS client_encoding,
       pg_catalog.current_setting('role') AS role,pg_catalog.current_setting('transaction_isolation') AS isolation,
       pg_catalog.current_setting('default_transaction_read_only') AS default_ro,
       pg_catalog.current_setting('transaction_read_only') AS current_ro,
       (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
          OR EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members WHERE member=r.oid OR roleid=r.oid)) AS unsafe
FROM pg_catalog.pg_roles r WHERE rolname=session_user
SQL);
        self::need($r['db']===self::DB && $r['client']==='127.0.0.1' && $r['server']==='127.0.0.1'
            && $r['port']===55483 && $r['version']==='180007' && $r['role']==='none'
            && $r['server_encoding']==='UTF8' && $r['client_encoding']==='UTF8' && $r['isolation']==='read committed'
            && is_int($r['pid']) && $r['pid']>1 && $r['session']===$r['actor']);
        return $r;
    }

    private static function one(PDO $p,string $sql,array $args=[]):array {
        $q=$p->prepare($sql);
        self::need($q!==false && $q->execute($args));
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        self::need(count($rows)===1);
        return $rows[0];
    }
    private static function need(bool $ok):void {if(!$ok) throw new RuntimeException();}
}
