<?php
declare(strict_types=1);

namespace SitesStage3CiOnly;

use App\Domain\SitesIntegration\ApprovedOnepageBriefSnapshot as Snapshot;
use App\Domain\SitesIntegration\ApprovedOnepageBriefSnapshotSource;
use App\Domain\SitesIntegration\CurrentOnepageBriefApprovalWitness;
use App\Domain\SitesIntegration\OnepageApprovedBriefExportGate;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use Imagine\Sites\Production\BriefSnapshotInput;
use RuntimeException;
use Closure;

/** CI fixture only. Never use as application authority or as an HTTP handler. */
function assertExactSyntheticApprovedBrief(
    PortalSiteContractSource $nativeBuyerContract,
    SiteContractSnapshot $attestedContract,
    string $attestedSite,
    ?Closure $afterVerifiedA = null,
): void {
    // M2 caller supplies contract/site only from the #591 qualified native
    // read and independently correlated physical ledgers; not request data.
    $need = static function (bool $ok): void {
        if (!$ok) throw new RuntimeException('STAGE3_EXACT_BRIEF_FIXTURE_FAILED');
    };
    $a = <<<'JSON'
{"company_name":"Oficina Sintética","company_intro":"Amostra fictícia.","services":"Documentação.","current_site":"","contact_site":"","domain":"","email":"oficina@example.invalid","phone":"","address":"","whatsapp":"","notes":"Revisão A","ai_support":false,"logo_unavailable":true,"manual_unavailable":true}
JSON;
    $a .= "\n";
    $b = str_replace('Revisão A', 'Revisão B', $a);
    $hashA = '4887417fdeaf589129602753a2fc31e8426eace8205b4f75cc980dc25ff9298c';
    $hashB = '5ad651f291a151194e44c6acd879183c1cb731e8942ce970e04d00c61ddfb14b';
    $selection = static fn (int $n): array => [
        'siteRef'=>$attestedSite, 'sourceVersion'=>$n,
        'snapshotRef'=>$n===12 ? '22222222-2222-4222-8222-222222222222' : '33333333-3333-4333-8333-333333333333',
        'operationId'=>"synthetic-approved-brief-$n", 'idempotencyKey'=>"synthetic-approved-key-$n",
        'expectedBriefRevision'=>$n-12,'payloadRef'=>"synthetic-brief-bytes-$n",
    ];
    $order = $attestedContract->orderRef;
    $repo = new class($order,$attestedSite,[
        12=>new Snapshot($attestedContract,$selection(12),$a,$hashA,'synthetic-editorial-A',1),
        13=>new Snapshot($attestedContract,$selection(13),$b,$hashB,'synthetic-editorial-B',2),
    ]) implements ApprovedOnepageBriefSnapshotSource {
        public function __construct(private string $order, private string $site, private array $archive) {}
        public function readExact(string $orderRef,string $siteRef,int $sourceVersion): ?Snapshot {
            return $orderRef===$this->order && $siteRef===$this->site ? ($this->archive[$sourceVersion]??null) : null;
        }
    };
    $selectorA = $selection(12);
    $selectorB = $selection(13);
    // Independent, predetermined fixture facts; never derive approval from
    // successful source read, candidate flags or portal pending status.
    $witness = new class($attestedContract,[
        [$selectorA,$hashA,'synthetic-editorial-A',1],
        [$selectorB,$hashB,'synthetic-editorial-B',2],
    ]) implements CurrentOnepageBriefApprovalWitness {
        public ?int $current = 0;
        public bool $revokeOnNextPositive = false;
        public int $positive = 0;
        public function __construct(private SiteContractSnapshot $link, private array $facts) {}
        public function isCurrent(Snapshot $brief): bool {
            if ($this->current===null || $brief->contract != $this->link ||
                $this->facts[$this->current] !== [$brief->selection,$brief->sourceSha256,$brief->approvalRef,$brief->approvalEpoch]) {
                return false;
            }
            ++$this->positive;
            if ($this->revokeOnNextPositive) $this->current = null;
            return true;
        }
    };
    $gate = new OnepageApprovedBriefExportGate($nativeBuyerContract,$repo,$witness);
    $send = static fn(int $n) => $gate->prepare($order,$attestedSite,$n);
    $deny = static function (callable $read) use ($need): void {
        try { $read(); }
        catch (RuntimeException $e) {
            $need($e->getMessage()==='ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED' && $e->getPrevious()===null);
            return;
        }
        $need(false);
    };
    $check = static function (array $pair,string $bytes,string $hash,array $selector) use ($need,$attestedContract): BriefSnapshotInput {
        $row = BriefSnapshotInput::fromJson($pair['manifestBytes'],$pair['sourceBytes']);
        $need($row->sourceJson===$bytes && $pair['sourceBytes']===$bytes &&
            $row->payloadSha256===$hash && $pair['payloadSha256']===$hash &&
            $row->manifestSha256===$pair['manifestSha256'] &&
            $row->tenantRef===$attestedContract->tenantRef && $row->orderRef===$attestedContract->orderRef &&
            $row->contractRef===$attestedContract->contractRef && $row->contractRevision===$attestedContract->contractRevision &&
            $row->siteRef===$selector['siteRef'] && $row->snapshotRef===$selector['snapshotRef'] &&
            $row->sourceVersion===$selector['sourceVersion'] && $row->briefRevision===$selector['expectedBriefRevision']+1);
        foreach (['authorityVerified','clientAccepted','canPublish','canAcceptRealOrders'] as $key) $need($pair[$key]===false);
        return $row;
    };
    $verifiedA = $check($send(12),$a,$hashA,$selectorA);
    $need($witness->positive===2);
    // Read-only witness: callers cannot move the fixture epoch or consume checks.
    if ($afterVerifiedA !== null) $afterVerifiedA($verifiedA, static fn(): bool => $witness->current===0);
    $deny(static fn()=>$send(14));
    $deny(static fn()=>$send(13)); // stored but not yet current; never latest
    $witness->revokeOnNextPositive=true;
    $deny(static fn()=>$send(12)); // revoke between #976 witness reads
    $need($witness->positive===3 && $repo->readExact($order,$attestedSite,12)?->sourceBytes===$a);
    $deny(static fn()=>$send(12)); // historical fixture row still present
    $witness->revokeOnNextPositive=false;
    $witness->current=1;
    $deny(static fn()=>$send(12)); // never silently fall forward to B
    $check($send(13),$b,$hashB,$selectorB);
    $need($witness->positive===5);
}
