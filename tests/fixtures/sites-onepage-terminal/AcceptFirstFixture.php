<?php
declare(strict_types=1);

namespace OnepageSitesTerminalDraft;

use Closure;
use PDO;
use RuntimeException;

/** Private disposable #603 sibling fixture. Never load in a product route. */
final class AcceptFirstFixture
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

    private const ACCEPTED_ROW = <<<'SQL'
SELECT operation_ref,tenant_id::text AS tenant_ref,site_id::text AS site_ref,
       requested_binding_revision::text AS requested_revision,
       observed_binding_revision::text AS observed_revision,
       exact_tuple::text AS tuple_json,terminal,recorded_at::text AS committed_at
FROM sites_onepage_terminal_candidate.decision WHERE operation_ref=?
SQL;
    private const N = 'SELECT count(*)::int FROM sites_onepage_terminal_candidate.decision';
    private const INSERT = <<<'SQL'
INSERT INTO sites_onepage_terminal_candidate.decision
(operation_ref,tenant_id,site_id,requested_binding_revision,observed_binding_revision,exact_tuple,terminal)
VALUES (?,?::uuid,?::uuid,?::bigint,?::bigint,?::jsonb,'accepted')
SQL;

    /**
     * Entrypoint exclusively inside #603's admitted disposable runtime.
     * Caller must bootstrap isolated fixtures, admit the native revoker and its distinct role,
     * and construct $minted independently of $submitted (do not copy from it).
     * $nativeCapture must be the pinned scenario's full native capture. No publish callable.
     */
    public static function run(SitesTlsAdmission $gate,PDO $revoker,string $tenant,
        Intent $submitted,Intent $minted,string $exactHtml,Closure $nativeCapture):void
    {
        $t=$submitted->tuple;
        $site=$t['siteRef']; $rev=$t['bindingRevision'];
        self::need($rev<PHP_INT_MAX && !$revoker->inTransaction() && $submitted->same($minted));
        $write=$gate->openBootstrap(false);
        $before=$gate->openBootstrap(true);
        $pids=[$gate->assertBootstrap($write),$gate->assertBootstrap($before),
            (int)self::q($revoker,'SELECT pg_catalog.pg_backend_pid()')->fetchColumn()];
        self::need(count(array_unique($pids))===3 && self::count($before)===0);
        $nativeBefore=$nativeCapture($before);

        // The pure machine must not turn a current binding into consent by itself.
        self::need(self::propose($submitted,null)->outcome===Outcome::Pending);
        $wrongAction=$minted->tuple;
        $wrongAction['actionRef']=$wrongAction['actionRef']==='fixture-mismatch-a'
            ? 'fixture-mismatch-b' : 'fixture-mismatch-a';
        self::need(self::propose($submitted,new Intent($minted->operationRef,$wrongAction))->outcome===Outcome::Pending &&
            self::count($before)===0);
        self::need($write->beginTransaction());
        try {
            self::q($write,self::LOCK,[$tenant,$site])->closeCursor();
            self::need(self::isCurrentExact($write,$tenant,$submitted,$exactHtml));
            $proposal=self::propose($submitted,$minted);
            self::need($proposal->outcome===Outcome::Proposed &&
                $proposal->proposal?->terminal===Terminal::Accepted);
            $insert=self::q($write,self::INSERT,[$submitted->operationRef,$tenant,$site,$rev,$rev,
                json_encode($t,JSON_THROW_ON_ERROR)]);
            self::need($insert->rowCount()===1 && $insert->closeCursor() && $write->commit());
        } finally {
            if ($write->inTransaction()) $write->rollBack();
        }

        // Explicit barrier: the native revoke is not even invoked before fresh committed proof.
        $accepted=self::fresh($gate,$pids);
        self::need(self::count($accepted)===1 && $nativeCapture($accepted)===$nativeBefore);
        $history=self::acceptedRow($accepted,$submitted,$tenant,$rev);
        self::need($history['terminal']==='accepted' &&
            is_string($history['committed_at']) && $history['committed_at']!=='');
        self::need((int)self::q($revoker,'SELECT sites_core.revoke_release_binding(?::uuid,?::bigint)',
            [$site,$rev])->fetchColumn()===$rev+1 && !$revoker->inTransaction());

        $after=self::fresh($gate,$pids);
        self::need(self::count($after)===1 &&
            self::acceptedRow($after,$submitted,$tenant,$rev)===$history);
        self::onlyRevoke($nativeBefore,$nativeCapture($after),$tenant,$site,$rev);

        // The unchanged formerly accepted tuple still cannot clear live Sites preflight.
        // No grant, output receipt or executor exists in this test-only module.
        self::need($write->beginTransaction());
        try {
            self::q($write,self::LOCK,[$tenant,$site])->closeCursor();
            $head=self::q($write,self::HEAD,[$tenant,$site])->fetchAll(PDO::FETCH_ASSOC);
            self::need(count($head)===1 && (int)$head[0]['binding_revision']===$rev+1 &&
                $head[0]['event_kind']==='revoked' && $head[0]['release_ref']===$t['releaseRef'] &&
                $head[0]['artifact_digest']===$t['htmlSha256']);
            self::need(!self::isCurrentExact($write,$tenant,$submitted,$exactHtml));
        } finally {
            if ($write->inTransaction()) $write->rollBack();
        }
        self::need(self::count($after)===1 &&
            self::acceptedRow($after,$submitted,$tenant,$rev)===$history);
    }

    /** Synthetic immutable witness is effective only in this fixture, not Portal auth. */
    public static function propose(Intent $requested,?Intent $minted):Result
    {
        $authority=new class($minted) implements DecisionAuthority {
            public function __construct(private readonly ?Intent $minted) {}
            public function observeUnderDecisionLock(Intent $intent):Verdict {
                return $this->minted?->same($intent)===true ? Verdict::Eligible : Verdict::Unknown;
            }
            public function mayReadTerminal(Intent $intent):bool { return false; }
        };
        return (new SitesTerminalMachine($authority))->decide($requested,null);
    }

    private static function fresh(SitesTlsAdmission $gate,array &$pids):PDO
    {
        $db=$gate->openBootstrap(true); $pid=$gate->assertBootstrap($db);
        self::need(!in_array($pid,$pids,true)); $pids[]=$pid;
        return $db;
    }

    private static function isCurrentExact(PDO $db,string $tenant,Intent $intent,string $html):bool
    {
        $t=$intent->tuple; $site=$t['siteRef']; $rev=$t['bindingRevision'];
        $h=self::q($db,self::HEAD,[$tenant,$site])->fetchAll(PDO::FETCH_ASSOC);
        if (count($h)!==1 || [(int)$h[0]['binding_revision'],$h[0]['event_kind'],$h[0]['release_ref'],
            $h[0]['artifact_digest'],(int)$h[0]['editorial_revision']]!==
            [$rev,'bound',$t['releaseRef'],$t['htmlSha256'],$t['editorialRevision']]) return false;
        $x=self::q($db,self::REQUESTED,[$tenant,$site,$rev])->fetchAll(PDO::FETCH_ASSOC);
        if (count($x)!==1) return false;
        $x=$x[0];
        if ([$x['portal_org_ref'],$x['site_id'],$x['release_ref'],$x['order_ref'],$x['contract_ref'],
            (int)$x['contract_revision'],$x['command_digest'],(int)$x['editorial_revision'],
            $x['artifact_digest'],$x['snapshot_ref'],(int)$x['brief_revision'],$x['manifest_sha256'],
            (int)$x['source_version'],$x['product_code'],$x['event_kind']]!==
            [$t['tenantRef'],$site,$t['releaseRef'],$t['orderRef'],$t['contractRef'],$t['contractRevision'],
            $t['commandDigest'],$t['editorialRevision'],$t['htmlSha256'],$t['snapshotRef'],
            $t['briefRevision'],$t['manifestSha256'],$t['sourceVersion'],'onepage','bound']) return false;
        $bytes=self::q($db,'SELECT html FROM sites_core.release_artifacts WHERE tenant_id=?::uuid AND site_id=?::uuid AND release_ref=?',
            [$tenant,$site,$t['releaseRef']])->fetchColumn();
        return $html!=='' && $bytes===$html && hash('sha256',$html)===$t['htmlSha256'];
    }

    private static function onlyRevoke(array $before,array $after,string $tenant,string $site,int $rev):void
    {
        $k='sites_core.release_binding_events';
        $old=json_decode($before[$k],true,32,JSON_THROW_ON_ERROR);
        $new=json_decode($after[$k],true,32,JSON_THROW_ON_ERROR);
        unset($before[$k],$after[$k]);
        self::need($before===$after && array_is_list($old) && array_is_list($new) && count($new)===count($old)+1);
        $orig=$diff=null;
        foreach ($old as $r) if ([$r['tenant_id'],$r['site_id'],$r['binding_revision'],$r['event_kind']]
            ===[$tenant,$site,$rev,'bound']) $orig=$r;
        foreach ($new as $i=>$r) if ([$r['tenant_id'],$r['site_id'],$r['binding_revision'],$r['event_kind']]
            ===[$tenant,$site,$rev+1,'revoked']) { self::need($diff===null); $diff=$r; unset($new[$i]); }
        self::need(is_array($orig) && is_array($diff) && array_values($new)===$old);
        foreach (['binding_revision','event_kind','actor','actor_epoch','created_at'] as $key)
            { unset($orig[$key],$diff[$key]); }
        self::need($orig===$diff);
    }
    /** Fixed positive reader. The revoke-first reader remains unchanged and REJECTED-only. */
    private static function acceptedRow(PDO $observer,Intent $want,string $tenant,int $rev):array
    {
        self::need(!$observer->inTransaction());
        $rows=self::q($observer,self::ACCEPTED_ROW,[$want->operationRef])->fetchAll(PDO::FETCH_ASSOC);
        self::need(count($rows)===1);
        $r=$rows[0];
        self::need([$r['operation_ref'],$r['tenant_ref'],$r['site_ref'],$r['requested_revision'],
            $r['observed_revision'],$r['terminal']]===
            [$want->operationRef,$tenant,$want->tuple['siteRef'],(string)$rev,(string)$rev,'accepted'] &&
            is_string($r['committed_at']) && $r['committed_at']!=='' && is_string($r['tuple_json']));
        $t=json_decode($r['tuple_json'],true,8,JSON_THROW_ON_ERROR);
        self::need(is_array($t) && (new Intent($r['operation_ref'],$t))->same($want));
        return $r;
    }

    private static function count(PDO $db):int { return (int)self::q($db,self::N)->fetchColumn(); }
    private static function q(PDO $db,string $sql,array $args=[]):\PDOStatement {
        $q=$db->prepare($sql); self::need($q!==false && $q->execute($args)); return $q;
    }
    private static function need(bool $ok):void { if (!$ok) throw new RuntimeException('ACCEPT_FIRST_FIXTURE_REFUSED'); }
}
