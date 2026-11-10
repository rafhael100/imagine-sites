<?php

declare(strict_types=1);

/**
 * F1-102: no-network candidate Portal project authority snapshot.
 * This test never authenticates a user or authorizes customer publication.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $relative=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Integration)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('Unsafe snapshot test class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationSnapshot as Snapshot;
use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationSource as Source;
use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationEpochSource as EpochSource;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\SnapshotBoundPortalProjectAuthorityGateway as Gateway;

final class F102FictionalSource implements Source
{
    public ?Snapshot $current=null;
    public bool $fail=false;
    public int $calls=0;

    public function findCurrentFor(string $tenantRef,string $orderRef):?Snapshot
    {
        ++$this->calls;
        if ($this->fail) throw new RuntimeException('Fictitious Portal unavailable');
        return $this->current;
    }
}

/** Separate fictional epoch provider. In production, this MUST be trusted. */
final class F105FictionalEpochSource implements EpochSource
{
    public ?int $epoch=3;
    public bool $fail=false;
    public int $calls=0;

    public function currentEpochFor(string $tenantRef,string $orderRef):?int
    {
        ++$this->calls;
        if ($this->fail) throw new RuntimeException('Fictitious epoch unavailable');
        if ($tenantRef !== 'tenant-f102' || $orderRef !== 'order-f102') return null;
        return $this->epoch;
    }
}

$checks=0;
function f102Check(bool $ok,string $description):void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL F1-102 '.$description);
    ++$checks;
    echo "PASS F1-102 $description\n";
}
function f102Snapshot(
    SiteProjectCommand $command, string $status, int $checkedAt, int $validUntil
):Snapshot {
    return new Snapshot($command,3,$status,$checkedAt,$validUntil);
}
function f102Invalid(callable $fn):bool
{
    try { $fn(); } catch (InvalidArgumentException) { return true; }
    return false;
}

$command = new SiteProjectCommand(
    '1','operation-f102','tenant-f102','order-f102',
    'contract-f102',3,'onepage','idempotency-f102'
);
$source=new F102FictionalSource();
$epochSource=new F105FictionalEpochSource();
$gateway=new Gateway($source,$epochSource);
$now=time();
$source->current=f102Snapshot($command,'eligible',$now-1,$now+20);
$previousEnv=getenv('APP_ENV');
$previousFlag=getenv('SITES_F102_PROJECT_SNAPSHOT_LAB');

