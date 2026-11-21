<?php
declare(strict_types=1);

namespace OnepageSitesTerminalDraft;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

enum WorkerStage:string
{
    case Lock='LOCK'; case Requested='REQUESTED'; case Head='HEAD';
    case Shape='SHAPE'; case Veto='VETO'; case RequestedTuple='REQUESTED_TUPLE';
    case Proposal='PROPOSAL'; case Insert='INSERT'; case Commit='COMMIT';
}
final class WorkerStageFailure extends RuntimeException
{
    public function __construct(public readonly WorkerStage $stage) { parent::__construct('REFUSED'); }
}

/** Test only; load after machine v2 in the qualified #541 parent and worker. */
final class RevokeFirstFixture
{
    private const LOCK = <<<'SQL'
SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(
    'sites-release:'||(?::uuid)::text||':'||(?::uuid)::text,0))
SQL;
    private const REQUESTED = <<<'SQL'
SELECT t.portal_org_ref,b.site_id::text,b.binding_revision,b.event_kind,b.release_ref,
       b.order_ref,b.contract_ref,b.contract_revision,b.product_code,b.command_digest::text,
       b.editorial_revision,b.artifact_digest::text,p.snapshot_ref::text,p.brief_revision,
       p.manifest_sha256,s.source_version
FROM sites_core.release_binding_events b
JOIN sites_core.tenant_accounts t USING (tenant_id)
JOIN sites_core.release_artifact_provenance p USING (tenant_id,site_id,release_ref,artifact_digest)
JOIN sites_core.portal_brief_snapshots s USING (tenant_id,site_id,snapshot_ref,brief_revision,manifest_sha256)
WHERE b.tenant_id=?::uuid AND b.site_id=?::uuid AND b.binding_revision=?::bigint
  AND p.portal_org_ref=t.portal_org_ref AND s.portal_org_ref=t.portal_org_ref
SQL;
    private const HEAD = <<<'SQL'
SELECT binding_revision,event_kind,release_ref,artifact_digest::text,editorial_revision
FROM sites_core.release_binding_events
WHERE tenant_id=?::uuid AND site_id=?::uuid
ORDER BY binding_revision DESC LIMIT 1
SQL;
    private const ROW = <<<'SQL'
SELECT operation_ref,tenant_id::text,site_id::text,requested_binding_revision,observed_binding_revision,
       exact_tuple::text,terminal,recorded_at::text
FROM sites_onepage_terminal_candidate.decision WHERE operation_ref=?
SQL;
    private const INSERT = <<<'SQL'
INSERT INTO sites_onepage_terminal_candidate.decision
 (operation_ref,tenant_id,site_id,requested_binding_revision,observed_binding_revision,exact_tuple,terminal)
 VALUES (?,?::uuid,?::uuid,?::bigint,?::bigint,?::jsonb,'rejected')
