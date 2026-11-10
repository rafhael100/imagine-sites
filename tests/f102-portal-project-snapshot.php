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
$gateway=new Gateway($source);
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
} finally {
    if ($previousEnv === false) putenv('APP_ENV');
    else putenv('APP_ENV='.$previousEnv);
    if ($previousFlag === false) putenv('SITES_F102_PROJECT_SNAPSHOT_LAB');
    else putenv('SITES_F102_PROJECT_SNAPSHOT_LAB='.$previousFlag);
}

echo "\n$checks candidate snapshot checks passed; no real Portal IAM, HTTP, approval or publication.\n";