try {
    putenv('APP_ENV=testing');
    putenv('SITES_F102_PROJECT_SNAPSHOT_LAB');
    $priorCalls=$source->calls;
    f102Check(!$gateway->verify($command)->allowed &&
        $source->calls===$priorCalls,
        'the snapshot adapter is disabled by default before consulting any source');

    putenv('SITES_F102_PROJECT_SNAPSHOT_LAB=1');
    f102Check((new Gateway())->verify($command)->allowed===false,
        'the default source denies, even in a testing laboratory');

    f102Check(!(new Gateway($source))->verify($command)->allowed,
        'F1-105 an available snapshot without a separate epoch provider cannot authorize');
    f102Check($epochSource->calls===0,
        'F1-105 disabled/default gateway does not read the epoch provider');

    $policy=(new SiteProjectAdmission($gateway))->evaluate($command);
    f102Check($policy->allowed && $policy->reasons===[],
        'matching current fictional Portal snapshot allows only candidate project admission');
    f102Check(!(new SiteProjectAdmission($gateway))->evaluate(
        $command,['privacy_pending']
    )->allowed, 'an independent blocker still denies the candidate admission');

    $fields=[
        'operationId'=>'operation-other',
        'tenantRef'=>'tenant-other',
        'orderRef'=>'order-other',
        'contractRef'=>'contract-other',
        'contractRevision'=>4,
        'productCode'=>'landing_page',
        'idempotencyKey'=>'idempotency-other',
    ];
    foreach($fields as $field=>$changed){
        $data=[
            'schemaVersion'=>$command->schemaVersion,
            'operationId'=>$command->operationId,
            'tenantRef'=>$command->tenantRef,
            'orderRef'=>$command->orderRef,
            'contractRef'=>$command->contractRef,
            'contractRevision'=>$command->contractRevision,
            'productCode'=>$command->productCode,
            'idempotencyKey'=>$command->idempotencyKey,
        ];
        $data[$field]=$changed;
        $different=SiteProjectCommand::fromArray($data);
        f102Check(!$gateway->verify($different)->allowed,
            'changed '.$field.' cannot inherit another project grant');
    }
    foreach(['revoked','suspended'] as $status) {
        $source->current=f102Snapshot($command,$status,$now-1,$now+20);
        f102Check(!$gateway->verify($command)->allowed,
            'current but '.$status.' Portal status must deny');
    }
    $source->current=f102Snapshot($command,'eligible',$now-20,$now-1);
    f102Check(!$gateway->verify($command)->allowed,
        'an expired snapshot denies even when the command still matches');
    $source->current=f102Snapshot($command,'eligible',$now+5,$now+20);
    f102Check(!$gateway->verify($command)->allowed,
        'a future checkedAt cannot authorize the current command');
    f102Check(f102Invalid(fn()=>new Snapshot(
        $command,3,'eligible',$now-1,$now+31
    )), 'a snapshot with over 30 seconds TTL is rejected at construction');
    f102Check(f102Invalid(fn()=>new Snapshot(
        $command,0,'eligible',$now-1,$now+20
    )), 'an invalid authority revision cannot create a snapshot');
    f102Check(f102Invalid(fn()=>new Snapshot(
        $command,3,'approved',$now-1,$now+20
    )), 'an approval-like state is not a project grant status');

    $source->current=null;
    f102Check(!$gateway->verify($command)->allowed,
        'missing authoritative snapshot fails closed');
    $source->fail=true;
    f102Check(!$gateway->verify($command)->allowed,
        'source exception fails closed without privileged fallback');
    $source->fail=false;
    $source->current=f102Snapshot($command,'eligible',$now-1,$now+20);
    f102Check($gateway->verify($command)->allowed,
        'a newly retrieved matching active snapshot is evaluated independently');

    // F1-105: a previously eligible revision 3 stays short-lived but is
    // rejected immediately when the independent epoch moves forward.
    $epochSource->epoch=4;
    f102Check(!$gateway->verify($command)->allowed,
        'F1-105 newer revocation epoch fences an otherwise unexpired eligible snapshot');
    $epochSource->epoch=2;
    f102Check(!$gateway->verify($command)->allowed,
        'F1-105 older epoch cannot justify a newer-looking grant');
    $epochSource->epoch=null;
    f102Check(!$gateway->verify($command)->allowed,
        'F1-105 unavailable epoch fails closed');
    $epochSource->epoch=0;
    f102Check(!$gateway->verify($command)->allowed,
        'F1-105 invalid zero epoch fails closed');
    $epochSource->fail=true;
    f102Check(!$gateway->verify($command)->allowed,
        'F1-105 epoch provider exception cannot grant');
    $epochSource->fail=false;

    // A matching fresh snapshot and epoch may authorize the same command
    // again; an epoch number alone is never a client acceptance token.
    $source->current=new Snapshot($command,4,'eligible',$now-1,$now+20);
    $epochSource->epoch=4;
    f102Check($gateway->verify($command)->allowed,
        'F1-105 only a matching fresh eligible revision and current epoch can pass');
    $source->current=new Snapshot($command,4,'revoked',$now-1,$now+20);
    f102Check(!$gateway->verify($command)->allowed,
        'F1-105 matching epoch never overrides revoked snapshot status');

    // F1-106: strictly decode the candidate wire shape, before a trusted
    // source adapter could ever consider any identity or grant evidence.
    $wire=[
        'schemaVersion'=>'sites.portal-project-authorization.v1',
        'boundCommand'=>[
            'schemaVersion'=>$command->schemaVersion,
            'operationId'=>$command->operationId,
            'tenantRef'=>$command->tenantRef,
            'orderRef'=>$command->orderRef,
            'contractRef'=>$command->contractRef,
            'contractRevision'=>$command->contractRevision,
            'productCode'=>$command->productCode,
            'idempotencyKey'=>$command->idempotencyKey,
        ],
        'authorizationRevision'=>4,
        'status'=>'eligible',
        'checkedAt'=>$now-1,
        'validUntil'=>$now+20,
    ];
    $decoded=Snapshot::fromArray(json_decode(
        json_encode($wire,JSON_THROW_ON_ERROR),true,16,JSON_THROW_ON_ERROR
    ));
    f102Check($decoded->isCurrentFor($command,$now) &&
        $decoded->authorizationRevision===4,
        'F1-106 exact candidate record decodes without granting identity');
    $bad=$wire;
    $bad['publishable']=true;
    f102Check(f102Invalid(fn()=>Snapshot::fromArray($bad)),
        'F1-106 extra publication field is rejected');
    $bad=$wire;
    unset($bad['validUntil']);
    f102Check(f102Invalid(fn()=>Snapshot::fromArray($bad)),
        'F1-106 missing expiration is rejected');
    $bad=$wire;
    $bad['authorizationRevision']='4';
    f102Check(f102Invalid(fn()=>Snapshot::fromArray($bad)),
        'F1-106 string authorization revision is rejected');
    $bad=$wire;
    $bad['checkedAt']=true;
    f102Check(f102Invalid(fn()=>Snapshot::fromArray($bad)),
        'F1-106 boolean timestamp is rejected');
    $bad=$wire;
    $bad['boundCommand']['idempotencyKey']=false;
    f102Check(f102Invalid(fn()=>Snapshot::fromArray($bad)),
        'F1-106 wrong nested command field type is rejected');
    $bad=$wire;
    $bad['boundCommand']['tenantRef']='tenant-f102-other';
    f102Check(!Snapshot::fromArray($bad)->isCurrentFor($command,$now),
        'F1-106 syntactically valid other tenant cannot inherit project scope');
    $bad=$wire;
    $bad['schemaVersion']='sites.portal-project-authorization.v2';
    f102Check(f102Invalid(fn()=>Snapshot::fromArray($bad)),
        'F1-106 unsupported record schema is rejected');
    $bad=$wire;
    $bad['validUntil']=$now+40;
    f102Check(f102Invalid(fn()=>Snapshot::fromArray($bad)),
        'F1-106 candidate decoder enforces 30-second validity limit');
} finally {
    if ($previousEnv === false) putenv('APP_ENV');
    else putenv('APP_ENV='.$previousEnv);
    if ($previousFlag === false) putenv('SITES_F102_PROJECT_SNAPSHOT_LAB');
    else putenv('SITES_F102_PROJECT_SNAPSHOT_LAB='.$previousFlag);
}

echo "\n$checks candidate snapshot checks passed; no real Portal IAM, HTTP, approval or publication.\n";
