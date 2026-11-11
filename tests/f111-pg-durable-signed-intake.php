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
use Imagine\Sites\Integration\PortalSigningKeySource;
use Imagine\Sites\Integration\PortalSigningKeyEpochSource;
use Imagine\Sites\Integration\PortalProjectCommercialEpochSource;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Persistence\LabAuthorizedProjectAdmissionStore;
use Imagine\Sites\Security\LabPostgresReplayStore;
use Imagine\Sites\Security\LabPreflightNoPersistReplayStore;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;
use Imagine\Sites\Security\SyntheticVersionedSignedCommand;
use Imagine\Sites\Security\SyntheticCommercialEpochSignedCommand;

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

/** F1-115: in-memory test-only service key. Not Portal IAM. */
final class F115FictionalSigningKeySource implements PortalSigningKeySource
{
    public bool $fail=false;
    public int $calls=0;
    public ?Closure $onLookup=null;
    public function __construct(public ?string $key) {}

    public function currentKeyFor(string $issuer, string $audience): ?string
    {
        ++$this->calls;
        if ($this->onLookup !== null) ($this->onLookup)();
        if ($this->fail) throw new RuntimeException('F115 synthetic key source unavailable');
        return $issuer==='imagine-portal' && $audience==='imagine-sites'
            ? $this->key : null;
    }
}

/** F1-117: fake key epoch, never a trusted operational provider. */
final class F117FictionalEpochSource implements PortalSigningKeyEpochSource
{
    public ?int $epoch=1;
    public bool $fail=false;
    public int $calls=0;

    public function currentEpochFor(string $issuer,string $audience):?int
    {
        ++$this->calls;
        if ($this->fail) throw new RuntimeException('Lab key epoch unavailable');
        return $issuer==='imagine-portal' && $audience==='imagine-sites'
            ? $this->epoch : null;
    }
}

/** F1-125: fake per-order commercial epoch, never an IAM source. */
final class F125FictionalCommercialEpochSource implements PortalProjectCommercialEpochSource
{
    public ?int $epoch=5;
    public bool $offline=false;
    public int $calls=0;
    public ?Closure $onRead=null;

    public function __construct(
        public string $tenantRef,
        public string $orderRef
    ) {}

    public function currentEpochFor(SiteProjectCommand $command):?int
    {
        ++$this->calls;
        if ($this->onRead!==null) ($this->onRead)();
        if ($this->offline) throw new RuntimeException('F125 synthetic commercial epoch outage');
        return $command->tenantRef===$this->tenantRef &&
            $command->orderRef===$this->orderRef ? $this->epoch : null;
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
$key='DO-NOT-USE-F1-111-SYNTHETIC-ONLY-KEY-123456789';
$signingKeys=new F115FictionalSigningKeySource($key);
$signingEpoch=new F117FictionalEpochSource();
$intakeA=new LabSignedProjectIntake($app,$gateway,$fenceA,$signingKeys,$signingEpoch);
$intakeB=new LabSignedProjectIntake($app,$gateway,$fenceB,$signingKeys,$signingEpoch);
$now=time();
$sign=static fn(SiteProjectCommand $cmd,string $nonce):array =>
    SyntheticSignedCommand::sign($cmd,$key,$nonce,$now-1,$now+60);
// F1-120: opt in explicitly to the historical CLI v1 test suite.
$oldF120Legacy=getenv('SITES_F118_ALLOW_LEGACY_V1_LAB');
putenv('SITES_F118_ALLOW_LEGACY_V1_LAB=1');
$first=$sign($command,'f111-durable-nonce-0001');
$site=LabAuthorizedProjectAdmissionStore::siteRefFor($command);

putenv('SITES_F110_SIGNED_PROJECT_LAB=0');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($first)) &&
    f111AdmissionCount($app,'tenant-f111')===0,
    'disabled F1-110 gate blocks signed command before nonce consumption');
putenv('SITES_F110_SIGNED_PROJECT_LAB=1');

$created=$intakeA->admitSigned($first);
f111Check($created['siteRef']===$site &&
    $created['tenantRef']==='tenant-f111' &&
    $created['idempotentReplay']===false &&
    $created['portalIdentityOperational']===false &&
    $created['officialClientAcceptance']===false &&
    $created['publishable']===false &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'first durable nonce and fictional authorized command create one PG project');

