<?php

declare(strict_types=1);

use Imagine\Sites\Domain\SyntheticIdempotencyLedger;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\SyntheticSitesOrderIngress;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticSignedCommand;

spl_autoload_register(static function (string $class): void {
    foreach ([
        'Imagine\\Sites\\Security\\' => 'Security',
        'Imagine\\Sites\\Integration\\' => 'Integration',
        'Imagine\\Sites\\Production\\' => 'Production',
        'Imagine\\Sites\\Domain\\' => 'Domain',
    ] as $prefix => $dir) {
        if (!str_starts_with($class,$prefix)) continue;
        $short = substr($class,strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
            throw new RuntimeException('Invalid synthetic test class.');
        }
        require __DIR__.'/../src/'.$dir.'/'.$short.'.php';
        return;
    }
});

final class F357Replay implements ReplayStore
{
    private array $used = [];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt): bool
    {
        $key=$issuer.':'.$nonce;
        if(isset($this->used[$key]))return false;
        $this->used[$key]=true;
        return true;
    }
}

$checks=0;
function f357Check(bool $valid,string $label): void
{
    global $checks;
    if(!$valid)throw new RuntimeException('F1-357: '.$label);
    ++$checks;
}
function f357Denied(callable $run):bool
{
    try {$run();}catch(DomainException|InvalidArgumentException|LogicException){return true;}
    return false;
}

$now=1790000000;
$cmdKey='F357-COMMAND-SIGNING-KEY-FOR-FAKE-ORDERS-0001';
$portalKey='F357-PORTAL-AUTHORITY-KEY-FOR-FAKE-ORDERS-02';
$externalKey='F357-EXTERNAL-AUTHORITY-KEY-FOR-FAKE-ORDERS03';
$ledger=new SyntheticIdempotencyLedger();
$replay=new F357Replay();
function f357Proofs(
    SiteProjectCommand $command,
    bool $externalActive=true
): array {
    global $now,$portalKey,$externalKey;
    $snapshot=new SyntheticAuthoritySnapshot(
        $command->tenantRef,$command->contractRef,
        $command->contractRevision,true,$now-1,$now+20
    );
    $external=new SyntheticAuthoritySnapshot(
        $command->tenantRef,$command->contractRef,
        $command->contractRevision,$externalActive,$now-1,$now+20
    );
    return [
        SyntheticAuthorityAttestation::sign(
            'imagine-portal-authority',$snapshot,$portalKey),
        SyntheticAuthorityAttestation::sign(
            'imagine-independent-revocation',$external,$externalKey)
    ];
}
function f357Wire(SiteProjectCommand $command,string $nonce):array
{
    global $now,$cmdKey;
    return SyntheticSignedCommand::sign(
        $command,$cmdKey,$nonce,$now-1,$now+60
    );
}
function f357Receive(
    array $wire,array $portal,array $external,
    SyntheticIdempotencyLedger $ledger,F357Replay $replay,
    string $site='site-f357-001',string $workflow='workflow-f357-001'
):array {
    global $now,$cmdKey,$portalKey,$externalKey;
    return SyntheticSitesOrderIngress::receive(
        $wire,$portal,$external,$cmdKey,$portalKey,$externalKey,
        $now,$replay,$ledger,$site,$workflow
    );
}

$onepage=new SiteProjectCommand(
    '1','op-f357-001','tenant-f357-a','order-f357-001',
    'contract-f357-001',3,'onepage','idem-f357-001'
);
[$portal,$external]=f357Proofs($onepage);
$first=f357Receive(
    f357Wire($onepage,'nonce-f357-00001'),$portal,$external,$ledger,$replay
);
f357Check($first['status']==='queued' && $first['publishable']===false,
    'signed Portal order queues exactly one nonpublishable Onepage');
f357Check($first['workflow'] instanceof SyntheticProductionWorkflow &&
    $first['workflow']->state===SyntheticProductionWorkflow::QUEUED,
    'new project starts queued, not published or client-approved');

$digest=hash('sha256','synthetic offline Onepage draft bytes');
$flow=$first['workflow']->advance('agent','start_draft',0);
f357Check($flow->state===SyntheticProductionWorkflow::DRAFTING,
    'authorized fictional agent begins draft');
$flow=$flow->advance('agent','submit_draft',1,$digest);
f357Check($flow->state===SyntheticProductionWorkflow::INTERNAL_REVIEW,
    'synthetic draft enters internal Imagine review');
