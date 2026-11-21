<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,AsaasTrustedPaymentBinding,
    AsaasTrustedPaymentBindingSource,AsaasVerifiedSettlementSource,OnepageEntryPaymentTerms};
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Closure;
use Illuminate\Database\PostgresConnection;
use PDO;
use PrivateD0Consumption\{Consumer,Target};
use PrivateD0IntegratedLab\ServiceFinancialPort;
use PrivateD0Lab\SyntheticPaymentReader;
use PrivateSharedIngress\CaptureHarness;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Exact test-only #982 producer continuation; no HTTP, DB provisioning, or legacy ServiceGate. */
final class Stage3NativePaidEntry
{
    private const NATIVE='portal_native_orders_ci';
    private const INBOX='portal_asaas_ci';
    private const ACCOUNT='native-entry-sandbox';
    private const CAPTURE_ACCOUNT='1fcc491f-96d9-4efe-8ec5-b8b09de750dd';
    private const EVENT='evt_buyer_continuation_0001';
    private const PAYMENT='pay_ConfigFixture1';
    private const CUSTOMER='cus_ConfigFixture1';
    private const OPT=[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_PERSISTENT=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>3,
    ];

    /**
     * The reviewed driver materializes exact #982 components and shared SQL on fresh
     * #590-targeted DBs. Each restricted Connection comes only from the driver-owned
     * native Laravel factory; externally injected primary PDO/closures are forbidden.
     * No new grants, alternate inputs/provider, fixture receipt or admin entry.
     */
    public static function run(
        Stage3SupervisedSlots $slots,PostgresConnection $nativeAdmin,PostgresConnection $inboxAdmin,
        PostgresConnection $finance,PostgresConnection $service,
        PostgresConnection $capture,PostgresConnection $worker,
        PostgresPortalBrowserAuthentication $auth,OnepageAcceptedOrder $order,
        #[SensitiveParameter] string $oldSession
    ):void {
        $phase='ATTEST_PRODUCER_PORTS';
        try {
            $na=$slots->beforeDdl(self::NATIVE,$nativeAdmin);
            $ia=$slots->beforeDdl(self::INBOX,$inboxAdmin);
            $native=self::context($na);$inbox=self::context($ia);
            $resolve=static fn(PostgresConnection $conn,array $anchor,string $role,string $alias):PDO=>
                self::restricted($conn,$anchor,$role,$alias);
            $fp=$resolve($finance,$native,'onepage_finance_lab_verifier','stage3_native_finance');
            $sp=$resolve($service,$native,'onepage_d0_service_b','stage3_native_service');
            $cp=$resolve($capture,$inbox,'onepage_ingress_capture','stage3_inbox_capture');
            $wp=$resolve($worker,$inbox,'asaas_reconcile_worker','stage3_inbox_worker');
            $known=[$na,$ia,$fp,$sp,$cp,$wp];
            self::need(count(array_unique(array_map(static fn(PDO $p):int=>self::context($p)['pid'],$known)))===6);
            $gate=static function(string $name, PDO $p)use($resolve,$slots,$nativeAdmin,$inboxAdmin,
                $na,$ia,$native,$inbox,$fp,$sp,$cp,$wp,$finance,$service,$capture,$worker):bool {
                return match($name) {
                    'inbox_audit'=>$p===$ia && $p===$slots->beforeDdl(self::INBOX,$inboxAdmin),
                    'ingress_capture'=>$p===$cp && $ia===$slots->beforeDdl(self::INBOX,$inboxAdmin)
                        && $p===$resolve($capture,$inbox,'onepage_ingress_capture','stage3_inbox_capture'),
                    'worker'=>$p===$wp && $ia===$slots->beforeDdl(self::INBOX,$inboxAdmin)
                        && $p===$resolve($worker,$inbox,'asaas_reconcile_worker','stage3_inbox_worker'),
                    'finance'=>$p===$fp && $na===$slots->beforeDdl(self::NATIVE,$nativeAdmin)
                        && $p===$resolve($finance,$native,'onepage_finance_lab_verifier','stage3_native_finance'),
                    'service'=>$p===$sp && $na===$slots->beforeDdl(self::NATIVE,$nativeAdmin)
                        && $p===$resolve($service,$native,'onepage_d0_service_b','stage3_native_service'),
                    default=>false,
                };
            };
            self::need($gate('service',$sp));
            self::serviceAcl($na,$sp);
            self::need(self::q($ia,'SELECT count(*)::int AS n FROM portal_psp.asaas_inbox')['n']===0);
            self::counts($na,0,0);
            self::need($auth->isActiveBusinessSession($oldSession) && $auth->revoke($oldSession)
                && !$auth->isActiveBusinessSession($oldSession));

            $phase='EXACT_SYNTHETIC_PROVIDER';
            // Fixture value derived from the previously accepted native orders, not a
            // webhook, SQL observation or a reflection of the inspected binding.
            $psp=[
                'id'=>self::PAYMENT,'customer'=>self::CUSTOMER,
                'externalReference'=>OnepageEntryPaymentTerms::reference($order,self::ACCOUNT,self::CUSTOMER),
                'billingType'=>'PIX','status'=>'RECEIVED','currency'=>'BRL','value'=>'330.00',
            ];
            $observationRead=new class($psp) implements SyntheticPaymentReader {
                public int $calls=0;
                public function __construct(private readonly array $fixture){}
                public function fetchPayment(string $id):?array {
                    ++$this->calls;
                    return $id===$this->fixture['id']?$this->fixture:null;
                }
            };
            $confirmRead=new class($psp) implements AsaasOfficialPaymentReader {
                public int $calls=0;
                public function __construct(private readonly array $fixture){}
                public function fetchPayment(string $id):?array {
                    ++$this->calls;
                    return $id===$this->fixture['id']?$this->fixture:null;
                }
            };

            $phase='NATIVE_CAPTURE_AND_CONSUMER';
            $financial=new ServiceFinancialPort($fp,static fn(PDO $p):bool=>$gate('finance',$p),
                ['database'=>self::NATIVE,'port'=>55483,'server_address'=>'127.0.0.1']);
            $target=new Target(self::CAPTURE_ACCOUNT,self::ACCOUNT,'sandbox',self::EVENT,self::PAYMENT,
                $order->companyReference,$order->id);
            $bind=$financial->inspect_binding(self::ACCOUNT,'sandbox',self::PAYMENT);
            self::need($bind!==null && $target->matches($bind)
                && $bind->binding->contractRef===$order->contractRef
                && $bind->binding->contractRevision===$order->contractRevision);
            // Exact harness does native authentication rejection and duplicate
            // capture itself, then reads the durable inbox before returning.
            (new CaptureHarness($ia,$cp,$gate,$financial))->receive(self::CAPTURE_ACCOUNT,self::ACCOUNT,self::EVENT,self::PAYMENT);
            $consumer=new Consumer($wp,static fn(PDO $p):bool=>$gate('worker',$p),
                $target,$financial,$observationRead);
            self::need($consumer->run()==='completed' && $observationRead->calls===1
                && $consumer->run()==='not_claimable' && $observationRead->calls===1);
            self::counts($na,1,0);
            $seen=self::q($ia,<<<'SQL'
SELECT count(*)::int AS n FROM portal_psp.asaas_inbox
WHERE account_ref=?::uuid AND event_id=? AND payment_id=? AND state='observed_psp_received' AND attempts=1
SQL,[self::CAPTURE_ACCOUNT,self::EVENT,self::PAYMENT]);
            self::need($seen['n']===1 && self::q($ia,'SELECT count(*)::int AS n FROM portal_psp.asaas_inbox')['n']===1
                && !$auth->isActiveBusinessSession($oldSession));
            fwrite(STDOUT,"STAGE3_ONE_NATIVE_CONSUMER_OBSERVATION_BEFORE_ENTRY_OK\n");

            $phase='PINNED_SERVICE_ENTRY';
            $inspect=static function()use($gate,$sp):string {
                self::need($gate('service',$sp) && $sp->beginTransaction());
                try {
                    $q=self::q($sp,'SELECT portal_onepage.d0_service_b_inspect(?,?,?)::text AS snapshot',
                        [self::ACCOUNT,'sandbox',self::PAYMENT]);
                    $v=$q['snapshot'];
                    self::need(is_string($v) && strlen($v)<=8192 && $sp->commit());
                    return $v;
                } catch(Throwable) {
                    if($sp->inTransaction()) $sp->rollBack();
                    throw new RuntimeException();
                }
            };
            $raw=$inspect();
            $stamp=ServiceFinancialPort::decode($raw,self::ACCOUNT,'sandbox',self::PAYMENT);
            self::need($target->matches($stamp) && $stamp->stamp()===$bind->stamp());
            $binding=new class($inspect,$raw,$stamp->binding) implements AsaasTrustedPaymentBindingSource {
                public function __construct(private readonly Closure $inspect,private readonly string $raw,
                    private readonly AsaasTrustedPaymentBinding $binding){}
                public function findInstallationPayment(string $order):?AsaasTrustedPaymentBinding {
                    return $order===$this->binding->orderRef && hash_equals($this->raw,($this->inspect)())
                        ?$this->binding:null;
                }
            };
            $proof=(new AsaasVerifiedSettlementSource($binding,$confirmRead))->findCurrentSettledOrder($order->id);
            self::need($proof!==null && $confirmRead->calls===1 && $proof->orderRef===$order->id);
            $stored=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            $receipt=$stored['binding']; unset($receipt['due_date']);
            $receipt+=['currency'=>'BRL','status'=>'RECEIVED'];
            self::need($gate('service',$sp) && $sp->beginTransaction());
            try {
                $out=self::q($sp,<<<'SQL'
SELECT portal_onepage.d0_service_b_confirm(?,?,?,?::jsonb,?::jsonb,?,clock_timestamp()) AS result
SQL,[self::ACCOUNT,'sandbox',self::PAYMENT,$raw,json_encode($receipt,JSON_THROW_ON_ERROR),$proof->settlementSha256]);
                self::need($out['result']==='recorded' && $sp->commit());
            } catch(Throwable) {
                if($sp->inTransaction()) $sp->rollBack();
                throw new RuntimeException();
            }

            $phase='INDEPENDENT_NATIVE_READBACK';
            self::need($na===$slots->beforeDdl(self::NATIVE,$nativeAdmin) && !$auth->isActiveBusinessSession($oldSession));
            self::counts($na,1,1);
            $same=self::q($na,<<<'SQL'
SELECT count(*)::int AS n FROM portal_onepage.entry_confirmations e
JOIN onepage_finance_lab.observations f USING(order_id,account_ref,environment,payment_id,evidence_sha256)
JOIN portal_onepage.pending_orders o ON o.id=e.order_id
WHERE e.order_id=?::uuid AND e.account_ref=? AND e.environment='sandbox' AND e.payment_id=?
  AND e.evidence_sha256=? AND e.context=f.expected->'context' AND e.binding=f.expected->'binding'
  AND o.status='pending_payment' AND o.company=?::uuid AND o.buyer=?::uuid
SQL,[$order->id,self::ACCOUNT,self::PAYMENT,$proof->settlementSha256,$order->companyId,$order->buyerId]);
            self::need($same['n']===1);
            fwrite(STDOUT,"STAGE3_SAME_ORDER_NATIVE_ENTRY_COMMITTED_NO_BUYER_OK\n");
        } catch(Throwable) {throw new RuntimeException('STAGE3_NATIVE_ENTRY_REFUSED:'.$phase);}
    }

