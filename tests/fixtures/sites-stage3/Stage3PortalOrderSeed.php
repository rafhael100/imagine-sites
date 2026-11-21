<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Closure;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Throwable;

/** Initial #982 synthetic order and first real Portal MFA. Never app/runtime code. */
final class Stage3PortalOrderSeed {
    private const DB='portal_native_orders_ci';
    private const ADMIN='portal_native_test_bootstrap';
    private const ALIAS='stage3_native_admin';
    private const ORG='native-order-company';
    private const EMAIL='onepage-own@example.invalid';
    private const IP='192.0.2.10';
    private const FIXTURE_PASSWORD='native-order-fixture-password';
    private const TOTP_BASE32='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private const TOTP_RAW='12345678901234567890';
    private const PINS=[
        'database/migrations/2026_11_09_000002_create_portal_identity_foundation.php'=>'f952bd64cb51be2ea18122ec232f6862bc1d522a',
        'database/migrations/2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php'=>'3a5c99b8a50cf8b9473f912db6477309d7ea5fcb',
        'database/migrations/2026_11_10_000004_add_org_revision_to_portal_sessions.php'=>'b8dee597906106dea33fabc79ce24bcf561d5e9a',
        'database/migrations/2026_11_10_000005_create_portal_auth_throttle_buckets.php'=>'7cb48e75b4de17fd7d9091f5dad81a8eed7b2b4d',
        'database/migrations/2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php'=>'737d43aa4271229e9c34bc2fa4759014ea11b21d',
        'database/migrations/2026_11_11_000012_fence_portal_membership_revision.php'=>'86e75433868b1f6ecc2fd74344b70c8e256e1ec5',
        'database/migrations/2026_11_11_000013_fence_portal_organization_revision.php'=>'7f6eb9526ed56f4306fbe0e6eda961b14142e63f',
        'database/migrations/2026_11_11_000014_guard_portal_session_issuance.php'=>'d00b2d0db13f788ff40b3964cb2b5b20eaf1f699',
        'database/migrations/2026_11_12_000016_fence_portal_identity_revision.php'=>'a7528d5d461f1653efb13ecc3f4fb86ed66dbec8',
        'database/security/portal_browser_auth.sql'=>'42bb622b01e1b89f7ec99214ac104da2262c3774',
        'database/security/portal_browser_principal_candidate.sql'=>'2ea9540009989b17656e18b588f0e7a60ed3762a',
        'database/security/portal_onepage_orders.sql'=>'93fc7987ed7b1902817af95092fe195c7cfc6722',
        'bootstrap/app.php'=>'1085719c5683acf7fa298c8efc78fcc5d0d6005e',
        'config/database.php'=>'00445daefdb5649c75555b8376fe53e995496edc',
    ];

