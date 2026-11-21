<?php
declare(strict_types=1);
namespace SitesStage3Fixture;
use App\Domain\Commerce\OnepageAcceptedOrder;
use Illuminate\Database\PostgresConnection;
use PDO;
use RuntimeException;
use Throwable;

/** Fixed test-only role/capability mirror of #954 native pair. No operational login. */
final class Stage3M2IntakeSlots
{
    private const PORTAL='portal_native_orders_ci';
    private const SITES='portal_sites_pair_ci';
    private const RECORDER='portal_onepage_intake_recorder';
    private const RECEIVER='portal_sites_pair_receiver';
    private const PORT=55483;
    private const OPT=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_PERSISTENT=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>3];

    /** @return array{receiver:PostgresConnection,ledger:PDO,tenant:string} */
    public static function prepare(Stage3SupervisedSlots $slots,PostgresConnection $portalAdmin,
        PostgresConnection $sitesAdmin,OnepageAcceptedOrder $order):array
    {
        $phase='BASELINE';
        try {
            if (PHP_SAPI!=='cli') self::stop();
            $p=$slots->beforeDdl(self::PORTAL,$portalAdmin);
            $s=$slots->beforeDdl(self::SITES,$sitesAdmin);
            $pa=self::id($p);$sa=self::id($s);
            if ($p===$s||$pa['db']!==self::PORTAL||$sa['db']!==self::SITES||$pa['pid']===$sa['pid']
                ||$pa['oid']===$sa['oid']||$pa['start']!==$sa['start']
                ||$p->query('SHOW hba_file')->fetchColumn()!==$s->query('SHOW hba_file')->fetchColumn()) self::stop();
            self::truth($p,<<<'SQL'
SELECT EXISTS(SELECT 1 FROM pg_catalog.pg_authid AS r WHERE rolname='portal_onepage_intake_recorder'
 AND NOT (rolcanlogin OR rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication)
 AND rolpassword IS NULL AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members WHERE member=r.oid OR roleid=r.oid))
 AND to_regprocedure('portal_onepage.record_pending_site_intake(jsonb)') IS NOT NULL
 AND NOT EXISTS(SELECT 1 FROM portal_onepage.site_intake_receipts)
 AND EXISTS(SELECT 1 FROM pg_catalog.pg_hba_file_rules WHERE type='host' AND auth_method='scram-sha-256')
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_hba_file_rules WHERE error IS NOT NULL OR
   (type LIKE 'host%' AND auth_method<>'scram-sha-256'))
SQL);
            self::truth($s,<<<'SQL'
SELECT to_regrole('portal_sites_pair_receiver') IS NULL
 AND to_regprocedure('sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)') IS NOT NULL
 AND NOT EXISTS(SELECT 1 FROM sites_core.tenant_accounts)
 AND NOT EXISTS(SELECT 1 FROM sites_core.db_login_bindings)
SQL);
            $q=$p->prepare("SELECT EXISTS(SELECT 1 FROM public.portal_organizations WHERE id=?::uuid AND reference=? AND status='active')");
            if (!$q->execute([$order->companyId,$order->companyReference])||$q->fetchColumn()!==true) self::stop();
            $phase='SITES_FIXTURE';
            $sitePw=bin2hex(random_bytes(32));$portalPw=bin2hex(random_bytes(32));
            if ($sitePw===$portalPw||$slots->beforeDdl(self::SITES,$sitesAdmin)!==$s||!$s->beginTransaction()) self::stop();
            try {
                $s->exec("SET LOCAL password_encryption='scram-sha-256'");
                $s->exec('CREATE ROLE portal_sites_pair_receiver LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT PASSWORD '.$s->quote($sitePw));
                $tenant=$s->query('SELECT pg_catalog.gen_random_uuid()::text')->fetchColumn();
                if (!is_string($tenant)||$tenant===$order->companyId||$tenant===$order->id) self::stop();
                self::one($s,'INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES(?::uuid,?)',[$tenant,$order->companyReference]);
                self::one($s,'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,active,can_receive_project,can_read) VALUES(?,?::uuid,true,true,false)',[self::RECEIVER,$tenant]);
                $s->exec('GRANT CONNECT ON DATABASE portal_sites_pair_ci TO portal_sites_pair_receiver');
                $s->exec('GRANT USAGE ON SCHEMA sites_core TO portal_sites_pair_receiver');
                $s->exec('GRANT EXECUTE ON FUNCTION sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text) TO portal_sites_pair_receiver');
                if (!$s->commit()) self::stop();
            } catch(Throwable) {
                if ($s->inTransaction()) $s->rollBack();
                self::stop();
            }
            $phase='PORTAL_FIXTURE';
            if ($slots->beforeDdl(self::PORTAL,$portalAdmin)!==$p||!$p->beginTransaction()) self::stop();
            try {
                $p->exec("SET LOCAL password_encryption='scram-sha-256'");
                $p->exec('ALTER ROLE portal_onepage_intake_recorder LOGIN PASSWORD '.$p->quote($portalPw));
                // EXECUTE/USAGE come solely from exact #954 DDL. Do not regrant.
                if (!$p->commit()) self::stop();
            } catch(Throwable) {
                if ($p->inTransaction()) $p->rollBack();
                self::stop();
            }
            $phase='RESTRICTED_CONNECTIONS';
            if ($slots->beforeDdl(self::PORTAL,$portalAdmin)!==$p||$slots->beforeDdl(self::SITES,$sitesAdmin)!==$s) self::stop();
            $raw=self::connect(self::SITES,self::RECEIVER,$sitePw);
            $ledger=self::connect(self::PORTAL,self::RECORDER,$portalPw);
            unset($sitePw,$portalPw);
            $sr=self::runtime($raw,$sa,self::RECEIVER);$pr=self::runtime($ledger,$pa,self::RECORDER);
            if (count(array_unique([$pa['pid'],$sa['pid'],$sr['pid'],$pr['pid']]))!==4) self::stop();
            return ['receiver'=>new PostgresConnection($raw,self::SITES,'',['driver'=>'pgsql']),'ledger'=>$ledger,'tenant'=>$tenant];
        } catch(Throwable) {
            // Partial writes across DBs mean discard this owned fixture, never retry in place.
            throw new RuntimeException('STAGE3_M2_INTAKE_SLOTS_REFUSED:'.$phase);
        }
    }

    private static function connect(string $db,string $role,#[\SensitiveParameter] string $pw):PDO
    {
        return new PDO('pgsql:host=127.0.0.1;port='.self::PORT.';dbname='.$db.';sslmode=disable',$role,$pw,self::OPT);
    }
    private static function runtime(PDO $p,array $a,string $role):array
    {
        $r=self::id($p);
        foreach(['db','oid','start','server','client','port'] as $key) if($r[$key]!==$a[$key]) self::stop();
        if ($r['login']!==$role||$r['actor']!==$role||$r['unsafe']!==false
            ||$p->getAttribute(PDO::ATTR_PERSISTENT)!==false||$p->getAttribute(PDO::ATTR_EMULATE_PREPARES)!==false) self::stop();
        return $r;
    }
    private static function id(PDO $p):array
    {
        $r=$p->query(<<<'SQL'
SELECT current_database() AS db,d.oid::text AS oid,session_user::text AS login,current_user::text AS actor,
 host(inet_server_addr()) AS server,host(inet_client_addr()) AS client,inet_server_port()::text AS port,
 pg_backend_pid()::text AS pid,pg_postmaster_start_time()::text AS start,
 (r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolinherit OR r.rolreplication
   OR EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.member=r.oid OR m.roleid=r.oid)) AS unsafe
FROM pg_catalog.pg_database AS d CROSS JOIN pg_catalog.pg_roles AS r
WHERE d.datname=current_database() AND r.rolname=current_user
SQL)->fetch(PDO::FETCH_ASSOC);
        if(!is_array($r)||$r['server']!=='127.0.0.1'||$r['client']!=='127.0.0.1'||$r['port']!==(string)self::PORT
            ||$p->inTransaction()) self::stop();
        return $r;
    }
    private static function truth(PDO $p,string $sql):void
    {
        if($p->query($sql)->fetchColumn()!==true) self::stop();
    }
    private static function one(PDO $p,string $sql,array $parameters):void
    {
        $q=$p->prepare($sql);
        if(!$q->execute($parameters)||$q->rowCount()!==1) self::stop();
    }
    private static function stop():never {throw new RuntimeException('INTAKE_SLOTS_REFUSED');}
}
