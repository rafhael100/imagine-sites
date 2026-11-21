<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\SitesIntegration\OnepageEntryPaymentTerms;
use App\Infrastructure\Commerce\PostgresOnepageEntryPayments;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\PostgresConnection;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Test-only prerequisite; no payment, queue, HTTP, browser, routes or operational bootstrap. */
final class Stage3PortalFinanceSeed
{
    private const DB='portal_native_orders_ci';
    private const ACCOUNT='native-entry-sandbox';
    private const PAYMENT='pay_ConfigFixture1';
    private const CUSTOMER='cus_ConfigFixture1';
    private const ROOT_BLOB_PREFIX='apps/portal/';
    private const SOURCE=[
        'database/security/portal_onepage_entry_payments.sql'=>'d2ed493ac1227f033092b2db72cf5bb1e324d837',
        'database/security/portal_onepage_d0_candidate.sql'=>'85f7a12dcf90d9319d1ced9300bda659cfec3c15',
        'database/security/portal_onepage_d0_account_epoch.sql'=>'a917cd4c7a486fb703b3a37bfe7a9ee68733804f',
        'labs/onepage-finance-integrated/finance.sql'=>'46ebfc9f9a756c8348f21791c7a2fc668b5da8f2',
        'labs/onepage-service-receipt-ci/service-authority.sql'=>'00b68a9abc16cce07f71229487a4e9c53b76ea24',
    ];
    private const ROLES=['portal_onepage_psp_registrar','onepage_finance_lab_verifier','onepage_d0_service_b'];

