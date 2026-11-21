<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\SitesIntegration\{DenyingApprovedOnepageBriefSnapshotSource,OnepageApprovedBriefExportGate,SiteContractSnapshot};
use App\Infrastructure\Commerce\{PostgresOnepageOrders,PostgresPortalSiteContractSource};
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Production\OnepageBuyerPrivatePreviewCandidate;
use PDO;
use ReflectionClass;
use RuntimeException;
use SensitiveParameter;

/** Call only at the #591 terminal native projection, before runner cleanup. */
final class Stage3M3MissingApprovedSource
{
    public static function run(Stage3SupervisedSlots $slots, PostgresConnection $portal, PostgresConnection $sites,
        PostgresOnepageOrders $reader, OnepageAcceptedOrder $order, array $visible,
        #[SensitiveParameter] string $fresh, #[SensitiveParameter] string $old,
        string $portal976, string $sites570):void
    {
        foreach ([
            [OnepageApprovedBriefExportGate::class,$portal976.'/apps/portal/app/Domain/SitesIntegration/OnepageApprovedBriefExportGate.php','58000f18eddce8cc93b7c76a8135817b9b426b07'],
            [DenyingApprovedOnepageBriefSnapshotSource::class,$portal976.'/apps/portal/app/Domain/SitesIntegration/DenyingApprovedOnepageBriefSnapshotSource.php','60b1efa325b7859256c5ab2683fbac525b289804'],
            [OnepageBuyerPrivatePreviewCandidate::class,$sites570.'/src/Production/OnepageBuyerPrivatePreviewCandidate.php','571838eefc9c4872515c5c2a2c8453328febd47b'],
        ] as [$class,$path,$blob]) {
            self::need(class_exists($class,false) && realpath($path)===$path
                && (new ReflectionClass($class))->getFileName()===$path);
            $bytes=file_get_contents($path,false,null,0,65537);
            self::need(is_string($bytes) && strlen($bytes)<=65536
                && sha1('blob '.strlen($bytes)."\0".$bytes)===$blob);
        }
        $pa=$slots->beforeDdl('portal_native_orders_ci',$portal);
        $sa=$slots->beforeDdl('portal_sites_pair_ci',$sites);
        $native=$reader->findOwnedAcceptedOrder($fresh,$order->id);
        self::need($native instanceof OnepageAcceptedOrder && get_object_vars($native)===get_object_vars($order));
        $site=$visible['siteRef']??null;
        self::need(is_string($site) && preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$site)===1
            && ($visible['status']??null)==='recorded' && ($visible['canPublish']??null)===false
            && self::sameProjection($reader->findOwnedSiteIntake($fresh,$native->id),$visible)
            && $reader->findOwnedSiteIntake($old,$native->id)===null);
        $before=self::state($pa,$sa);
        $contracts=new PostgresPortalSiteContractSource($reader,$fresh);
        $contract=$contracts->findAuthorizedSiteOrder($native->id);
        self::need($contract instanceof SiteContractSnapshot && $contract->tenantRef===$native->companyReference
            && $contract->orderRef===$native->id && $contract->contractRef===$native->contractRef
            && $contract->contractRevision===$native->contractRevision && $contract->productCode==='onepage');
        $selector=1; // attempted selection only, never a trusted revision
        $missing=new DenyingApprovedOnepageBriefSnapshotSource();
        self::need($missing->readExact($native->id,$site,$selector)===null);
        $gate=new OnepageApprovedBriefExportGate($contracts,$missing);
        $downstreamCalls=0;
        $downstream=static function(array $unused)use(&$downstreamCalls):never {
            ++$downstreamCalls;
            throw new RuntimeException('STAGE3_M3_DOWNSTREAM_MUST_NOT_RUN');
        };
        self::denies(
            static fn()=>$downstream($gate->prepare($native->id,$site,$selector)),
            'ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED');
        self::need($downstreamCalls===0);
        self::denies(static fn()=>(new OnepageBuyerPrivatePreviewCandidate())
            ->render($native->buyerId,$native->companyReference,$native->id,$site,$selector),
            'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED');
        self::need($slots->beforeDdl('portal_native_orders_ci',$portal)===$pa
            && $slots->beforeDdl('portal_sites_pair_ci',$sites)===$sa
            && self::state($pa,$sa)===$before
            && self::sameProjection($reader->findOwnedSiteIntake($fresh,$native->id),$visible)
            && $reader->findOwnedSiteIntake($old,$native->id)===null);
    }

    private static function state(PDO $p, PDO $s):array
    {
        // Fixed relations from the #591 pinned schema; missing relations are errors, not zero.
        $rows=[];
        foreach ([
            [$p,'portal_onepage.entry_confirmations',1],[$p,'onepage_finance_lab.observations',1],
            [$p,'portal_onepage.site_intake_receipts',1],[$s,'sites_core.site_projects',1],
            [$s,'sites_core.portal_project_intakes',1],[$s,'sites_core.site_outbox',1],
            [$s,'sites_preview.versions',0],[$s,'sites_preview.reviews',0],[$s,'sites_core.release_artifacts',0],
            [$s,'sites_core.release_binding_events',0],
        ] as [$db,$relation,$count]) {
            $q=$db->query("SELECT to_jsonb(t)::text FROM $relation t");
            self::need($q!==false);
            $v=$q->fetchAll(PDO::FETCH_COLUMN);
            self::need(count($v)===$count);
            $rows[$relation]=$v;
        }
        self::need(($s->query("SELECT delivery_state FROM sites_core.site_outbox")->fetchColumn())==='blocked');
        return $rows;
    }

    private static function sameProjection(?array $actual,array $expected):bool
    {
        if($actual===null) return false;
        ksort($actual); ksort($expected);
        return $actual===$expected;
    }
    private static function denies(\Closure $action,string $code):void
    {
        try { $action(); } catch(RuntimeException $e) {
            self::need($e->getMessage()===$code);
            return;
        }
        self::need(false);
    }
    private static function need(bool $ok):void
    {
        if(!$ok) throw new RuntimeException('STAGE3_M3_DEFAULT_DENY_REFUSED');
    }
}