    /**
     * Trusted M2 launcher has already independently pinned #982 and its Composer
     * tree, isolated networking, and qualified all three #590 admin connections.
     * There is no arbitrary migration path, provider installation, or route use.
     * @return array{auth:PostgresPortalBrowserAuthentication,runtime:PDO,order:OnepageAcceptedOrder,initial:string,openPortalFreshRead:Closure}
     */
    public static function run(Stage3SupervisedSlots $slots,PostgresConnection $admin,string $portalRoot):array {
        $phase='SOURCE';
        try {
            $base=$portalRoot.'/apps/portal';
            self::need(realpath($portalRoot)===$portalRoot && realpath($base)===$base);
            foreach(array_keys(getenv()) as $name) {
                self::need(preg_match('/\A(?:APP_|DB_|PG|PORTAL_|DATABASE_URL)/D',$name)!==1);
            }
            self::need(glob($base.'/.env*')===[$base.'/.env.example']);
            foreach(self::PINS as $path=>$sha) self::pin($base.'/'.$path,$sha);
            $pdo=$slots->beforeDdl(self::DB,$admin);

            $phase='UNCACHED_CONSOLE';
            // #589 boot order: no early routesAreCached() filesystem resolution.
            require_once $base.'/vendor/autoload.php';
            $app=require $base.'/bootstrap/app.php';
            self::need($app instanceof Application && $app->basePath()===$base);
            $cache=$base.'/bootstrap/cache';
            self::need(realpath($cache)===$cache && !is_link($cache)
                && $app->getCachedConfigPath()===$cache.'/config.php'
                && $app->getCachedRoutesPath()===$cache.'/routes-v7.php');
            foreach([$cache.'/config.php',$cache.'/routes-v7.php'] as $path)
                self::need(!file_exists($path) && !is_link($path));
            self::need((!$app->bound('config_loaded_from_cache') || $app->make('config_loaded_from_cache')===false)
                && (!$app->bound('routes.cached') || $app->make('routes.cached')===false));
            $app->make(ConsoleKernel::class)->bootstrap();
            $app['config']->set(['app.env'=>'staging','app.debug'=>false,'app.url'=>'http://portal-fixture.invalid',
                'logging.default'=>'null','portal_development_auth.enabled'=>false,'portal_onepage.enabled'=>false,
                'portal_onepage_d0.enabled'=>false,'database.default'=>self::ALIAS]);
            $manager=$app['db'];
            self::need($manager::class===DatabaseManager::class && $manager->getConnections()===[]);
            $config=$admin->getConfig();
            $app['config']->set('database.connections.'.self::ALIAS,$config);
            // Attach only the exact signer-qualified Connection as Laravel's
            // one default alias for migration facades; never reopen on retry.
            $manager->extend(self::ALIAS,static function(array $got,string $name)use($admin,$config):PostgresConnection{
                self::need($name===self::ALIAS && $got===$config);
                return $admin;
            });
            self::need($manager->connection()===$admin);
            $manager->forgetExtension(self::ALIAS);
            $admin->setReconnector(static function():never {throw new RuntimeException();});
            $gate=static function()use($slots,$admin,$pdo,$manager,$app,$config):void {
                // No lazy access to an unknown facade or primary route.
                self::need($manager->getDefaultConnection()===self::ALIAS
                    && $manager->getConnections()===[self::ALIAS=>$admin]
                    && $app['config']->get('database.connections.'.self::ALIAS)===$config
                    && $slots->beforeDdl(self::DB,$admin)===$pdo && DB::getFacadeRoot()===$manager
                    && Schema::getFacadeRoot()->getConnection()===$admin);
            };
            $gate();
            $phase='EMPTY_NATIVE_SCHEMA';
            self::need(self::q($pdo,<<<'SQL'
SELECT to_regclass('public.portal_identities') IS NULL AND to_regclass('public.portal_auth_sessions') IS NULL
   AND to_regnamespace('portal_browser_private') IS NULL AND to_regnamespace('portal_onepage') IS NULL
   AND NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname ~ '^(portal_browser_|portal_onepage_)')
SQL)===true);
            foreach (self::PINS as $file=>$sha) {
                if (!str_starts_with($file,'database/')) continue;
                $phase='MIGRATION_OR_NATIVE_SQL';
                $gate();
                $pin=self::pin($base.'/'.$file,$sha);
                if (str_ends_with($file,'.php')) {
                    (require $base.'/'.$file)->up();
                } else {
                    $pdo->exec($pin);
                }
            }

            $phase='RESTRICTED_LOGIN';
            $acl=['portal_browser_runtime','portal_onepage_runtime','portal_onepage_publisher'];
            $anchor=self::identity($pdo);
            self::need($anchor['name']===self::ADMIN && $anchor['actor']===self::ADMIN);
            $ports=[];$pids=[$anchor['pid']];$openPortalFreshRead=null;
            $dial=static function(string $role,string $password):PDO {
                return new PDO('pgsql:host=127.0.0.1;port=55483;dbname='.self::DB.';sslmode=disable',
                    $role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
                    PDO::ATTR_PERSISTENT=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>3]);
            };
            $same=static function(PDO $p,string $role)use($anchor):array {
                $id=self::identity($p);
                foreach(['db','server','client','port','start','oid'] as $field) self::need($id[$field]===$anchor[$field]);
                self::need($id['name']===$role && $id['actor']===$role && $id['unsafe']===false);
                return $id;
            };
            foreach($acl as $role) {
                $gate();
                self::need(self::q($pdo,<<<'SQL'
SELECT NOT (rolcanlogin OR rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication)
  AND rolpassword IS NULL AND NOT EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid OR roleid=r.oid)
FROM pg_authid r WHERE rolname=?
SQL,[$role])===true);
                $password=bin2hex(random_bytes(32));
                self::need($pdo->beginTransaction());
                try {
                    $pdo->exec("SET LOCAL password_encryption='scram-sha-256'");
                    $pdo->exec('ALTER ROLE '.$role." LOGIN PASSWORD '".$password."'");
                    self::need($pdo->commit());
                } catch(Throwable) {
                    if($pdo->inTransaction()) $pdo->rollBack();
                    throw new RuntimeException();
                }
                $gate();
                $connection=$dial($role,$password);
                $id=$same($connection,$role);
                if($role==='portal_onepage_runtime') {
                    $readerPid=$id['pid'];
                    // One closure opens only the same synthetic fixed role on
                    // the same locally attested endpoint, after receipt commit.
                    $openPortalFreshRead=static function()use($gate,$dial,$same,$password,$readerPid):PDO {
                        $gate();
                        $read=$dial('portal_onepage_runtime',$password);
                        self::need($same($read,'portal_onepage_runtime')['pid']!==$readerPid);
                        return $read;
                    };
                }
                unset($password);
                $pids[]=$id['pid'];$ports[$role]=$connection;
            }
            self::need(count(array_unique($pids))===4 && $openPortalFreshRead instanceof Closure);

            $phase='SYNTHETIC_NATIVE_IDENTITY';
            $gate();
            $key=random_bytes(32);$proof=bin2hex(random_bytes(32));$pepper=bin2hex(random_bytes(32));
            $org=self::q($pdo,'SELECT gen_random_uuid()');$buyer=self::q($pdo,'SELECT gen_random_uuid()');
            $hash=PortalPasswordCredential::makeHash(self::FIXTURE_PASSWORD);
            self::need(is_string($org) && is_string($buyer) && $org!==$buyer && is_string($hash));
            self::need($pdo->beginTransaction());
            try {
                self::writeOne($pdo,'INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)',[$proof]);
                self::writeOne($pdo,<<<'SQL'
INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision)
VALUES(?,?,'Native order integration fixture','active',1)
SQL,[$org,self::ORG]);
                self::writeOne($pdo,<<<'SQL'
INSERT INTO public.portal_identities(id,email_normalized,password_hash,status,mfa_secret_ciphertext,
 email_verified_at,mfa_confirmed_at,security_revision)
VALUES(?,?,?,'active',?,clock_timestamp()-interval '1 minute',clock_timestamp()-interval '1 minute',1)
SQL,[$buyer,self::EMAIL,$hash,PortalTotpSecretCipher::seal(self::TOTP_BASE32,$key)]);
                self::writeOne($pdo,<<<'SQL'
INSERT INTO public.portal_memberships(id,identity_id,organization_id,role,status,membership_revision)
VALUES(gen_random_uuid(),?,?,'member','active',1)
SQL,[$buyer,$org]);
                self::need($pdo->commit());
            } catch(Throwable) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw new RuntimeException();
            }
            $phase='FIRST_NATIVE_MFA';
            $auth=new PostgresPortalBrowserAuthentication($ports['portal_browser_runtime'],self::DB,$proof,$key,$pepper);
            unset($proof,$key,$pepper,$hash);
            $pending=$auth->beginPassword(self::EMAIL,self::FIXTURE_PASSWORD,$org,self::IP);
            self::need(is_string($pending) && !$auth->isActiveBusinessSession($pending));
            $step=(int)self::q($pdo,'SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint');
            $h=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),self::TOTP_RAW,true);
            $off=ord($h[19])&15;
            $code=str_pad((string)((unpack('N',substr($h,$off,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
            $session=$auth->completeMfa($pending,$code,self::IP);
            self::need(is_string($session) && $session!==$pending && $auth->isActiveBusinessSession($session));

            $phase='ACCEPT_EXACT_NATIVE_CONTRACT';
            $runtime=$ports['portal_onepage_runtime'];
            $orders=new PostgresOnepageOrders($runtime,self::DB);
            $selection=['site'=>'onepage','channels'=>[],'tier'=>'minimum'];
            self::need($orders->createDraft($pending,'onepage-key',$selection)['status']==='denied');
            $draft=$orders->createDraft($session,'onepage-key',$selection);
            self::need($draft['status']==='draft' && $draft['quote']['site_setup_cents']===99000
                && $orders->review($session,$draft['id'])===null);
            $offer=(new ApprovedOfferCatalog())->quote('onepage');
            $terms=['scope'=>'onepage','payment_policy'=>'onepage-d0-d90-v1','onepage_payment_policy'=>[
                'version'=>'onepage-d0-d90-v1','currency'=>'BRL','setup_total_cents'=>$offer['setup_total_cents'],
                'setup_charges'=>$offer['setup_charges'],'monthly'=>$offer['monthly'],'entry_installment_key'=>'setup-1',
                'briefing_after_entry_confirmation'=>true,'publication_requires_separate_acceptance'=>true,
            ]];
            self::need(self::q($ports['portal_onepage_publisher'],
                'SELECT portal_onepage.publish_contract(?,?,?,?::jsonb,?::jsonb,?)',
                ['publication-onepage-v1','onepage-agreement',1,json_encode($draft['quote'],JSON_THROW_ON_ERROR),
                    json_encode($terms,JSON_THROW_ON_ERROR),'Native order integration fixture; not operational published terms.'])===true);
            $review=$orders->review($session,$draft['id']);
            self::need(is_array($review) && $review['company_reference']===self::ORG
                && $review['publication_ref']==='publication-onepage-v1');
            $keys=['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest'];
            $accept=['accepted'=>true]+array_intersect_key($review,array_flip($keys));
            self::need(count($accept)===7);
            $accepted=$orders->accept($session,'accept-key',$draft['id'],$accept);
            self::need($accepted['status']==='pending_payment' && $accepted['payment_requested']===false
                && $accepted['site_provisioned']===false);
            $order=$orders->findOwnedAcceptedOrder($session,$accepted['id']);
            self::need($order instanceof OnepageAcceptedOrder && $order->companyId===$org && $order->buyerId===$buyer
                && $order->companyReference===self::ORG && $order->contractRef===$review['contract_ref']
                && $order->contractRevision===$review['contract_revision'] && $order->offerDigest===$review['offer_digest']
                && $order->termsDigest===$review['terms_digest']
                && self::q($pdo,'SELECT count(*)::int FROM portal_onepage.pending_orders')===1);
            fwrite(STDOUT,"STAGE3_NATIVE_INITIAL_MFA_ONE_EXACT_ACCEPTED_ORDER_OK\n");
            return ['auth'=>$auth,'runtime'=>$runtime,'order'=>$order,'initial'=>$session,'openPortalFreshRead'=>$openPortalFreshRead];
        } catch(Throwable) {
            throw new RuntimeException('STAGE3_NATIVE_ORDER_SEED_REFUSED:'.$phase);
        }
    }

    private static function pin(string $p,string $sha):string {
        self::need(realpath($p)===$p && is_file($p));
        $s=file_get_contents($p,false,null,0,262145);
        self::need(is_string($s) && strlen($s)<262145 && sha1('blob '.strlen($s)."\0".$s)===$sha);
        return $s;
    }

    private static function identity(PDO $p):array {
        self::need(!$p->inTransaction() && $p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            && $p->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
            && $p->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false
            && $p->getAttribute(PDO::ATTR_PERSISTENT)===false && $p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)===false);
        $q=$p->query(<<<'SQL'
SELECT current_database() AS db, session_user::text AS name, current_user::text AS actor,
 host(inet_server_addr()) AS server,host(inet_client_addr()) AS client,inet_server_port() AS port,
 extract(epoch FROM pg_postmaster_start_time())::text AS start,pg_backend_pid() AS pid,
 (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
 current_setting('transaction_isolation') AS isolation,
 (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
   OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid OR roleid=r.oid)) AS unsafe
FROM pg_roles r WHERE rolname=current_user
SQL);
        self::need($q!==false);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);self::need(count($rows)===1);$r=$rows[0];
        self::need($r['db']===self::DB && $r['server']==='127.0.0.1' && $r['client']==='127.0.0.1'
            && $r['port']===55483 && $r['isolation']==='read committed' && is_int($r['pid']) && $r['pid']>1);
        return $r;
    }

    private static function q(PDO $p,string $sql,array $args=[]):mixed {
        $q=$p->prepare($sql);self::need($q!==false && $q->execute($args));
        $rows=$q->fetchAll(PDO::FETCH_NUM);self::need(count($rows)===1 && count($rows[0])===1);
        return $rows[0][0];
    }

    private static function writeOne(PDO $p,string $sql,array $args):void {
        $q=$p->prepare($sql);self::need($q!==false && $q->execute($args) && $q->rowCount()===1);
    }
    private static function need(bool $ok):void {if(!$ok) throw new RuntimeException();}
}