    private static function serviceAcl(PDO $admin,PDO $service):void {
        // #982 execute-only role boundary, verified with actual role/catalogs.
        self::need(self::q($service,<<<'SQL'
SELECT NOT has_table_privilege(current_user,'portal_onepage.entry_confirmations','INSERT,UPDATE,DELETE,SELECT')
    AND NOT has_schema_privilege(current_user,'portal_onepage','CREATE') AS sealed
SQL)['sealed']===true);
        self::need(self::q($admin,<<<'SQL'
SELECT NOT has_table_privilege('onepage_d0_service_b','onepage_finance_lab.observations'::regclass,'SELECT,INSERT,UPDATE,DELETE')
    AND NOT has_schema_privilege('onepage_d0_service_b','onepage_finance_lab','USAGE')
    AND NOT has_function_privilege('onepage_d0_service_b',
       'onepage_finance_lab.service_b_observation_matches(text,text,uuid,text,jsonb,jsonb,text)'::regprocedure,'EXECUTE')
    AND NOT EXISTS(SELECT 1 FROM pg_auth_members WHERE member='onepage_d0_service_b'::regrole OR roleid='onepage_d0_service_b'::regrole)
AS sealed
SQL)['sealed']===true);
        $q=$service->query(<<<'SQL'
SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname='portal_onepage' AND has_function_privilege(current_user,p.oid,'EXECUTE')
ORDER BY p.proname
SQL);
        self::need($q!==false && $q->fetchAll(PDO::FETCH_COLUMN)===['d0_service_b_confirm','d0_service_b_inspect']);
    }