f111Check(f111Denied(fn()=> $intakeB->admitSigned($first)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'second independent nonce database session rejects cross-worker replay');

$second=$sign($command,'f111-durable-nonce-0002');
f111Check($intakeB->admitSigned($second)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'fresh persisted nonce allows idempotent project replay without duplicate SQL');

$third=$sign($command,'f111-durable-nonce-0003');
$tampered=$third;
$tampered['payload']['tenantRef']='tenant-f111-attacker';
f111Check(f111Denied(fn()=> $intakeA->admitSigned($tampered)) &&
    f111AdmissionCount($app,'tenant-f111-attacker')===0,
    'modified tenant invalidates signed envelope without consuming original nonce');
f111Check($intakeB->admitSigned($third)['idempotentReplay']===true,
    'unmodified signature remains usable in another session after MAC rejection');

$gateway->enabled=false;
$revoked=$sign($command,'f111-durable-nonce-0004');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($revoked)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'revoked fictional gateway rejects fresh nonce with unchanged admission count');
$gateway->enabled=true;
f111Check(f111Denied(fn()=> $intakeB->admitSigned($revoked)),
    'revoked operation nonce stays consumed after authority is restored');

$other=new SiteProjectCommand(
    '1','operation-f111-other','tenant-f111-other','order-f111-other',
    'contract-f111-other',1,'landing_page','idempotency-f111-other'
);
$otherSigned=$sign($other,'f111-durable-nonce-0005');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($otherSigned)) &&
    f111AdmissionCount($app,'tenant-f111-other')===0,
    'valid synthetic HMAC cannot cross fictional order authorization scope');

$restored=$sign($command,'f111-durable-nonce-0006');
f111Check($intakeB->admitSigned($restored)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'new nonce after renewed current authorization returns only exact idempotent result');

// F1-112: an adapter that was previously initialized under the safe
// SQL role must recheck the current role for EACH nonce consumption.
$roleFlipSigned=$sign($command,'f112-role-flip-nonce-0001');
$nonceA->exec('RESET ROLE');
f111Check(f111Denied(fn()=>new LabPostgresReplayStore($nonceA)),
    'PG adapter constructor denies schema owner after role reset');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($roleFlipSigned)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-112 existing adapter refuses nonce after its PDO session escalates to owner');
$nonceA->exec('SET ROLE sites_auth_runtime');
f111Check($intakeB->admitSigned($roleFlipSigned)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-112 role-denied nonce was never consumed and works with restricted session');

$flagFlipSigned=$sign($command,'f112-flag-flip-nonce-0002');
putenv('SITES_F1_14_PG_LAB=0');
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($flagFlipSigned)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-112 existing nonce adapter denies when lab flag is withdrawn');
} finally {
    putenv('SITES_F1_14_PG_LAB=1');
}
f111Check($intakeB->admitSigned($flagFlipSigned)['idempotentReplay']===true,
    'F1-112 a disabled adapter did not burn the nonce');