SQL;

    /**
     * $writer is a disposable #541 fixture connection, never an app role.
     * $ready writes the bounded worker preamble from inside this backend.
     * Worker returns only an ACK-discard marker, not a receipt.
     */
    public static function worker(PDO $writer, string $tenant, Intent $a, Closure $ready): array
    {
        self::identity($writer,'off');
        self::need(!$writer->inTransaction() && $writer->beginTransaction());
        $stage=WorkerStage::Lock;
        try {
            $pid=(int)self::rows($writer,'SELECT pg_catalog.pg_backend_pid() AS pid',[])[0]['pid'];
            $ready($pid);
            $site=$a->tuple['siteRef'];
            $old=$a->tuple['bindingRevision'];
            self::rows($writer,self::LOCK,[$tenant,$site]); // separate READ COMMITTED snapshot next
            $stage=WorkerStage::Requested;
            $requested=self::rows($writer,self::REQUESTED,[$tenant,$site,$old]);
            $stage=WorkerStage::Head;
            $head=self::rows($writer,self::HEAD,[$tenant,$site]);
            $stage=WorkerStage::Shape;
            self::need(count($requested)===1 && count($head)===1);
            $x=$requested[0]; $h=$head[0]; $t=$a->tuple;
            $stage=WorkerStage::Veto;
            self::need($old<PHP_INT_MAX && (int)$h['binding_revision']===$old+1 &&
                $h['event_kind']==='revoked' && $h['release_ref']===$t['releaseRef'] &&
                $h['artifact_digest']===$t['htmlSha256'] && (int)$h['editorial_revision']===$t['editorialRevision']);
            $stage=WorkerStage::RequestedTuple;
            self::need($x['event_kind']==='bound' && $x['product_code']==='onepage' &&
                [$x['portal_org_ref'],$x['site_id'],$x['release_ref'],$x['order_ref'],$x['contract_ref'],
                    (int)$x['contract_revision'],$x['command_digest'],(int)$x['editorial_revision'],$x['artifact_digest'],
                    $x['snapshot_ref'],(int)$x['brief_revision'],$x['manifest_sha256'],(int)$x['source_version']]
                ===[$t['tenantRef'],$site,$t['releaseRef'],$t['orderRef'],$t['contractRef'],$t['contractRevision'],
                    $t['commandDigest'],$t['editorialRevision'],$t['htmlSha256'],$t['snapshotRef'],
                    $t['briefRevision'],$t['manifestSha256'],$t['sourceVersion']]);
            $stage=WorkerStage::Proposal;
            $authority=new class implements DecisionAuthority {
                // Only after the exact native veto above; no path to Eligible.
                public function observeUnderDecisionLock(Intent $intent): Verdict {return Verdict::Vetoed;}
                public function mayReadTerminal(Intent $intent): bool {return false;}
            };
            $p=(new SitesTerminalMachine($authority))->decide($a,null);
            self::need($p->outcome===Outcome::Proposed && $p->proposal?->terminal===Terminal::Rejected);
            $stage=WorkerStage::Insert;
            $q=$writer->prepare(self::INSERT);
            self::need($q->execute([$a->operationRef,$tenant,$site,$old,$old+1,self::json($a),]) &&
                $q->rowCount()===1 && $q->closeCursor());
            $stage=WorkerStage::Commit;
            self::need($writer->commit());
            return ['kind'=>'discarded'];
        } catch (Throwable) {
            try { if ($writer->inTransaction()) $writer->rollBack(); } catch (Throwable) {}
            throw new WorkerStageFailure($stage);
        }
    }

    /**
     * #541 hooks: $start owns an admitted isolated worker and returns its PID;
     * $finish is the existing deadline/cleanup and returns its sanitized result;
     * $captureNative is #541 bapRows; $openFreshObserver returns a separately
     * attested new read-only administrative observer for this fixture only.
     */
    public static function run(PDO $revoker, PDO $observer, string $tenant, Intent $a,
        Closure $start, Closure $finish, Closure $captureNative, Closure $openFreshObserver): void
    {
        self::identity($revoker,'off'); self::identity($observer,'on');
        $before=$captureNative($observer);
        $base=self::rows($observer,'SELECT count(*)::int AS n FROM sites_onepage_terminal_candidate.decision',[]);
        self::need($base===[['n'=>0]]);
        $site=$a->tuple['siteRef']; $old=$a->tuple['bindingRevision'];
        self::need($old<PHP_INT_MAX && !$revoker->inTransaction() && $revoker->beginTransaction());
        try {
            $r=self::rows($revoker,'SELECT pg_catalog.pg_backend_pid() AS pid',[]);
            $revokerPid=$r[0]['pid'];
            $answer=self::rows($revoker,'SELECT sites_core.revoke_release_binding(?::uuid,?::bigint) AS rev',[$site,$old]);
            self::need(count($answer)===1 && (int)$answer[0]['rev']===$old+1);
            $worker=$start($tenant,$a);
            self::need(is_array($worker) && is_int($worker['pid']??null) && $worker['pid']>0 &&
                $worker['pid']!==$revokerPid);
            $limit=hrtime(true)+1_500_000_000;
            $blocked=false;
            do {
                $wait=self::rows($observer,<<<'SQL'
SELECT pid FROM pg_catalog.pg_stat_activity
 WHERE pid=?::int AND datname=pg_catalog.current_database()
   AND wait_event_type='Lock' AND wait_event='advisory'
   AND ?::int=ANY(pg_catalog.pg_blocking_pids(pid))
SQL,[$worker['pid'],$revokerPid]);
                $blocked=count($wait)===1;
                if (!$blocked) usleep(5000); // cadence only; commit requires observed blocker
            } while (!$blocked && hrtime(true)<$limit);
            self::need($blocked && $revoker->commit());
        } finally {
            if ($revoker->inTransaction()) $revoker->rollBack();
        }
        self::need($finish($worker)===['kind'=>'discarded']);
        $proofPid=self::rows($observer,'SELECT pg_catalog.pg_backend_pid() AS pid',[])[0]['pid'];
        $observer=$openFreshObserver();
        self::need($observer instanceof PDO);
        self::identity($observer,'on');
        $freshPid=self::rows($observer,'SELECT pg_catalog.pg_backend_pid() AS pid',[])[0]['pid'];
        self::need(!in_array($freshPid,[$revokerPid,$worker['pid'],$proofPid],true));
        $after=$captureNative($observer);
        self::onlyRevoke($before,$after,$tenant,$site,$old);
        $durable=self::durable($observer,$a,$tenant,$old);
        self::need($durable['terminal']==='rejected');
        // Reconcile without a second writer or synthetic source. Readback itself
        // is observed again independently; missing ACK is never acceptance.
        $authority=new class($observer,$tenant,$old) implements DurableTerminalAuthority {
            public function __construct(private PDO $db,private string $tenant,private int $revision) {}
            public function readCommitted(Intent $prepared): ?DurablyRecordedDecision {
                RevokeFirstFixture::durable($this->db,$prepared,$this->tenant,$this->revision);
                return new DurablyRecordedDecision($prepared,Terminal::Rejected);
            }
        };
        $portal=new PortalReconciliation($authority);
        $observed=$portal->observe($a); $replay=$portal->observe($a);
        self::need($observed->outcome===Outcome::DurablyRecorded &&
            $observed->record?->terminal===Terminal::Rejected && $replay==$observed &&
            self::durable($observer,$a,$tenant,$old)===$durable &&
            self::rows($observer,'SELECT count(*)::int AS n FROM sites_onepage_terminal_candidate.decision',[])===[['n'=>1]] &&
            self::rows($observer,"SELECT count(*)::int AS n FROM sites_onepage_terminal_candidate.decision WHERE terminal='accepted'",[])===[['n'=>0]] &&
            $captureNative($observer)===$after);
    }

    private static function onlyRevoke(array $before,array $after,string $tenant,string $site,int $old): void
    {
        $key='sites_core.release_binding_events';
        $left=json_decode($before[$key],true,32,JSON_THROW_ON_ERROR);
        $right=json_decode($after[$key],true,32,JSON_THROW_ON_ERROR);
        self::need(is_array($left) && is_array($right) && count($right)===count($left)+1);
        unset($before[$key],$after[$key]);
        self::need($before===$after);
        $original=null; $next=null;
        foreach ($left as $row) {
            if ([$row['tenant_id'],$row['site_id'],$row['binding_revision'],$row['event_kind']]
                ===[$tenant,$site,$old,'bound']) $original=$row;
        }
        foreach ($right as $k=>$row) {
            if ([$row['tenant_id'],$row['site_id'],$row['binding_revision'],$row['event_kind']]
                ===[$tenant,$site,$old+1,'revoked']) {
                self::need($next===null); $next=$row; unset($right[$k]);
            }
        }
        self::need(is_array($original) && is_array($next) && array_values($right)===$left);
        foreach (['binding_revision','event_kind','actor','actor_epoch','created_at'] as $key) {
            unset($original[$key],$next[$key]);
        }
        self::need($original===$next);
    }

    public static function durable(PDO $db, Intent $a, string $tenant, int $old): array
    {
        $rows=self::rows($db,self::ROW,[$a->operationRef]);
        self::need(count($rows)===1);
        $r=$rows[0]; $t=json_decode($r['exact_tuple'],true,8,JSON_THROW_ON_ERROR);
        self::need(is_array($t) && (new Intent($r['operation_ref'],$t))->same($a) &&
            [$r['tenant_id'],$r['site_id'],(int)$r['requested_binding_revision'],
                (int)$r['observed_binding_revision'],$r['terminal']]
            ===[$tenant,$a->tuple['siteRef'],$old,$old+1,'rejected']);
        return $r;
    }

    private static function identity(PDO $db,string $readOnly): void
    {
        self::need(PHP_SAPI==='cli' && !$db->inTransaction() &&
            $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql' &&
            $db->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION);
        $r=self::rows($db,<<<'SQL'
SELECT pg_catalog.current_database() AS db,pg_catalog.host(pg_catalog.inet_server_addr()) AS host,
       pg_catalog.inet_server_port() AS port,
       pg_catalog.current_setting('server_version_num')::int AS version,
       pg_catalog.current_setting('default_transaction_isolation') AS isolation,
       pg_catalog.current_setting('default_transaction_read_only') AS readonly,
       (SELECT ssl FROM pg_catalog.pg_stat_ssl WHERE pid=pg_catalog.pg_backend_pid()) AS tls
SQL,[]);
        self::need(count($r)===1 && $r[0]['db']==='postgres' && $r[0]['host']==='127.0.0.1' &&
            $r[0]['port']===55449 && $r[0]['version']>=180000 && $r[0]['version']<190000 &&
            $r[0]['isolation']==='read committed' && $r[0]['readonly']===$readOnly && $r[0]['tls']===true);
        // This prefilter never replaces the #541 supervisor's independently
        // attested cluster/root/OID/CA and per-connection identity.
    }
    private static function rows(PDO $db,string $sql,array $params):array
    {
        $q=$db->prepare($sql); self::need($q->execute($params));
        $r=$q->fetchAll(PDO::FETCH_ASSOC); self::need($q->closeCursor()); return $r;
    }
    private static function json(Intent $i): string {return json_encode($i->tuple,JSON_THROW_ON_ERROR);}
    private static function need(bool $v):void {if(!$v) throw new RuntimeException('ONEPAGE_TERMINAL_FIXTURE_REFUSED');}
}