    private static function counts(PDO $p,int $observations,int $entries):void {
        self::need(self::q($p,<<<'SQL'
SELECT (SELECT count(*) FROM onepage_finance_lab.observations)::int AS obs,
       (SELECT count(*) FROM portal_onepage.entry_confirmations)::int AS entry
SQL)===['obs'=>$observations,'entry'=>$entries]);
    }

    private static function restricted(PostgresConnection $c,array $a,string $role,string $alias):PDO {
        self::need($c::class===PostgresConnection::class && $c->transactionLevel()===0
            && $c->getName()===$alias && $c->getDatabaseName()===$a['db'] && self::plain($c));
        $cfg=$c->getConfig();
        $expected=['name'=>$alias,'driver'=>'pgsql','database'=>$a['db'],'username'=>$role,'host'=>'127.0.0.1',
            'port'=>55483,'sslmode'=>'disable','charset'=>'utf8','prefix'=>'','search_path'=>'public'];
        self::need(count($cfg)===count($expected)+2
            && array_diff(array_keys($cfg),[...array_keys($expected),'password','options'])===[]
            && is_string($cfg['password']) && preg_match('/\A[a-f0-9]{64}\z/D',$cfg['password'])===1
            && is_array($cfg['options']) && count($cfg['options'])===count(self::OPT));
        foreach($expected as $k=>$v) self::need(($cfg[$k]??null)===$v);
        foreach(self::OPT as $k=>$v) self::need(($cfg['options'][$k]??null)===$v);
        $p=$c->getPdo();
        self::need(self::plain($c) && $c->getReadPdo()===$p);
        $r=self::context($p);
        foreach(['db','server','client','port','started','oid'] as $k) self::need($r[$k]===$a[$k]);
        self::need($r['login']===$role && $r['actor']===$role && $r['unsafe']===false);
        return $p;
    }