$txSigned=$sign($command,'f112-rollback-nonce-0003');
$nonceA->beginTransaction();
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($txSigned)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-112 nonce adapter denies caller-controlled PostgreSQL transaction');
} finally {
    if ($nonceA->inTransaction()) $nonceA->rollBack();
}
f111Check($intakeB->admitSigned($txSigned)['idempotentReplay']===true &&
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
    f111Check(f111Denied(fn()=> $intakeB->admitSigned($outageSigned)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-113 unavailable nonce INSERT privilege denies authorized signed intake');
} finally {
    $nonceA->exec(
        'GRANT INSERT ON auth_replay.nonce_consumptions TO sites_auth_runtime'
    );
    $nonceA->exec('SET ROLE sites_auth_runtime');
}
f111Check($intakeA->admitSigned($outageSigned)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-113 replay store recovery permits original unconsumed nonce only once');
f111Check(f111Denied(fn()=> $intakeB->admitSigned($outageSigned)) &&
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
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($projectOutageSigned)),
        'F1-114 project ledger read outage denies a newly signed valid command');
} finally {
    $projectAdmin->exec(
        'GRANT SELECT ON public.sites_project_admissions TO sites_f94_app'
    );
}
f111Check(f111AdmissionCount($app,'tenant-f111')===1,
    'F1-114 failed project transaction left the admission ledger unchanged');
f111Check(f111Denied(fn()=> $intakeB->admitSigned($projectOutageSigned)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-114 nonce remains consumed when later project database access fails');
$projectRecoverySigned=$sign($command,'f114-recovery-new-nonce-0002');
f111Check($intakeB->admitSigned($projectRecoverySigned)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-114 recovery requires a new signed nonce and preserves project idempotency');

// F1-115: trust source must not come from the caller. It defaults DENY,
// can revoke an existing key, and supports tested synthetic key rotation.
// All denials occur before the nonce is committed to the separate PG ledger.
$untrustedDefault=new LabSignedProjectIntake($app,$gateway,$fenceA);
$defaultSigned=$sign($command,'f115-default-key-deny-0001');
f111Check(f111Denied(fn()=> $untrustedDefault->admitSigned($defaultSigned)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-115 no injected signing key source cannot accept a signed project');
f111Check($intakeB->admitSigned($defaultSigned)['idempotentReplay']===true,
    'F1-115 default key denial does not consume the nonce');

$revokedKeySigned=$sign($command,'f115-revoked-key-0002');
$signingKeys->key=null;
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($revokedKeySigned)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-115 key removal denies an otherwise authentic signed message');
} finally {
    $signingKeys->key=$key;
}
f111Check($intakeB->admitSigned($revokedKeySigned)['idempotentReplay']===true,
    'F1-115 revoked key denial does not burn the protected nonce');

$failedKeySigned=$sign($command,'f115-source-error-0003');
$signingKeys->fail=true;
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($failedKeySigned)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-115 trusted key source outage fails closed');
} finally {
    $signingKeys->fail=false;
}
f111Check($intakeB->admitSigned($failedKeySigned)['idempotentReplay']===true,
    'F1-115 source outage does not consume the original nonce');

$oldSigned=$sign($command,'f115-rotation-key-0004');
$rotatedKey='DO-NOT-USE-F1-115-ROTATED-SYNTHETIC-KEY-123456789';
$signingKeys->key=$rotatedKey;
++$signingEpoch->epoch;
f111Check(f111Denied(fn()=> $intakeA->admitSigned($oldSigned)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-115 stale signature is rejected after pinned key rotation');
$newSigned=SyntheticSignedCommand::sign(
    $command,$rotatedKey,'f115-rotation-key-0004',$now-1,$now+60
);
f111Check($intakeB->admitSigned($newSigned)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-115 current pinned synthetic key can consume the unburned nonce');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($newSigned)),
    'F1-115 replay protection still holds across sessions after key rotation');
$signingKeys->key=$key;
++$signingEpoch->epoch;

$notPortal=$sign($command,'f115-issuer-gate-0005');
$notPortal['issuer']='unknown-service';
$beforeIssuerCalls=$signingKeys->calls;
f111Check(f111Denied(fn()=> $intakeA->admitSigned($notPortal)) &&
    $signingKeys->calls===$beforeIssuerCalls &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-115 unknown issuer is denied without consulting the key registry');
$portalOriginal=$sign($command,'f115-issuer-gate-0005');
f111Check($intakeB->admitSigned($portalOriginal)['idempotentReplay']===true,
    'F1-115 rejecting unknown issuer did not consume a Portal nonce');

// F1-116: the independently pinned key is revoked between the first
// authority check and the final pre-COMMIT check. Nonce was consumed in
// another database, but no new project state is committed.
$f116Signed=$sign($command,'f116-durable-final-key-0001');
$f116Calls=$signingKeys->calls;
$signingKeys->onLookup=static function() use($signingKeys,$f116Calls):void {
    if ($signingKeys->calls===$f116Calls+3) $signingKeys->key=null;
};
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($f116Signed)) &&
        f111AdmissionCount($app,'tenant-f111')===1 &&
        !$app->inTransaction(),
        'F1-116 key revoked at final authorization refuses replay and rolls back');
    f111Check($signingKeys->calls===$f116Calls+3,
        'F1-116 signing key is resolved for MAC, entry and final admission');
} finally {
    $signingKeys->onLookup=null;
    $signingKeys->key=$key;
}
f111Check(f111Denied(fn()=> $intakeB->admitSigned($f116Signed)),
    'F1-116 nonce remains consumed after key revocation blocks the SQL commit');
$f116Refresh=$sign($command,'f116-durable-new-nonce-0002');
f111Check($intakeB->admitSigned($f116Refresh)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-116 fresh signed nonce and revalidated key recover exact idempotent replay');

// F1-117: default epoch source MUST deny even if a signing key exists
// and a previously verified candidate project is still eligible.
$noEpochIntake=new LabSignedProjectIntake($app,$gateway,$fenceA,$signingKeys);
$noEpochSigned=$sign($command,'f117-no-signing-epoch-0001');
f111Check(f111Denied(fn()=> $noEpochIntake->admitSigned($noEpochSigned)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-117 missing independent key epoch denies before project admission');
f111Check($intakeB->admitSigned($noEpochSigned)['idempotentReplay']===true,
    'F1-117 default epoch denial does not consume the nonce');

$epochUnavailableSigned=$sign($command,'f117-epoch-offline-0002');
$signingEpoch->fail=true;
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($epochUnavailableSigned)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-117 key epoch provider error fails closed before nonce consumption');
} finally {
    $signingEpoch->fail=false;
}
f111Check($intakeB->admitSigned($epochUnavailableSigned)['idempotentReplay']===true,
    'F1-117 unavailable generation did not consume nonce');

$abaSigned=$sign($command,'f117-aba-durable-replay-0003');
$beforeAba=$signingKeys->calls;
$initialEpoch=$signingEpoch->epoch;
$signingKeys->onLookup=static function() use($signingKeys,$signingEpoch,$beforeAba,$key):void {
    if ($signingKeys->calls===$beforeAba+3) {
        $signingKeys->key='DO-NOT-USE-F117-TEMPORARY-ROTATION-KEY-123456789';
        $signingKeys->key=$key;
        ++$signingEpoch->epoch;
    }
};
try {
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($abaSigned)) &&
        f111AdmissionCount($app,'tenant-f111')===1 &&
        !$app->inTransaction(),
        'F1-117 A-B-A signing key change denies before idempotent replay COMMIT');
    f111Check($signingEpoch->epoch===$initialEpoch+1 &&
        $signingKeys->key===$key &&
        $signingKeys->calls===$beforeAba+3,
        'F1-117 same bytes and bumped generation prove anti-ABA epoch check');
} finally {
    $signingKeys->onLookup=null;
    $signingKeys->key=$key;
}
f111Check(f111Denied(fn()=> $intakeB->admitSigned($abaSigned)),
    'F1-117 replay nonce remains consumed even when epoch changed before COMMIT');
$epochRetry=$sign($command,'f117-current-epoch-new-nonce-0004');
f111Check($intakeB->admitSigned($epochRetry)['idempotentReplay']===true &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-117 current epoch with fresh signed nonce recovers exact idempotent replay');

// F1-118: an authenticated keyEpoch in HMAC v2 prevents a signed
// command from an OLD generation being replayed AFTER A->B->A
// restoration, even when the current secret bytes equal the old ones.
$oldVersionFlag=getenv('SITES_F118_REQUIRE_EPOCH_SIGNED');
putenv('SITES_F118_ALLOW_LEGACY_V1_LAB=0');
putenv('SITES_F118_REQUIRE_EPOCH_SIGNED=1');
try {
    $legacySigned=$sign($command,'f118-durable-v1-denied-0001');
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($legacySigned)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-118 explicit v2 opt-in rejects old HMAC envelope before nonce consumption');

    $beforeRotateEpoch=$signingEpoch->epoch;
    $staleV2=SyntheticVersionedSignedCommand::sign(
        $command,$key,'f118-durable-stale-epoch-0002',
        $now-1,$now+60,$beforeRotateEpoch
    );
    $signingKeys->key='DO-NOT-USE-F118-TEMPORARY-ROTATION-KEY-123456789';
    $signingKeys->key=$key;
    ++$signingEpoch->epoch;
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($staleV2)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-118 old signed key epoch denied after A-B-A rotation before intake');
    $forgedCurrent=$staleV2;
    $forgedCurrent['keyEpoch']=$signingEpoch->epoch;
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($forgedCurrent)) &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-118 rewriting old signed epoch to current without MAC re-signing is denied');
    $currentV2=SyntheticVersionedSignedCommand::sign(
        $command,$key,'f118-durable-stale-epoch-0002',
        $now-1,$now+60,$signingEpoch->epoch
    );
    f111Check($intakeB->admitSigned($currentV2)['idempotentReplay']===true &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-118 new signed epoch admits once with same previously unconsumed nonce');
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($currentV2)),
        'F1-118 second PDO session cannot reuse a v2-signed nonce');

    $badV2=SyntheticVersionedSignedCommand::sign(
        $command,$key,'f118-durable-tampered-epoch-0003',
        $now-1,$now+60,$signingEpoch->epoch
    );
    $badV2['keyEpoch']++;
    f111Check(f111Denied(fn()=> $intakeA->admitSigned($badV2)),
        'F1-118 alteration of signed epoch is rejected before nonce INSERT');

    $goodV2=SyntheticVersionedSignedCommand::sign(
        $command,$key,'f118-durable-tampered-epoch-0003',
        $now-1,$now+60,$signingEpoch->epoch
    );
    f111Check($intakeB->admitSigned($goodV2)['idempotentReplay']===true &&
        f111AdmissionCount($app,'tenant-f111')===1,
        'F1-118 unchanged valid signed epoch can still consume previously denied nonce');
} finally {
    if ($oldVersionFlag===false) putenv('SITES_F118_REQUIRE_EPOCH_SIGNED');
    else putenv('SITES_F118_REQUIRE_EPOCH_SIGNED='.$oldVersionFlag);
    putenv('SITES_F118_ALLOW_LEGACY_V1_LAB=1');
}

// F1-120: no configured version and contradictory flags both fail closed.
$defaultNonce=$sign($command,'f120-durable-default-deny-0001');
putenv('SITES_F118_ALLOW_LEGACY_V1_LAB=0');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($defaultNonce)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-120 no explicit version selection denies before nonce consumption');
putenv('SITES_F118_ALLOW_LEGACY_V1_LAB=1');
f111Check($intakeB->admitSigned($defaultNonce)['idempotentReplay']===true,
    'F1-120 rejected default mode did not consume the durable nonce');

$bothNonce=$sign($command,'f120-durable-both-modes-0002');
putenv('SITES_F118_REQUIRE_EPOCH_SIGNED=1');
f111Check(f111Denied(fn()=> $intakeA->admitSigned($bothNonce)) &&
    f111AdmissionCount($app,'tenant-f111')===1,
    'F1-120 both version flags enabled are denied, not downgraded');
putenv('SITES_F118_REQUIRE_EPOCH_SIGNED=0');
f111Check($intakeB->admitSigned($bothNonce)['idempotentReplay']===true,
    'F1-120 invalid dual mode leaves nonce free for explicit version');

if ($oldF120Legacy===false) putenv('SITES_F118_ALLOW_LEGACY_V1_LAB');
else putenv('SITES_F118_ALLOW_LEGACY_V1_LAB='.$oldF120Legacy);
if ($oldVersionFlag===false) putenv('SITES_F118_REQUIRE_EPOCH_SIGNED');
else putenv('SITES_F118_REQUIRE_EPOCH_SIGNED='.$oldVersionFlag);

// F1-125: real disposable two-PostgreSQL integration of authenticated
// commercial epoch v3. No default provider or implicit v2/v1 downgrade.
$oldV3=getenv('SITES_F125_REQUIRE_COMMERCIAL_EPOCH_SIGNED');
$oldV1=getenv('SITES_F118_ALLOW_LEGACY_V1_LAB');
$oldV2=getenv('SITES_F118_REQUIRE_EPOCH_SIGNED');
putenv('SITES_F125_REQUIRE_COMMERCIAL_EPOCH_SIGNED=1');
putenv('SITES_F118_ALLOW_LEGACY_V1_LAB=0');
putenv('SITES_F118_REQUIRE_EPOCH_SIGNED=0');
try {
    $newCommand=new SiteProjectCommand(
        '1','operation-f125','tenant-f125','order-f125',
        'contract-f125',1,'landing_page','idempotency-f125'
    );
    $newAuthority=new F111FictionalOrderGateway($newCommand);
    $commercial=new F125FictionalCommercialEpochSource(
        $newCommand->tenantRef,$newCommand->orderRef
    );
    $v3A=new LabSignedProjectIntake(
        $app,$newAuthority,$fenceA,$signingKeys,$signingEpoch,$commercial
    );
    $v3B=new LabSignedProjectIntake(
        $app,$newAuthority,$fenceB,$signingKeys,$signingEpoch,$commercial
    );
    $signV3=static fn(string $nonce,int $commercialEpoch):array =>
        SyntheticCommercialEpochSignedCommand::sign(
            $newCommand,$key,$nonce,$now-1,$now+60,
            $signingEpoch->epoch,$commercialEpoch
        );

    $defaultV3=new LabSignedProjectIntake(
        $app,$newAuthority,$fenceA,$signingKeys,$signingEpoch
    );
    $defaultV3Envelope=$signV3('f125-default-source-denied-0001',$commercial->epoch);
    f111Check(f111Denied(fn()=> $defaultV3->admitSigned($defaultV3Envelope)) &&
        f111AdmissionCount($app,'tenant-f125')===0,
        'F1-125 default commercial epoch source denies v3 before nonce consumption');

    $staleV3=$signV3('f125-old-commercial-epoch-0002',$commercial->epoch);
    ++$commercial->epoch;
    f111Check(f111Denied(fn()=> $v3A->admitSigned($staleV3)) &&
        f111AdmissionCount($app,'tenant-f125')===0,
        'F1-125 signed stale commercial grant denied before nonce and project write');
    $freshV3=$signV3('f125-old-commercial-epoch-0002',$commercial->epoch);
    f111Check($v3B->admitSigned($freshV3)['idempotentReplay']===false &&
        f111AdmissionCount($app,'tenant-f125')===1,
        'F1-125 refreshed grant epoch and unconsumed nonce create exactly one project');
    f111Check(f111Denied(fn()=> $v3A->admitSigned($freshV3)),
        'F1-125 another PDO session rejects a consumed v3-signed nonce');

    $badCommercialV3=$signV3('f125-forged-commercial-epoch-0003',$commercial->epoch);
    ++$badCommercialV3['commercialEpoch'];
    f111Check(f111Denied(fn()=> $v3A->admitSigned($badCommercialV3)),
        'F1-125 tampered commercial authorization revision fails HMAC');

    $v2Legacy= SyntheticVersionedSignedCommand::sign(
        $newCommand,$key,'f125-downgrade-0004',$now-1,$now+60,$signingEpoch->epoch
    );
    f111Check(f111Denied(fn()=> $v3A->admitSigned($v2Legacy)),
        'F1-125 v3 intake refuses an otherwise valid v2 envelope');

    $revokedBeforeCommit=$signV3('f125-precommit-epoch-0005',$commercial->epoch);
    $before=$commercial->calls;
    $commercial->onRead=static function() use($commercial,$before):void {
        if ($commercial->calls===$before+3) ++$commercial->epoch;
    };
    try {
        f111Check(f111Denied(fn()=> $v3A->admitSigned($revokedBeforeCommit)) &&
            f111AdmissionCount($app,'tenant-f125')===1 &&
            !$app->inTransaction(),
            'F1-125 commercial grant changed at final COMMIT check rolls back replay');
        f111Check($commercial->calls===$before+3,
            'F1-125 current commercial epoch checked before nonce, on entry and at commit');
    } finally {
        $commercial->onRead=null;
    }
    f111Check(f111Denied(fn()=> $v3B->admitSigned($revokedBeforeCommit)),
        'F1-125 failed project transaction cannot reuse already consumed nonce');
    $afterRestore=$signV3('f125-post-revoke-new-nonce-0006',$commercial->epoch);
    f111Check($v3B->admitSigned($afterRestore)['idempotentReplay']===true &&
        f111AdmissionCount($app,'tenant-f125')===1,
        'F1-125 new signed nonce and current grant restore exact idempotent result');

    // F1-126: a commercial source outage BEFORE the durable nonce INSERT
    // may be retried with the SAME signed envelope after recovery.
    $offlineBeforeNonce=$signV3(
        'f126-outage-before-nonce-0001',$commercial->epoch
    );
    $commercial->offline=true;
    try {
        f111Check(f111Denied(fn()=> $v3A->admitSigned($offlineBeforeNonce)) &&
            f111AdmissionCount($app,'tenant-f125')===1,
            'F1-126 commercial source outage at preflight denies without SQL mutation');
    } finally {
        $commercial->offline=false;
    }
    f111Check($v3B->admitSigned($offlineBeforeNonce)['idempotentReplay']===true,
        'F1-126 preflight outage did not burn nonce in other PostgreSQL database');

    // Outage after a successful commercial lookup and nonce INSERT,
    // during the first SQL admission authorization. Original nonce
    // must be considered consumed despite project transaction denial.
    $outageAtEntry=$signV3('f126-outage-at-entry-0002',$commercial->epoch);
    $beforeEntry=$commercial->calls;
    $commercial->onRead=static function() use($commercial,$beforeEntry):void {
        if ($commercial->calls===$beforeEntry+2) $commercial->offline=true;
    };
    try {
        f111Check(f111Denied(fn()=> $v3A->admitSigned($outageAtEntry)) &&
            f111AdmissionCount($app,'tenant-f125')===1 &&
            !$app->inTransaction(),
            'F1-126 outage after nonce but before admission rolls back SQL');
        f111Check($commercial->calls===$beforeEntry+2,
            'F1-126 outage triggered at first transactional commercial recheck');
    } finally {
        $commercial->onRead=null;
        $commercial->offline=false;
    }
    f111Check(f111Denied(fn()=> $v3B->admitSigned($outageAtEntry)),
        'F1-126 cross-session retry denies nonce consumed before authorization outage');
    $entryRetry=$signV3('f126-entry-recovery-nonce-0003',$commercial->epoch);
    f111Check($v3B->admitSigned($entryRetry)['idempotentReplay']===true &&
        f111AdmissionCount($app,'tenant-f125')===1,
        'F1-126 fresh nonce recovers idempotent replay after entry outage');

    // Finally, fail exactly on the THIRD commercial lookup during
    // the creation of a NEW project: first read before durable nonce,
    // second read at admission entry, third read before COMMIT.
    $brandNew=new SiteProjectCommand(
        '1','operation-f126','tenant-f126','order-f126',
        'contract-f126',1,'onepage','idempotency-f126'
    );
    $newCommercial=new F125FictionalCommercialEpochSource(
        $brandNew->tenantRef,$brandNew->orderRef
    );
    $newAuthority=new F111FictionalOrderGateway($brandNew);
    $newIntakeA=new LabSignedProjectIntake(
        $app,$newAuthority,$fenceA,$signingKeys,$signingEpoch,$newCommercial
    );
    $newIntakeB=new LabSignedProjectIntake(
        $app,$newAuthority,$fenceB,$signingKeys,$signingEpoch,$newCommercial
    );
    $newSigned=static fn(string $nonce):array =>
        SyntheticCommercialEpochSignedCommand::sign(
            $brandNew,$key,$nonce,$now-1,$now+60,
            $signingEpoch->epoch,$newCommercial->epoch
        );
    $precommitOutage=$newSigned('f126-precommit-outage-0004');
    $beforeCommit=$newCommercial->calls;
    $newCommercial->onRead=static function() use($newCommercial,$beforeCommit):void {
        if ($newCommercial->calls===$beforeCommit+3) $newCommercial->offline=true;
    };
    try {
        f111Check(f111Denied(fn()=> $newIntakeA->admitSigned($precommitOutage)) &&
            f111AdmissionCount($app,'tenant-f126')===0 &&
            !$app->inTransaction(),
            'F1-126 outage at pre-COMMIT rolls back BOTH new project and admission');
        f111Check($newCommercial->calls===$beforeCommit+3,
            'F1-126 three per-command commercial checks reached pre-COMMIT outage');
    } finally {
        $newCommercial->onRead=null;
        $newCommercial->offline=false;
    }
    f111Check(f111Denied(fn()=> $newIntakeB->admitSigned($precommitOutage)),
        'F1-126 nonce remains burned after SQL rollback on pre-COMMIT outage');
    $postCommitRecovery=$newSigned('f126-new-project-recovery-0005');
    f111Check($newIntakeB->admitSigned($postCommitRecovery)['idempotentReplay']===false &&
        f111AdmissionCount($app,'tenant-f126')===1,
        'F1-126 new signed nonce creates exactly one project after provider recovery');
    f111Check(f111Denied(fn()=> $newIntakeA->admitSigned($postCommitRecovery)),
        'F1-126 replay of recovered create is denied across DB connections');

    // F1-127: never substitute the preflight non-persistent store, or
    // any arbitrary in-memory success stub, for the restricted durable
    // PostgreSQL replay store in actual v3 candidate admission.
    $badReplay=new LabPreflightNoPersistReplayStore();
    $wrongStoreIntake=new LabSignedProjectIntake(
        $app,$newAuthority,$badReplay,$signingKeys,$signingEpoch,$newCommercial
    );
    $insecureStub=new class implements ReplayStore {
        public int $calls=0;
        public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
        {
            ++$this->calls;
            return true;
        }
    };
    $stubIntake=new LabSignedProjectIntake(
        $app,$newAuthority,$insecureStub,$signingKeys,$signingEpoch,$newCommercial
    );
    $protectedEnvelope=$newSigned('f127-miswired-store-nonce-0001');
    $sourceBefore=$newCommercial->calls;
    f111Check(f111Denied(fn()=> $wrongStoreIntake->admitSigned($protectedEnvelope)) &&
        f111Denied(fn()=> $stubIntake->admitSigned($protectedEnvelope)) &&
        $insecureStub->calls===0 &&
        $newCommercial->calls===$sourceBefore &&
        f111AdmissionCount($app,'tenant-f126')===1,
        'F1-127 rejects no-persist and always-allow replay adapters before any authorization call');
    f111Check($newIntakeB->admitSigned($protectedEnvelope)['idempotentReplay']===true &&
        f111AdmissionCount($app,'tenant-f126')===1,
        'F1-127 rejected fake replay stores never consume the real durable nonce');
    f111Check(f111Denied(fn()=> $newIntakeA->admitSigned($protectedEnvelope)),
        'F1-127 proper PostgreSQL nonce store still denies cross-session replay');

    // F1-128: authenticated preflight must be a pure verification API,
    // without any ReplayStore. A second check of an already consumed
    // nonce succeeds AS A SIGNATURE ONLY; admission remains denied.
    $checked= SyntheticCommercialEpochSignedCommand::verifySignature(
        $protectedEnvelope,$key,$signingEpoch->epoch,$newCommercial->epoch,$now
    );
    $checkedAgain= SyntheticCommercialEpochSignedCommand::verifySignature(
        $protectedEnvelope,$key,$signingEpoch->epoch,$newCommercial->epoch,$now
    );
    f111Check($checked->semanticFingerprint()===$brandNew->semanticFingerprint() &&
        $checkedAgain->semanticFingerprint()===$brandNew->semanticFingerprint() &&
        f111Denied(fn()=> $newIntakeB->admitSigned($protectedEnvelope)),
        'F1-128 HMAC v3 preflight stays repeatable without granting consumed nonce');

    $v1Proof=SyntheticSignedCommand::sign(
        $brandNew,$key,'f128-v1-preflight-no-nonce-0001',$now-1,$now+60
    );
    $v2Proof=SyntheticVersionedSignedCommand::sign(
        $brandNew,$key,'f128-v2-preflight-no-nonce-0002',
        $now-1,$now+60,$signingEpoch->epoch
    );
    f111Check(SyntheticSignedCommand::verifySignature(
        $v1Proof,$key,$now
    )->semanticFingerprint()===$brandNew->semanticFingerprint() &&
        SyntheticVersionedSignedCommand::verifySignature(
            $v2Proof,$key,$signingEpoch->epoch,$now
        )->semanticFingerprint()===$brandNew->semanticFingerprint(),
        'F1-128 legacy v1/v2 cryptographic-only checks preserve canonical semantics');

    // The retired preflight shim is no longer allowed to accept ANY
    // nonce, even with the v3 testing flag enabled.
    $retiredShim=new LabPreflightNoPersistReplayStore();
    f111Check(!$retiredShim->rememberOnce(
        'imagine-portal','f128-legacy-shim-deny-0003',$now+60
    ), 'F1-128 retired nonpersistent replay adapter unconditionally denies');

    // Before durable consumption, repeated signature-only checks of
    // the SAME valid new nonce must not reserve it. The strict v3
    // intake can then consume it once and reject the other PDO session.
    $freshPreflight=$newSigned('f128-pure-preflight-then-consume-0004');
    $countBeforePreflight=$newCommercial->calls;
    f111Check(SyntheticCommercialEpochSignedCommand::verifySignature(
        $freshPreflight,$key,$signingEpoch->epoch,$newCommercial->epoch,$now
    )->semanticFingerprint()===$brandNew->semanticFingerprint() &&
        SyntheticCommercialEpochSignedCommand::verifySignature(
            $freshPreflight,$key,$signingEpoch->epoch,$newCommercial->epoch,$now
        )->semanticFingerprint()===$brandNew->semanticFingerprint() &&
        $newCommercial->calls===$countBeforePreflight,
        'F1-128 repeated preflight invokes no commercial provider or replay store');
    f111Check($newIntakeA->admitSigned($freshPreflight)['idempotentReplay']===true &&
        f111Denied(fn()=> $newIntakeB->admitSigned($freshPreflight)),
        'F1-128 PG18 remains the only durable replay fence after pure preflight');

    $invalidPreflight=$newSigned('f128-corrupted-mac-0005');
    $invalidPreflight['mac']=str_repeat('a',64);
    f111Check(f111Denied(fn()=>
        SyntheticCommercialEpochSignedCommand::verifySignature(
            $invalidPreflight,$key,$signingEpoch->epoch,$newCommercial->epoch,$now
        )),
        'F1-128 signature-only path rejects modified commercial HMAC');
} finally {
    if ($oldV3===false)putenv('SITES_F125_REQUIRE_COMMERCIAL_EPOCH_SIGNED');
    else putenv('SITES_F125_REQUIRE_COMMERCIAL_EPOCH_SIGNED='.$oldV3);
    if ($oldV1===false)putenv('SITES_F118_ALLOW_LEGACY_V1_LAB');
    else putenv('SITES_F118_ALLOW_LEGACY_V1_LAB='.$oldV1);
    if ($oldV2===false)putenv('SITES_F118_REQUIRE_EPOCH_SIGNED');
    else putenv('SITES_F118_REQUIRE_EPOCH_SIGNED='.$oldV2);
}

echo "\n$checks F1-111–F1-128 two-database signed intake assertions passed.\n";
echo "Disposable PG18, fake HMAC, no live Portal IAM, HTTP, client data, go-live or publication.\n";