    /**
     * Depends on the independently pinned #982 Portal tree, witnessed #590
     * slots, exact native identity and order schema, and ONE real accepted
     * synthetic order from the initial MFA buyer. No substitute SQL/minted
     * order. Returns restricted native producer role PDOs, but no payment proof.
     * @return array{finance:PostgresConnection,service:PostgresConnection}
     */
    public static function prepare(
        Stage3SupervisedSlots $slots, PostgresConnection $admin,
        string $sourceRoot, PDO $runtime, OnepageAcceptedOrder $order, #[SensitiveParameter] string $old
    ):array {
        $stage='PINNED_SQL';
        try {
            self::need($sourceRoot===realpath($sourceRoot));
            $sql=[];
            foreach(self::SOURCE as $name=>$blob) {
                $path=$sourceRoot.'/'.self::ROOT_BLOB_PREFIX.$name;
                self::need(realpath($path)===$path && is_file($path));
                $raw=file_get_contents($path,false,null,0,262145);
                self::need(is_string($raw) && strlen($raw)<262145
                    && sha1('blob '.strlen($raw)."\0".$raw)===$blob);
                $sql[]=$raw;
            }
            self::need(is_array(getenv()));
            foreach(array_keys(getenv()) as $var) self::need(!str_starts_with($var,'PG') && $var!=='DATABASE_URL');
            $stage='ATTESTED_PORTAL';
            $db=$slots->beforeDdl(self::DB,$admin);
            $anchor=self::who($db);
            self::need($anchor['login']==='portal_native_test_bootstrap' && $anchor['actor']===$anchor['login']);
            $runtimeId=self::restricted($runtime,$anchor,'portal_onepage_runtime');
            $orders=new PostgresOnepageOrders($runtime,self::DB);
            $view=$orders->findOwnedAcceptedOrder($old,$order->id);
            self::need($view instanceof OnepageAcceptedOrder && get_object_vars($view)===get_object_vars($order)
                && $order->companyReference==='native-order-company');
            self::need(self::value($db,'SELECT count(*)::int FROM portal_onepage.pending_orders')===1);
            self::need(self::value($db,<<<'SQL'
SELECT (to_regclass('portal_onepage.entry_payment_bindings') IS NULL
    AND to_regclass('portal_onepage.entry_confirmations') IS NULL
    AND to_regnamespace('onepage_finance_lab') IS NULL
    AND to_regrole('portal_onepage_psp_registrar') IS NULL
    AND to_regrole('portal_onepage_d0_verifier') IS NULL
    AND to_regrole('onepage_finance_lab_verifier') IS NULL
    AND to_regrole('onepage_d0_service_b') IS NULL)
SQL)===true);

            $stage='NATIVE_SCHEMA';
            // Exact original #982 SQL, including its own per-script transactions.
            foreach($sql as $native) {
                self::need($db===$slots->beforeDdl(self::DB,$admin));
                $db->exec($native);
                self::need(!$db->inTransaction());
            }
            self::need(self::value($db,"SELECT portal_onepage.d0_account_gate() IS NULL")===true);
            $stage='DISPOSABLE_SYNTHETIC_POLICY';
            self::need($db===$slots->beforeDdl(self::DB,$admin));
            // Same closed synthetic literal as #982 ServiceBindingFixture::prepare.
            $db->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION portal_onepage.d0_account_gate()
RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT 'native-entry-sandbox'::text $$
SQL);
            $account=$db->prepare("INSERT INTO portal_onepage.payment_accounts(account_ref,environment,active,configuration_ref) VALUES(?,'sandbox',true,'cfg_reader_00001')");
            self::need($account->execute([self::ACCOUNT]) && $account->rowCount()===1);

            $stage='TEST_ONLY_RESTRICTED_LOGINS';
            $ports=[];$connections=[];$pids=[$anchor['pid'],$runtimeId];
            $factory=new ConnectionFactory(new Container());
            self::need(PostgresConnection::getResolver('pgsql')===null);
            $aliases=[self::ROLES[0]=>'stage3_native_registrar',self::ROLES[1]=>'stage3_native_finance',self::ROLES[2]=>'stage3_native_service'];
            foreach(self::ROLES as $role) {
                self::need($db===$slots->beforeDdl(self::DB,$admin));
                // Role is an exact constant from the #982 SQL. Validate the
                // exact existing role, including the original passwordless LOGIN
                // on service-B (the closed runner requires SCRAM), before setting
                // an ephemeral SCRAM login secret. No grants or memberships.
                self::need(self::value($db,<<<'SQL'
SELECT rolcanlogin=?::boolean AND rolpassword IS NULL
  AND NOT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
    OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid OR roleid=r.oid))
FROM pg_authid r WHERE rolname=?
SQL,[$role==='onepage_d0_service_b'?'true':'false',$role])===true);
                $password=bin2hex(random_bytes(32));
                self::need($db->beginTransaction());
                try {
                    $db->exec("SET LOCAL password_encryption='scram-sha-256'");
                    $db->exec("ALTER ROLE ".$role." LOGIN PASSWORD '".$password."'");
                    self::need($db->commit());
                } catch(Throwable) {
                    if($db->inTransaction()) $db->rollBack();
                    throw new RuntimeException();
                }
                self::need($db===$slots->beforeDdl(self::DB,$admin));
                $c=$factory->make([
                    'driver'=>'pgsql','database'=>self::DB,'username'=>$role,
                    'host'=>'127.0.0.1','port'=>55483,'sslmode'=>'disable','charset'=>'utf8',
                    'prefix'=>'','search_path'=>'public','password'=>$password,
                    'options'=>[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
                        PDO::ATTR_PERSISTENT=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>3],
                ],$aliases[$role]);
                unset($password);
                self::need($c::class===PostgresConnection::class && $c->getRawReadPdo()===null
                    && $c->getRawDirectPdo()===null && $c->getDirectPdoConfig()===[]);
                $pdo=$c->getPdo();
                self::need($c->getRawReadPdo()===null && $c->getRawDirectPdo()===null && $c->getReadPdo()===$pdo);
                $pids[]=self::restricted($pdo,$anchor,$role);
                $ports[$role]=$pdo;
                $connections[$role]=$c;
            }
            self::need(count(array_unique($pids))===5);
            self::need(self::value($db,"SELECT NOT rolcanlogin FROM pg_roles WHERE rolname='portal_onepage_d0_verifier'")===true);
            $stage='NATIVE_REGISTRAR';
            $reg=new PostgresOnepageEntryPayments($ports['portal_onepage_psp_registrar'],self::DB,self::ACCOUNT);
            self::need($reg->registerCustomer($order->id,self::CUSTOMER)==='recorded');
            self::need($reg->registerEntry($order->id,self::PAYMENT,self::CUSTOMER,
                OnepageEntryPaymentTerms::reference($order,self::ACCOUNT,self::CUSTOMER),
                33000,OnepageEntryPaymentTerms::schedule($order)[0]['due_date'],'PIX')==='recorded');
            self::need(self::value($db,<<<'SQL'
SELECT (SELECT count(*) FROM portal_onepage.entry_payment_bindings)=1
   AND (SELECT count(*) FROM portal_onepage.entry_confirmations)=0
   AND (SELECT count(*) FROM onepage_finance_lab.observations)=0
   AND EXISTS(SELECT 1 FROM portal_onepage.entry_payment_bindings
              WHERE order_id=?::uuid AND account_ref=? AND payment_id=? AND customer_id=?)
SQL,[$order->id,self::ACCOUNT,self::PAYMENT,self::CUSTOMER])===true);
            fwrite(STDOUT,"STAGE3_NATIVE_FINANCE_SEED_ONE_BINDING_ZERO_EFFECTS_OK\n");
            return ['finance'=>$connections['onepage_finance_lab_verifier'],'service'=>$connections['onepage_d0_service_b']];
        } catch(Throwable) {
            throw new RuntimeException('STAGE3_FINANCE_SEED_REFUSED:'.$stage);
        }
    }

    /** After real Consumer/confirmation: issue a new native business session, no reset of TOTP replay. */
    public static function renewBuyer(
        Stage3SupervisedSlots $slots,PostgresConnection $admin,
        PostgresPortalBrowserAuthentication $auth, PDO $runtime,
        OnepageAcceptedOrder $order, #[SensitiveParameter] string $old
    ):string {
        try {
            $db=$slots->beforeDdl(self::DB,$admin);
            self::restricted($runtime,self::who($db),'portal_onepage_runtime');
            self::need(!$auth->isActiveBusinessSession($old));
            self::need(self::value($db,<<<'SQL'
SELECT count(*)::int FROM portal_onepage.entry_confirmations e
JOIN onepage_finance_lab.observations f USING(order_id,account_ref,environment,payment_id,evidence_sha256)
WHERE e.order_id=?::uuid AND e.account_ref=? AND e.environment='sandbox' AND e.payment_id=?
SQL,[$order->id,self::ACCOUNT,self::PAYMENT])===1);
            $pending=$auth->beginPassword('onepage-own@example.invalid','native-order-fixture-password',$order->companyId,'192.0.2.10');
            self::need(is_string($pending) && !$auth->isActiveBusinessSession($pending));
            $counter=static fn():int=>(int)self::value($db,'SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint');
            $initial=$counter();$deadline=hrtime(true)+32_000_000_000;
            do { usleep(200000);$step=$counter(); } while($step<=$initial && hrtime(true)<$deadline);
            self::need($step>$initial && $db===$slots->beforeDdl(self::DB,$admin));
            $h=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),'12345678901234567890',true);
            $off=ord($h[19])&15;
            $code=str_pad((string)((unpack('N',substr($h,$off,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
            $fresh=$auth->completeMfa($pending,$code,'192.0.2.10');
            self::need(is_string($fresh) && $fresh!==$old && $fresh!==$pending && $auth->isActiveBusinessSession($fresh));
            $view=(new PostgresOnepageOrders($runtime,self::DB))->findOwnedAcceptedOrder($fresh,$order->id);
            self::need($view instanceof OnepageAcceptedOrder && get_object_vars($view)===get_object_vars($order));
            fwrite(STDOUT,"STAGE3_SAME_BUYER_NATIVE_MFA_FRESH_OK\n");
            return $fresh;
        } catch(Throwable) { throw new RuntimeException('STAGE3_FINANCE_SEED_REFUSED:FRESH_MFA'); }
    }

    private static function who(PDO $p):array {
        self::need(!$p->inTransaction() && $p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            && $p->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
            && $p->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false
            && $p->getAttribute(PDO::ATTR_PERSISTENT)===false
            && $p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)===false);
        $q=$p->query(<<<'SQL'
SELECT current_database() AS db,session_user::text AS login,current_user::text AS actor, pg_backend_pid() AS pid,
       host(inet_server_addr()) AS host,host(inet_client_addr()) AS client,inet_server_port() AS port,
       extract(epoch FROM pg_postmaster_start_time())::text AS start,
       (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
       current_setting('transaction_isolation') AS isolation,current_setting('transaction_read_only') AS read_only,
       (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
        OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid OR roleid=r.oid)) AS unsafe
FROM pg_roles r WHERE rolname=current_user
SQL);
        self::need($q!==false);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);self::need(count($rows)===1);$r=$rows[0];
        self::need($r['db']===self::DB && $r['host']==='127.0.0.1' && $r['client']==='127.0.0.1'
            && $r['port']===55483 && $r['isolation']==='read committed' && $r['read_only']==='off'
            && is_int($r['pid']) && $r['pid']>1);
        return $r;
    }

    private static function restricted(PDO $p,array $anchor,string $role):int {
        $identity=self::who($p);
        foreach(['db','host','client','port','start','oid'] as $k) self::need($identity[$k]===$anchor[$k]);
        self::need($identity['login']===$role && $identity['actor']===$role && $identity['unsafe']===false);
        return $identity['pid'];
    }

    private static function value(PDO $p,string $sql,array $args=[]):mixed {
        $q=$p->prepare($sql);self::need($q!==false && $q->execute($args));
        $rows=$q->fetchAll(PDO::FETCH_NUM);self::need(count($rows)===1 && count($rows[0])===1);
        return $rows[0][0];
    }
    private static function need(bool $ok):void {if(!$ok) throw new RuntimeException();}
}