$flow=$flow->advance('imagine_reviewer','approve_internal',2,$digest);
f357Check($flow->state===SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE,
    'Imagine internal reviewer can only request Portal acceptance');
$proposal=$flow->portalReviewCandidate('release-f357-001',$digest,3);
f357Check($proposal['publishable']===false &&
    $proposal['officialClientAcceptance']===false &&
    $proposal['portalAuthorityRequired']===true &&
    $proposal['artifactDigest']===$digest,
    'exact digest submitted to Portal as NONpublishable review candidate');
f357Check(f357Denied(
    static fn()=>$flow->portalReviewCandidate('release-f357-001',str_repeat('0',64),3)),
    'changed bytes cannot silently receive the same client review proposal');

$retry=f357Receive(
    f357Wire($onepage,'nonce-f357-00002'),$portal,$external,$ledger,$replay
);
f357Check($retry['status']==='duplicate_ignored' &&
    $retry['workflow']===null && $retry['publishable']===false,
    'new signed delivery of same idempotency key cannot enqueue twice');
f357Check(f357Denied(static fn()=>f357Receive(
    f357Wire($onepage,'nonce-f357-00002'),$portal,$external,$ledger,$replay)),
    'replayed duplicate envelope still rejected by nonce fence');

$newVersion=new SiteProjectCommand(
    '1','op-f357-002','tenant-f357-a','order-f357-001',
    'contract-f357-001',4,'onepage','idem-f357-001'
);
[$portal4,$external4]=f357Proofs($newVersion);
f357Check(f357Denied(static fn()=>f357Receive(
    f357Wire($newVersion,'nonce-f357-00003'),
    $portal4,$external4,$ledger,$replay)),
    'reusing idempotency key with changed contract revision is conflict');

$landing=new SiteProjectCommand(
    '1','op-f357-004','tenant-f357-b','order-f357-004',
    'contract-f357-004',1,'landing_page','idem-f357-001'
);
[$portalB,$externalB]=f357Proofs($landing);
$second=f357Receive(
    f357Wire($landing,'nonce-f357-00004'),$portalB,$externalB,
    $ledger,$replay,'site-f357-004','workflow-f357-004'
);
f357Check($second['status']==='queued' &&
    $second['workflow']->tenantRef==='tenant-f357-b',
    'same idempotency key in another authorized tenant is separately scoped');

$revokedCmd=new SiteProjectCommand(
    '1','op-f357-005','tenant-f357-a','order-f357-005',
    'contract-f357-005',1,'onepage','idem-f357-005'
);
[$portalR,$externalDenied]=f357Proofs($revokedCmd,false);
f357Check(f357Denied(static fn()=>f357Receive(
    f357Wire($revokedCmd,'nonce-f357-00005'),
    $portalR,$externalDenied,$ledger,$replay)),
    'a signed revoked contract never creates a project');
[, $externalCurrent]=f357Proofs($revokedCmd,true);
$goodAfterDenied=f357Receive(
    f357Wire($revokedCmd,'nonce-f357-00006'),
    $portalR,$externalCurrent,$ledger,$replay,
    'site-f357-005','workflow-f357-005'
);
f357Check($goodAfterDenied['status']==='queued',
    'denied command did not poison synthetic project idempotency');

$invalidCmd=new SiteProjectCommand(
    '1','op-f357-007','tenant-f357-a','order-f357-007',
    'contract-f357-007',1,'onepage','idem-f357-007'
);
[$portalInvalid,$externalInvalid]=f357Proofs($invalidCmd);
f357Check(f357Denied(static fn()=>f357Receive(
    f357Wire($invalidCmd,'nonce-f357-00007'),
    $portalInvalid,$externalInvalid,$ledger,$replay,'site with spaces')),
    'invalid site ref denied before claiming idempotency key');
$recovered=f357Receive(
    f357Wire($invalidCmd,'nonce-f357-00008'),
    $portalInvalid,$externalInvalid,$ledger,$replay,
    'site-f357-007','workflow-f357-007'
);
f357Check($recovered['status']==='queued',
    'valid retry after malformed site still queues one project');
f357Check(f357Denied(static fn()=>$flow->advance(
    'customer','approve_internal',3,$digest)),
    'client cannot impersonate Imagine reviewer in workflow');

echo "$checks F1-357 signed contract to queued project and private preview checks passed.\n";
echo "NO-GO: simulated orders, in-memory idempotency, fictional reviewers; no commercial payment, customer acceptance, publication or durable outbox.\n";