    private static function plain(PostgresConnection $c):bool {
        return $c->getRawReadPdo()===null && $c->getRawDirectPdo()===null && $c->getDirectPdoConfig()===[];
    }

    private static function context(PDO $p):array {
        self::need(!$p->inTransaction() && $p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            && $p->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
            && $p->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false
            && $p->getAttribute(PDO::ATTR_PERSISTENT)===false && $p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)===false);
        $r=self::q($p,<<<'SQL'
SELECT current_database() AS db,session_user::text AS login,current_user::text AS actor,
       pg_backend_pid() AS pid,host(inet_server_addr()) AS server,host(inet_client_addr()) AS client,inet_server_port() AS port,
       extract(epoch FROM pg_postmaster_start_time())::text AS started,
       (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
       current_setting('transaction_isolation') AS isolation,
       (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
        OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid OR roleid=r.oid)) AS unsafe
FROM pg_roles r WHERE rolname=current_user
SQL);
        self::need($r['server']==='127.0.0.1' && $r['client']==='127.0.0.1' && $r['port']===55483
            && $r['isolation']==='read committed' && is_int($r['pid']) && $r['pid']>1);
        return $r;
    }

    private static function q(PDO $p,string $sql,array $args=[]):array {
        $q=$p->prepare($sql); self::need($q!==false && $q->execute($args));
        $r=$q->fetchAll(PDO::FETCH_ASSOC);self::need(count($r)===1);return $r[0];
    }
    private static function need(bool $ok):void {if(!$ok)throw new RuntimeException();}
}
