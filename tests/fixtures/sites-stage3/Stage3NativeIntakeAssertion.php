<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\Commerce\D0\FirstBriefingRelease;
use App\Domain\SitesIntegration\PortalSitesPendingReceiptValidator;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Commerce\PostgresD0EntryStore;
use App\Infrastructure\Commerce\PostgresOnepageSiteReceiptWriter;
use App\Infrastructure\Commerce\PostgresPortalSiteContractSource;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use App\Integration\PortalProjectIntakeRecorder;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Integration\SiteProjectCommand;
use Closure;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Private test-only post-entry continuation. No schema, login setup or payment transport. */
final class Stage3NativeIntakeAssertion
{
    private const PORTAL='portal_native_orders_ci';
    private const SITES='portal_sites_pair_ci';
    private const ACCOUNT='native-entry-sandbox';
    private const PAYMENT='pay_ConfigFixture1';

    /**
     * Exact trusted driver must supply one already accepted #982 fixture order,
     * a revoked old native buyer session and a newly issued actual MFA session
     * for the SAME buyer, never an SQL-minted token. Reuse fresh #590 audit slots.
     * Runtime PDOs are already opened by that trusted driver only after its own
     * effective endpoint preflight. The evidence below binds them to the slots
     * before SQL functions, not before their original connections were opened.
     */
    public static function run(
        Stage3SupervisedSlots $slots,
        PostgresConnection $portalAdmin, PostgresConnection $sitesAdmin,
        PostgresConnection $siteReceiver,
        PDO $portalReceipt, PDO $portalOrderReader, Closure $openPortalFreshRead,
        PostgresPortalBrowserAuthentication $auth, OnepageAcceptedOrder $order, string $siteTenant,
        #[SensitiveParameter] string $oldSession, #[SensitiveParameter] string $freshSession
    ):void {
        $phase='AUDIT_TARGET';
        try {
            $pa=$slots->beforeDdl(self::PORTAL,$portalAdmin);
            $sa=$slots->beforeDdl(self::SITES,$sitesAdmin);
            $p=self::identity($pa); $s=self::identity($sa);
            self::need($p['db']===self::PORTAL && $s['db']===self::SITES
                && $p['port']===$s['port'] && $p['start']===$s['start'] && $p['oid']!==$s['oid']);
            $phase='ROLE_PROOF';
            self::need($siteReceiver::class===PostgresConnection::class
                && $siteReceiver->transactionLevel()===0
                && $siteReceiver->getRawReadPdo()===null && $siteReceiver->getRawDirectPdo()===null
                && $siteReceiver->getDirectPdoConfig()===[]);
            $sitePdo=$siteReceiver->getPdo();
            self::need($siteReceiver->getRawReadPdo()===null && $siteReceiver->getRawDirectPdo()===null
                && $siteReceiver->getDirectPdoConfig()===[] && $siteReceiver->getReadPdo()===$sitePdo);
            $peers=[
                self::restricted($sitePdo,$s,'portal_sites_pair_receiver'),
                self::restricted($portalReceipt,$p,'portal_onepage_intake_recorder'),
                self::restricted($portalOrderReader,$p,'portal_onepage_runtime'),
            ];
            self::need(count(array_unique([$p['pid'],$s['pid'],...$peers]))===5);
            $phase='POST_ENTRY_PRECONDITION';
            self::need($order->id!==$siteTenant && $order->companyId!==$siteTenant
                && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$siteTenant)===1);
            $financial=self::financial($pa,$order);
            self::need(!$auth->isActiveBusinessSession($oldSession)
                && $oldSession!==$freshSession && $auth->isActiveBusinessSession($freshSession));
            $read=new PostgresOnepageOrders($portalOrderReader,self::PORTAL);
            self::need($read->findOwnedSiteIntake($oldSession,$order->id)===null
                && $read->findOwnedSiteIntake($freshSession,$order->id)===['status'=>'not_recorded']);
            self::need(self::countPortal($pa)===0 && self::countSites($sa)===[0,0,0,0]);

            $phase='NATIVE_COMMAND';
            $orders=new PostgresOnepageOrders($portalOrderReader,self::PORTAL);
            $source=new PostgresPortalSiteContractSource($orders,$freshSession);
            $wire=(new SitesCreateProjectEncoder($source))->build(
                $order->id,'stage3-native-op-'.$order->id,'stage3-native-idem-'.$order->id
            );
            self::need($wire['tenantRef']===$order->companyReference && $wire['tenantRef']!==$siteTenant
                && $wire['orderRef']===$order->id && $wire['contractRef']===$order->contractRef
                && $wire['contractRevision']===$order->contractRevision && $wire['productCode']==='onepage');
            $command=SiteProjectCommand::fromArray($wire);
            $phase='NATIVE_COMMITTED_RECEIPT';
            self::currentEntry($portalOrderReader,$freshSession,$order->id);
            self::restricted($sitePdo,$s,'portal_sites_pair_receiver');
            $recorder=new PortalProjectIntakeRecorder($siteReceiver);
            $receipt=$recorder->record($command);
            self::need(PortalSitesPendingReceiptValidator::validate($wire,$receipt)===$receipt
                && $receipt['commandDigest']===$command->semanticFingerprint());
            self::restricted($portalReceipt,$p,'portal_onepage_intake_recorder');
            $ledger=new PostgresOnepageSiteReceiptWriter($portalReceipt,self::PORTAL);
            self::currentEntry($portalOrderReader,$freshSession,$order->id);
            self::need($ledger->record($wire,$receipt)===$receipt
                && !$portalReceipt->inTransaction() && !$sitePdo->inTransaction()
                && $siteReceiver->transactionLevel()===0);
            // Exact replay on both committed product ports; never regenerate IDs.
            self::currentEntry($portalOrderReader,$freshSession,$order->id);
            self::need($recorder->record($command)===$receipt && $ledger->record($wire,$receipt)===$receipt);

            $phase='CORRELATE_DURABLE_ROWS';
            self::need($slots->beforeDdl(self::PORTAL,$portalAdmin)===$pa
                && $slots->beforeDdl(self::SITES,$sitesAdmin)===$sa);
            self::need(self::financial($pa,$order)===$financial
                && self::countPortal($pa)===1 && self::countSites($sa)===[1,1,1,1]);
            $saved=self::one($pa,'SELECT site_ref::text,receipt::text FROM portal_onepage.site_intake_receipts WHERE order_id=?::uuid',[$order->id]);
            $decoded=json_decode($saved['receipt'],true,32,JSON_THROW_ON_ERROR);
            self::need(is_array($decoded) && $saved['site_ref']===$receipt['siteRef']);
            $want=$receipt; ksort($decoded); ksort($want); self::need($decoded===$want);
            $stored=self::one($sa,<<<'SQL'
SELECT i.tenant_id::text AS tenant, i.site_id::text AS site_ref, i.portal_org_ref, i.portal_order_ref,
       i.contract_ref,i.contract_revision,i.product_code,i.operation_id,i.idempotency_key,i.command_digest,
       p.authority_verified,p.publishable,p.lifecycle_state,o.delivery_state,o.event_type,o.payload_sha256
FROM sites_core.portal_project_intakes i
JOIN sites_core.site_projects p
  ON p.tenant_id=i.tenant_id AND p.site_id=i.site_id
JOIN sites_core.site_outbox o
  ON o.tenant_id=i.tenant_id AND o.event_id=i.event_id AND o.site_id=i.site_id
WHERE i.portal_order_ref=? AND i.portal_org_ref=?
SQL,[$order->id,$order->companyReference]);
            self::need($stored===[
                'tenant'=>$siteTenant,'site_ref'=>$receipt['siteRef'],'portal_org_ref'=>$order->companyReference,
                'portal_order_ref'=>$order->id,'contract_ref'=>$order->contractRef,
                'contract_revision'=>$order->contractRevision,'product_code'=>'onepage','operation_id'=>$command->operationId,
                'idempotency_key'=>$command->idempotencyKey,'command_digest'=>$receipt['commandDigest'],
                'authority_verified'=>false,'publishable'=>false,'lifecycle_state'=>'awaiting_official_authority',
                'delivery_state'=>'blocked','event_type'=>'ProjectQueued','payload_sha256'=>$receipt['commandDigest'],
            ]);

            $phase='NATIVE_BUYER_PROJECTION';
            // Trusted fixed M2 driver opens a new Portal read connection here,
            // after real receipts commit. Never a dynamic caller/transport hook.
            $portalFreshRead=$openPortalFreshRead();
            self::need($portalFreshRead instanceof PDO);
            $readPid=self::restricted($portalFreshRead,$p,'portal_onepage_runtime');
            self::need(!in_array($readPid,[$p['pid'],$s['pid'],...$peers],true));
            $read=new PostgresOnepageOrders($portalFreshRead,self::PORTAL);
            $visible=$read->findOwnedSiteIntake($freshSession,$order->id);
            $want=['status'=>'recorded','siteRef'=>$receipt['siteRef'],'state'=>'awaiting_official_authority',
                'authorityVerified'=>false,'clientAccepted'=>false,'canPublish'=>false];
            self::need(is_array($visible));
            ksort($visible); ksort($want); self::need($visible===$want);
            self::need($read->findOwnedSiteIntake($oldSession,$order->id)===null);
            self::currentEntry($portalFreshRead,$freshSession,$order->id);
            fwrite(STDOUT,"STAGE3_ONE_NATIVE_ENTRY_PENDING_INTAKE_AND_BUYER_READ_OK\n");
        } catch(Throwable) {
            throw new RuntimeException('STAGE3_M2_REFUSED:'.$phase);
        }
    }

    /** Current native buyer view; no access to a D0 writer role is needed. */
    private static function currentEntry(PDO $reader,#[SensitiveParameter] string $session,string $order):void {
        // The store constructor requires a writer argument. This read-only test
        // deliberately supplies only the same runtime PDO: it cannot pass native
        // admit's verifier-role/distinct-connection checks. Never call attempt().
        $state=new FirstBriefingRelease(new PostgresD0EntryStore($reader,$reader,self::PORTAL,self::ACCOUNT));
        self::need($state->state($session,$order)==='briefing_available');
    }

    private static function financial(PDO $db,OnepageAcceptedOrder $order):array {
        $row=self::one($db,<<<'SQL'
SELECT o.status, f.evidence_sha256 AS digest, e.evidence_sha256 AS entry_digest,
       e.context->>'order_ref' AS order_ref,e.context->>'tenant_ref' AS tenant_ref,
       e.context->>'contract_ref' AS contract_ref,e.context->>'contract_revision' AS contract_revision,
       (e.context=f.expected->'context' AND e.binding=f.expected->'binding') AS same_snapshot,
       (SELECT count(*) FROM onepage_finance_lab.observations)::int AS observation_count,
       (SELECT count(*) FROM portal_onepage.entry_confirmations)::int AS entry_count
FROM portal_onepage.pending_orders o
JOIN portal_onepage.entry_confirmations e ON e.order_id=o.id
JOIN onepage_finance_lab.observations f ON f.order_id=e.order_id AND f.account_ref=e.account_ref
 AND f.environment=e.environment AND f.payment_id=e.payment_id
WHERE o.id=?::uuid AND o.company=?::uuid AND e.account_ref=? AND e.environment='sandbox' AND e.payment_id=?
SQL,[$order->id,$order->companyId,self::ACCOUNT,self::PAYMENT]);
        self::need($row['status']==='pending_payment' && $row['observation_count']===1 && $row['entry_count']===1
            && $row['same_snapshot']===true && $row['digest']===$row['entry_digest']
            && preg_match('/\A[a-f0-9]{64}\z/D',$row['digest'])===1
            && $row['order_ref']===$order->id && $row['tenant_ref']===$order->companyReference
            && $row['contract_ref']===$order->contractRef && $row['contract_revision']===(string)$order->contractRevision);
        return $row;
    }

    private static function identity(PDO $pdo):array {
        self::need($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            && $pdo->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
            && $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false
            && $pdo->getAttribute(PDO::ATTR_PERSISTENT)===false
            && $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)===false && !$pdo->inTransaction());
        $r=self::one($pdo,<<<'SQL'
SELECT current_database() AS db,session_user::text AS login,current_user::text AS actor,
       host(inet_server_addr()) AS host,host(inet_client_addr()) AS client,inet_server_port() AS port,
       extract(epoch FROM pg_postmaster_start_time())::text AS start,pg_backend_pid() AS pid,
       (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
       (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
         OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid)) AS unsafe
FROM pg_roles r WHERE rolname=current_user
SQL,[]);
        self::need($r['host']==='127.0.0.1' && $r['client']==='127.0.0.1' && $r['port']===55483
            && is_int($r['pid']) && $r['pid']>1);
        return $r;
    }

    private static function restricted(PDO $pdo,array $anchor,string $role):int {
        $r=self::identity($pdo);
        foreach (['db','host','client','port','start','oid'] as $k) self::need($r[$k]===$anchor[$k]);
        self::need($r['login']===$role && $r['actor']===$role && $r['unsafe']===false);
        return $r['pid'];
    }

    private static function countPortal(PDO $pdo):int {
        return self::one($pdo,'SELECT count(*)::int AS n FROM portal_onepage.site_intake_receipts',[])['n'];
    }

    private static function countSites(PDO $pdo):array {
        return array_values(self::one($pdo,<<<'SQL'
SELECT (SELECT count(*) FROM sites_core.contract_refs)::int AS contracts,
       (SELECT count(*) FROM sites_core.site_projects)::int AS projects,
       (SELECT count(*) FROM sites_core.portal_project_intakes)::int AS intakes,
       (SELECT count(*) FROM sites_core.site_outbox)::int AS outbox
SQL,[]));
    }

    private static function one(PDO $pdo,string $sql,array $bind):array {
        $q=$pdo->prepare($sql);
        self::need($q!==false && $q->execute($bind));
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        self::need(count($rows)===1);
        return $rows[0];
    }

    private static function need(bool $ok):void { if (!$ok) throw new RuntimeException(); }
}
