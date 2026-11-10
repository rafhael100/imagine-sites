<?php

declare(strict_types=1);

/**
 * F1-111: real disposable PG18 replay fence + separate PG18 project ledger.
 * The two databases and fake keys/authority are lab-only. No Portal IAM.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_F94_PROJECT_ADMISSION_LAB') !== '1' ||
    getenv('SITES_F1_14_PG_LAB') !== '1' ||
    getenv('SITES_F110_SIGNED_PROJECT_LAB') !== '1' ||
    getenv('DB_DATABASE') !== 'sites_f94_lab' ||
    getenv('DB_HOST') !== '127.0.0.1') {
    fwrite(STDERR, "F1-111 requires two explicitly enabled disposable PostgreSQL labs.\n");
    exit(1);
}

spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if (!preg_match(
        '/\A(?:Integration|Domain|Security|Persistence)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $relative
    ))throw new RuntimeException('Unsafe F1-111 class name');
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\LabSignedProjectIntake;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Persistence\LabAuthorizedProjectAdmissionStore;
use Imagine\Sites\Security\LabPostgresReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;

final class F111FictionalOrderGateway implements PortalProjectAuthorityGateway
{
    public bool $enabled=true;
    public function __construct(private readonly SiteProjectCommand $bound) {}

    public function verify(SiteProjectCommand $command):PolicyDecision
    {
        $allowed=$this->enabled &&
            $command->tenantRef===$this->bound->tenantRef &&
            $command->operationId===$this->bound->operationId &&
            $command->idempotencyKey===$this->bound->idempotencyKey &&
            hash_equals(
                $this->bound->semanticFingerprint(),
                $command->semanticFingerprint()
            );
        return new PolicyDecision($allowed,$allowed?[]:['f111_fictional_scope_denied']);
    }
}

$checks=0;
function f111Check(bool $ok,string $why):void
{
    global $checks;
    if (!$ok)throw new RuntimeException('FAIL F1-111 '.$why);
    ++$checks;
    echo "PASS F1-111 ".$why."\n";
}
function f111Denied(callable $callback):bool
{
    try{$callback();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
function f111AdmissionCount(PDO $db,string $tenant):int
{
    $db->beginTransaction();
    try {
        $q=$db->prepare("SELECT set_config('imagine_sites.tenant_ref',:tenant,true)");
        $q->execute(['tenant'=>$tenant]);
        return (int)$db->query(
            'SELECT COUNT(*) FROM sites_project_admissions'
        )->fetchColumn();
    } finally {
        if ($db->inTransaction())$db->rollBack();
    }
}

$opts=[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
];
$app=new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f94_lab;connect_timeout=5',
    'sites_f94_app',(string)getenv('SITES_F94_APP_PASSWORD'),$opts
);
$nonceDsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_auth_replay_lab;connect_timeout=5';
$nonceA=new PDO($nonceDsn,'postgres',(string)getenv('DB_PASSWORD'),$opts);
$nonceB=new PDO($nonceDsn,'postgres',(string)getenv('DB_PASSWORD'),$opts);

// The original F1-14 adapter refuses privileged/owner PDO connections.
// SET ROLE is used only in this disposable, fictional CI database.
f111Check(f111Denied(fn()=>new LabPostgresReplayStore($nonceA)),
    'privileged nonce database owner is denied before SET ROLE');
$nonceA->exec('SET ROLE sites_auth_runtime');
$nonceB->exec('SET ROLE sites_auth_runtime');
$fenceA=new LabPostgresReplayStore($nonceA);
$fenceB=new LabPostgresReplayStore($nonceB);

$command=new SiteProjectCommand(
    '1','operation-f111','tenant-f111','order-f111',
    'contract-f111',1,'onepage','idempotency-f111'
);
$gateway=new F111FictionalOrderGateway($command);
$intakeA=new LabSignedProjectIntake($app,$gateway,$fenceA);
$intakeB=new LabSignedProjectIntake($app,$gateway,$fenceB);
$key='DO-NOT-USE-F1-111-SYNTHETIC-ONLY-KEY-123456789';
$now=time();
$sign=static fn(SiteProjectCommand $cmd,string $nonce):array =>
    SyntheticSignedCommand::sign($cmd,$key,$nonce,$now-1,$now+60);
$first=$sign($command,'f111-durable-nonce-0001');
$site=LabAuthorizedProjectAdmissionStore::siteRefFor($command);

putenv('SITES_F110_SIGNED_PROJECT_LAB=0');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($first,$key)) &&
    f111AdmissionCount($app,'tenant-f111')===0,
    'disabled F1-110 gate blocks signed command before nonce consumption');
putenv('SITES_F110_SIGNED_PROJECT_LAB=1');

$created=$intakeA->admitSigned($first,$key);
f111Check($created['siteRef']===$site &&
    $created['tenantRef']==='tenant-f111' &&
    $created['idempotentReplay']===false &&
    $created['portalIdentityOperational']===false &&
    $created['officialClientAcceptance']===false &&
    $created['publishable']===false &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'first durable nonce and fictional authorized command create one PG project');

f111Check(f111Denied(fn()=> $intakeB->admitSigned($first,$key)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'second independent nonce database session rejects cross-worker replay');

$second=$sign($command,'f111-durable-nonce-0002');
f111Check($intakeB->admitSigned($second,$key)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'fresh persisted nonce allows idempotent project replay without duplicate SQL');

$third=$sign($command,'f111-durable-nonce-0003');
$tampered=$third;
$tampered['payload']['tenantRef']='tenant-f111-attacker';
f111Check(f111Denied(fn()=> $intakeA->admitSigned($tampered,$key)) &&
    f111AdmissionCount($app,'tenant-f111-attacker')===0,
    'modified tenant invalidates signed envelope without consuming original nonce');
f111Check($intakeB->admitSigned($third,$key)['idempotentReplay']===true,
    'unmodified signature remains usable in another session after MAC rejection');

$gateway->enabled=false;
$revoked=$sign($command,'f111-durable-nonce-0004');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($revoked,$key)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'revoked fictional gateway rejects fresh nonce with unchanged admission count');
$gateway->enabled=true;
f111Check(f111Denied(fn()=> $intakeB->admitSigned($revoked,$key)),
    'revoked operation nonce stays consumed after authority is restored');

$other=new SiteProjectCommand(
    '1','operation-f111-other','tenant-f111-other','order-f111-other',
    'contract-f111-other',1,'landing_page','idempotency-f111-other'
);
$otherSigned=$sign($other,'f111-durable-nonce-0005');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($otherSigned,$key)) &&
    f111AdmissionCount($app,'tenant-f111-other')===0,
    'valid synthetic HMAC cannot cross fictional order authorization scope');

$restored=$sign($command,'f111-durable-nonce-0006');
f111Check($intakeB->admitSigned($restored,$key)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'new nonce after renewed current authorization returns only exact idempotent result');

// F1-112: an adapter that was previously initialized under the safe
// SQL role must recheck the current role for EACH nonce consumption.
$roleFlipSigned=$sign($command,'f112-role-flip-nonce-0001');
$nonceA->exec('RESET ROLE');
f111Check(f111Denied(fn()=>new LabPostgresReplayStore($nonceA)),
    'PG adapter constructor denies schema owner after role reset');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($roleFlipSigned,$key)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-112 existing adapter refuses nonce after its PDO session escalates to owner');
$nonceA->exec('SET ROLE sites_auth_runtime');
f111Check($intakeB->admitSigned($roleFlipSigned,$key)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-112 role-denied nonce was never consumed and works with restricted session');

$flagFlipSigned=$sign($command,'f112-flag-flip-nonce-0002');
putenv('SITES_F1_14_PG_LAB=0');
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($flagFlipSigned,$key)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-112 existing nonce adapter denies when lab flag is withdrawn');
} finally {
    putenv('SITES_F1_14_PG_LAB=1');
}
f111Check($intakeB->admitSigned($flagFlipSigned,$key)['idempotentReplay']===true,
    'F1-112 a disabled adapter did not burn the nonce');

$txSigned=$sign($command,'f112-rollback-nonce-0003');
$nonceA->beginTransaction();
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($txSigned,$key)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-112 nonce adapter denies caller-controlled PostgreSQL transaction');
} finally {
    if ($nonceA->inTransaction()) $nonceA->rollBack();
}
f111Check($intakeB->admitSigned($txSigned,$key)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-112 a rejected rollback-capable transaction never consumed the nonce');

// F1-113: simulate temporary loss of INSERT permission on the disposable
// nonce ledger. The gateway still allows the exact site command, but
// intake must deny BEFORE any project query/write when nonce storage fails.
// This modifies only the throwaway CI database and restores privileges.
$outageSigned=$sign($command,'f113-nonce-insert-outage-0001');
$nonceA->exec('RESET ROLE');
$nonceA->exec(
    'REVOKE INSERT ON auth_replay.nonce_consumptions FROM sites_auth_runtime'
);
try {
    f111Check(f111Denied(fn()=> $intakeB->admitSigned($outageSigned,$key)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-113 unavailable nonce INSERT privilege denies authorized signed intake');
} finally {
    $nonceA->exec(
        'GRANT INSERT ON auth_replay.nonce_consumptions TO sites_auth_runtime'
    );
    $nonceA->exec('SET ROLE sites_auth_runtime');
}
f111Check($intakeA->admitSigned($outageSigned,$key)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-113 replay store recovery permits original unconsumed nonce only once');
f111Check(f111Denied(fn()=> $intakeB->admitSigned($outageSigned,$key)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-113 restored durable replay fence rejects the now consumed nonce');

// F1-114: failure happens AFTER the nonce database has committed, but
// BEFORE the project transaction can read its append-only admission ledger.
// The original nonce must remain consumed: it is not a cross-DB transaction.
$projectAdmin=new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f94_lab;connect_timeout=5',
    'postgres',(string)getenv('DB_PASSWORD'),$opts
);
$projectOutageSigned=$sign($command,'f114-project-read-outage-0001');
$projectAdmin->exec(
    'REVOKE SELECT ON public.sites_project_admissions FROM sites_f94_app'
);
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($projectOutageSigned,$key)),
        'F1-114 project ledger read outage denies a newly signed valid command');
} finally {
    $projectAdmin->exec(
        'GRANT SELECT ON public.sites_project_admissions TO sites_f94_app'
    );
}
f111Check(f111AdmissionCount($app,'tenant-f111')===1,
    'F1-114 failed project transaction left the admission ledger unchanged');
f111Check(f111Denied(fn()=> $intakeB->admitSigned($projectOutageSigned,$key)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-114 nonce remains consumed when later project database access fails');
$projectRecoverySigned=$sign($command,'f114-recovery-new-nonce-0002');
f111Check($intakeB->admitSigned($projectRecoverySigned,$key)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-114 recovery requires a new signed nonce and preserves project idempotency');

echo "\n$checks F1-111–F1-114 two-database signed intake assertions passed.\n";
echo "Disposable PG18, fake HMAC, no live Portal IAM, HTTP, client data, go-live or publication.\n";
