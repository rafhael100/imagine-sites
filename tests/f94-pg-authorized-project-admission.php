<?php

declare(strict_types=1);

/**
 * F1-94: end-to-end PostgreSQL 18 proof of the real Laravel-created
 * project/admission tables using a *restricted* DB login and fictional
 * independent authority gateway. No Portal, public HTTP or customer data.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_F94_PROJECT_ADMISSION_LAB') !== '1' ||
    getenv('DB_DATABASE') !== 'sites_f94_lab' ||
    getenv('DB_HOST') !== '127.0.0.1') {
    fwrite(STDERR, "F1-94 requires an explicitly authorized disposable CI database.\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $rest = substr($class, strlen($prefix));
    if (!preg_match(
        '/\A(?:Integration|Domain|Persistence|Security)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $rest
    )) throw new RuntimeException('Invalid F1-94 test class name');
    require __DIR__.'/../src/'.str_replace('\\', '/', $rest).'.php';
});

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationSource;
use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationEpochSource;
use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationSnapshot;
use Imagine\Sites\Integration\SnapshotBoundPortalProjectAuthorityGateway;
use Imagine\Sites\Integration\LabSignedProjectIntake;
use Imagine\Sites\Integration\PortalSigningKeySource;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;
use Imagine\Sites\Persistence\LabAuthorizedProjectAdmissionStore;

final class F94FictionalAuthority implements PortalProjectAuthorityGateway
{
    public bool $allowed = false;
    public int $checks = 0;
    public ?Closure $onVerify = null;

    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        ++$this->checks;
        if ($this->onVerify !== null) ($this->onVerify)();
        return new PolicyDecision(
            $this->allowed,
            $this->allowed ? [] : ['fictional_gateway_is_denied']
        );
    }
}

/**
 * F1-103: a separate synthetic Portal source (no network, tokens or IAM).
 * Only the exact tenant/order pair can retrieve this in-memory snapshot.
 */
final class F103SyntheticSnapshotSource implements CurrentPortalProjectAuthorizationSource
{
    public ?CurrentPortalProjectAuthorizationSnapshot $record=null;

    public function findCurrentFor(
        string $tenantRef,string $orderRef
    ): ?CurrentPortalProjectAuthorizationSnapshot {
        $bound=$this->record?->boundCommand;
        return $bound !== null &&
            $bound->tenantRef === $tenantRef &&
            $bound->orderRef === $orderRef
            ? $this->record : null;
    }
}

/** F1-105: separate, fake current epoch view for the isolated PG18 test. */
final class F105SyntheticCurrentEpochSource implements CurrentPortalProjectAuthorizationEpochSource
{
    public ?int $epoch=2;
    public bool $unavailable=false;
    public int $calls=0;
    public ?Closure $onRead=null;

    public function currentEpochFor(string $tenantRef,string $orderRef):?int
    {
        ++$this->calls;
        if ($this->onRead !== null) ($this->onRead)();
        if ($this->unavailable ||
            $tenantRef !== 'tenant-f103-c' || $orderRef !== 'order-f103-c') return null;
        return $this->epoch;
    }
}

/**
 * F1-110: process-local synthetic test fence, NOT durable or multi-worker.
 * The production nonce fence must be atomic, durable and authenticated.
 */
final class F110MemoryReplayFence implements ReplayStore
{
    /** @var array<string,bool> */
    private array $seen=[];
    public int $calls=0;

    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        ++$this->calls;
        if ($expiresAt<=time())return false;
        $key=strlen($issuer).':'.$issuer.':'.$nonce;
        if (isset($this->seen[$key]))return false;
        $this->seen[$key]=true;
        return true;
    }
}

/** F1-115: fictional in-memory trust source, never a production key vault. */
final class F115FictionalPortalSigningKeys implements PortalSigningKeySource
{
    public bool $fail=false;
    public int $calls=0;
    public ?Closure $onLookup=null;

    public function __construct(public ?string $key) {}

    public function currentKeyFor(string $issuer,string $audience):?string
    {
        ++$this->calls;
        if ($this->onLookup !== null) ($this->onLookup)();
        if ($this->fail) throw new RuntimeException('Fictitious key service offline');
        return $issuer==='imagine-portal' && $audience==='imagine-sites'
            ? $this->key : null;
    }
}

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=sites_f94_lab;connect_timeout=5';
$app = new PDO($dsn, 'sites_f94_app', (string)getenv('SITES_F94_APP_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$admin = new PDO($dsn, 'postgres', (string)getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$checks = 0;
function f94Assert(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL F1-94 '.$label);
    ++$checks;
    echo 'PASS F1-94 '.$label."\n";
}
function f94Denied(callable $action): bool {
    try { $action(); } catch (DomainException) { return true; }
    return false;
}
function f94Count(PDO $db, string $tenant, string $table): int {
    if (!in_array($table, ['sites_projects', 'sites_project_admissions'], true)) {
        throw new DomainException('Invalid test table');
    }
    $db->beginTransaction();
    try {
        $stmt=$db->prepare("SELECT set_config('imagine_sites.tenant_ref', :tenant, true)");
        $stmt->execute(['tenant'=>$tenant]);
        return (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}
$command = static fn (
    string $tenant, string $op, string $order, string $contract,
    int $revision, string $product, string $idem
): SiteProjectCommand => new SiteProjectCommand(
    '1', $op, $tenant, $order, $contract, $revision, $product, $idem
);
$original = $command(
    'tenant-f94-a', 'operation-f94-001', 'order-f94-001',
    'contract-f94-001', 1, 'onepage', 'idem-f94-001'
);
$siteA=LabAuthorizedProjectAdmissionStore::siteRefFor($original);
f94Assert(preg_match('/\Asite-[a-f0-9]{32}\z/D', $siteA) === 1,
    'site reference is minted from trusted contract and order inputs');
$fixture = new F94FictionalAuthority();
$store = new LabAuthorizedProjectAdmissionStore($app, $fixture);

putenv('SITES_F94_PROJECT_ADMISSION_LAB=0');
f94Assert(f94Denied(fn()=> $store->admit($original, $siteA)) &&
    $fixture->checks===0,
    'disabled lab flag denies before authority or database access');
putenv('SITES_F94_PROJECT_ADMISSION_LAB=1');

f94Assert(f94Denied(fn()=> (new LabAuthorizedProjectAdmissionStore($app))
    ->admit($original, $siteA)),
    'default trusted-authority gateway is always DENY');
f94Assert(f94Denied(fn()=> $store->admit($original, $siteA)),
    'explicitly denied fictional Portal authority cannot create a project');
f94Assert(f94Count($app,'tenant-f94-a','sites_projects')===0 &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===0,
    'denied commands do not leave database rows');

$fixture->allowed=true;
// F1-104: revoke the fictional grant between the first and last
// authorization checks on a new project. Both rows must roll back.
$f104NewCalls=$fixture->checks;
$fixture->onVerify=static function() use ($fixture,$f104NewCalls):void {
    if ($fixture->checks===$f104NewCalls+2) $fixture->allowed=false;
};
try {
    f94Assert(f94Denied(fn()=> $store->admit($original,$siteA)) &&
        f94Count($app,'tenant-f94-a','sites_projects')===0 &&
        f94Count($app,'tenant-f94-a','sites_project_admissions')===0 &&
        !$app->inTransaction(),
        'F1-104 final revocation rolls back both project and admission');
    f94Assert($fixture->checks===$f104NewCalls+2,
        'F1-104 new admission checks independent authority twice');
} finally {
    $fixture->allowed=true;
    $fixture->onVerify=null;
}
$created=$store->admit($original,$siteA);
f94Assert($created['schemaVersion']==='sites.synthetic.project-admission-result.v1' &&
    $created['tenantRef']==='tenant-f94-a' &&
    $created['siteRef']===$siteA &&
    $created['operationId']==='operation-f94-001' &&
    $created['productCode']==='onepage' &&
    $created['semanticFingerprint']===$original->semanticFingerprint() &&
    $created['idempotentReplay']===false,
    'authorized synthetic command atomically created exact project and admission');
f94Assert($created['labOnly']===true &&
    $created['portalIdentityOperational']===false &&
    $created['officialClientAcceptance']===false &&
    $created['publishable']===false,
    'persistent operation cannot become official acceptance or publication');
f94Assert(f94Count($app,'tenant-f94-a','sites_projects')===1 &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'one project and one admission record persisted under tenant A');

$retry=$store->admit($original,$siteA);
f94Assert($retry['idempotentReplay']===true &&
    $retry['semanticFingerprint']===$created['semanticFingerprint'] &&
    f94Count($app,'tenant-f94-a','sites_projects')===1 &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'same exact idempotency key returns existing result with zero duplicate writes');

// Even an exact existing operation must not reuse a now-revoked grant.
$f104RetryCalls=$fixture->checks;
$fixture->onVerify=static function() use ($fixture,$f104RetryCalls):void {
    if ($fixture->checks===$f104RetryCalls+2) $fixture->allowed=false;
};
try {
    f94Assert(f94Denied(fn()=> $store->admit($original,$siteA)) &&
        f94Count($app,'tenant-f94-a','sites_project_admissions')===1 &&
        !$app->inTransaction(),
        'F1-104 revocation before replay commit denies without mutating ledger');
    f94Assert($fixture->checks===$f104RetryCalls+2,
        'F1-104 idempotent replay checks current grant twice');
} finally {
    $fixture->onVerify=null;
    $fixture->allowed=true;
}
f94Assert($store->admit($original,$siteA)['idempotentReplay']===true,
    'F1-104 restored grant permits exact idempotent project replay');

$alteredOrder=$command(
    'tenant-f94-a','operation-f94-001','order-f94-different',
    'contract-f94-001',1,'onepage','idem-f94-001'
);
f94Assert(f94Denied(fn()=> $store->admit($alteredOrder, LabAuthorizedProjectAdmissionStore::siteRefFor($alteredOrder))),
    'same key with changed order or semantic fingerprint is rejected');
$alteredOp=$command(
    'tenant-f94-a','operation-f94-changed','order-f94-001',
    'contract-f94-001',1,'onepage','idem-f94-001'
);
f94Assert(f94Denied(fn()=> $store->admit($alteredOp, $siteA)),
    'same idempotency key cannot switch the operation');
f94Assert(f94Denied(fn()=> $store->admit($original, 'site-f94-changed')),
    'caller-supplied site not bound to authorized order and contract is denied');

$newIdem=$command(
    'tenant-f94-a','operation-f94-002','order-f94-001',
    'contract-f94-001',1,'onepage','idem-f94-002'
);
f94Assert(f94Denied(fn()=> $store->admit($newIdem, $siteA)) &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'second operation cannot bind an already admitted site and leaves no partial write');

$sameOpNewSite=$command(
    'tenant-f94-a','operation-f94-001','order-f94-003',
    'contract-f94-003',1,'onepage','idem-f94-new'
);
f94Assert(f94Denied(fn()=> $store->admit($sameOpNewSite, LabAuthorizedProjectAdmissionStore::siteRefFor($sameOpNewSite))) &&
    f94Count($app,'tenant-f94-a','sites_projects')===1,
    'reusing operation ID with different idempotency fails atomically');

$other=$command(
    'tenant-f94-b','operation-f94-001','order-f94-001',
    'contract-f94-001',1,'landing_page','idem-f94-001'
);
$siteB=LabAuthorizedProjectAdmissionStore::siteRefFor($other);
f94Assert($siteB!==$siteA,
    'tenant-specific Site IDs cannot coincide for the same order and contract');
$second=$store->admit($other,$siteB);
f94Assert($second['tenantRef']==='tenant-f94-b' &&
    $second['productCode']==='landing_page' &&
    f94Count($app,'tenant-f94-b','sites_project_admissions')===1 &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'tenant B has independent compound keys, product, storage and idempotency');

f94Assert((int)$app->query('SELECT count(*) FROM sites_projects')->fetchColumn()===0 &&
    (int)$app->query('SELECT count(*) FROM sites_project_admissions')->fetchColumn()===0,
    'tenant context SET LOCAL does not persist across requests');

// A human/Portal decision is not editable by an application or migration
// operator via UPDATE/DELETE of previously recorded admission facts.
function f94SqlDenied(PDO $db, string $sql): bool {
    try {
        $db->beginTransaction();
        $db->exec($sql);
        $db->rollBack();
        return false;
    } catch (PDOException) {
        if ($db->inTransaction()) $db->rollBack();
        return true;
    }
}
f94Assert(f94SqlDenied($admin,
    "UPDATE sites_project_admissions
     SET contract_revision=2 WHERE tenant_ref='tenant-f94-a'"),
    'admission trigger prevents SQL UPDATE even by migration owner');
f94Assert(f94SqlDenied($admin,
    "DELETE FROM sites_project_admissions
     WHERE tenant_ref='tenant-f94-a'"),
    'admission trigger prevents SQL DELETE even by migration owner');
f94Assert(f94Count($app,'tenant-f94-a','sites_project_admissions')===1 &&
    f94Count($app,'tenant-f94-b','sites_project_admissions')===1,
    'unauthorized mutation attempts preserve both tenant admission records');

$foreignKeyDenied=false;
try {
    $app->beginTransaction();
    $s=$app->prepare("SELECT set_config('imagine_sites.tenant_ref', ?, true)");
    $s->execute(['tenant-f94-a']);
    $stmt=$app->prepare(
        'INSERT INTO sites_project_admissions
           (tenant_ref,site_ref,operation_id,idempotency_key,order_ref,
            contract_ref,contract_revision,product_code,semantic_fingerprint)
         VALUES (:tenant,:site,:op,:idem,:ord,:contract,:revision,:product,:fingerprint)'
    );
    $stmt->execute([
        'tenant'=>'tenant-f94-a','site'=>'site-f94-only-other-tenant',
        'op'=>'op-f94-no-parent','idem'=>'idem-f94-no-parent',
        'ord'=>'order-f94-no-parent','contract'=>'contract-f94-no-parent',
        'revision'=>1,'product'=>'onepage',
        'fingerprint'=>str_repeat('e',64),
    ]);
} catch (PDOException) {
    $foreignKeyDenied=true;
} finally {
    if ($app->inTransaction()) $app->rollBack();
}
f94Assert($foreignKeyDenied &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'foreign key requires the project to exist in the SAME tenant before admission');

$fixture->allowed=false;
f94Assert(f94Denied(fn()=> $store->admit($original,$siteA)) &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'revoked fictional gateway denies even an otherwise valid idempotent retry');
$fixture->allowed=true;
f94Assert(f94Denied(fn()=> (
    new LabAuthorizedProjectAdmissionStore($admin, $fixture)
)->admit($original,$siteA)),
    'administrator role is rejected even if the fictional gateway allows');
f94Assert(f94Denied(fn()=> $store->admit($original,'../site-f94-invalid')),
    'untrusted site reference is not passed into SQL or filesystem');

$checksBefore=$fixture->checks;
$app->beginTransaction();
try {
    f94Assert(f94Denied(fn()=> $store->admit($original,$siteA)) &&
        $fixture->checks===$checksBefore+1,
        'caller-owned transaction is refused, avoiding context-leak ownership confusion');
} finally {
    $app->rollBack();
}

// Explicit threat-model evidence: a custom user-settable PostgreSQL GUC is
// NOT independent IAM. Do NOT expose the sites_f94_app SQL credential to
// browsers, AI agents, tenants or third parties.
$app->beginTransaction();
try {
    $s=$app->prepare("SELECT set_config('imagine_sites.tenant_ref', ?, true)");
    $s->execute(['tenant-f94-b']);
    f94Assert(
        (int)$app->query('SELECT count(*) FROM sites_project_admissions')->fetchColumn()===1,
        'SECURITY LIMIT: direct app SQL access can set the GUC and impersonate another tenant'
    );
} finally {
    $app->rollBack();
}
f94Assert((int)$app->query('SELECT count(*) FROM sites_project_admissions')->fetchColumn()===0,
    'forged transaction-local tenant does not survive rollback');

// F1-103: bind the F1-102 snapshot adapter to the REAL restricted PG18
// admission store. No external Portal connection or real user approval.
$f103Command=$command(
    'tenant-f103-c','operation-f103-c','order-f103-c',
    'contract-f103-c',2,'landing_page','idem-f103-c'
);
$f103Site=LabAuthorizedProjectAdmissionStore::siteRefFor($f103Command);
$f103Source=new F103SyntheticSnapshotSource();
$f103EpochSource=new F105SyntheticCurrentEpochSource();
$f103Gateway=new SnapshotBoundPortalProjectAuthorityGateway($f103Source,$f103EpochSource);
$f103Store=new LabAuthorizedProjectAdmissionStore($app,$f103Gateway);
putenv('SITES_F102_PROJECT_SNAPSHOT_LAB=0');
f94Assert(f94Denied(fn()=> $f103Store->admit($f103Command,$f103Site)) &&
    f94Count($app,'tenant-f103-c','sites_projects')===0,
    'F1-103 disabled snapshot gateway refuses real PG project admission');
putenv('SITES_F102_PROJECT_SNAPSHOT_LAB=1');
f94Assert(f94Denied(fn()=> $f103Store->admit($f103Command,$f103Site)) &&
    f94Count($app,'tenant-f103-c','sites_project_admissions')===0,
    'F1-103 missing snapshot denies before any PG admission row');

$nowF103=time();
$f103Source->record=new CurrentPortalProjectAuthorizationSnapshot(
    $f103Command,2,'eligible',$nowF103-1,$nowF103+20
);
$f103Created=$f103Store->admit($f103Command,$f103Site);
f94Assert($f103Created['idempotentReplay']===false &&
    $f103Created['labOnly']===true &&
    $f103Created['portalIdentityOperational']===false &&
    $f103Created['officialClientAcceptance']===false &&
    $f103Created['publishable']===false &&
    f94Count($app,'tenant-f103-c','sites_projects')===1 &&
    f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
    'F1-103 exactly bound short-lived fictional snapshot creates one PG admission');

// A revision change in the epoch source DURING a replay must be observed
// by the F1-104 final authorization check, even if the first check allowed.
$f105FirstEpochCall=$f103EpochSource->calls;
$f103EpochSource->onRead=static function() use ($f103EpochSource,$f105FirstEpochCall):void {
    if ($f103EpochSource->calls===$f105FirstEpochCall+2) {
        $f103EpochSource->epoch=3;
    }
};
try {
    f94Assert(f94Denied(fn()=> $f103Store->admit($f103Command,$f103Site)) &&
        f94Count($app,'tenant-f103-c','sites_project_admissions')===1 &&
        !$app->inTransaction(),
        'F1-105 changed epoch at final authority check denies replay with rollback');
    f94Assert($f103EpochSource->calls===$f105FirstEpochCall+2,
        'F1-105 transaction asks the epoch source separately at entry and commit');
} finally {
    $f103EpochSource->onRead=null;
    $f103EpochSource->epoch=2;
}

$f103EpochSource->epoch=3;
// The old eligible snapshot has not expired yet but the independent epoch
// has advanced: it must not permit project replay after revocation.
f94Assert(f94Denied(fn()=> $f103Store->admit($f103Command,$f103Site)) &&
    f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
    'F1-105 epoch fence rejects old eligible grant before its TTL expires');
$f103Source->record=new CurrentPortalProjectAuthorizationSnapshot(
    $f103Command,3,'revoked',$nowF103-1,$nowF103+20
);
f94Assert(f94Denied(fn()=> $f103Store->admit($f103Command,$f103Site)) &&
    f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
    'F1-103 revoked latest snapshot denies even an existing idempotent replay');

$f103EpochSource->epoch=4;
$f103Source->record=new CurrentPortalProjectAuthorizationSnapshot(
    $f103Command,4,'eligible',$nowF103-20,$nowF103-1
);
f94Assert(f94Denied(fn()=> $f103Store->admit($f103Command,$f103Site)) &&
    f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
    'F1-103 expired grant cannot be replayed into PostgreSQL');

$f103EpochSource->epoch=5;
$f103Source->record=new CurrentPortalProjectAuthorizationSnapshot(
    $f103Command,5,'eligible',$nowF103-1,$nowF103+20
);
$f103Wrong=$command(
    'tenant-f103-c','operation-f103-other','order-f103-c',
    'contract-f103-c',2,'landing_page','idem-f103-c'
);
f94Assert(f94Denied(fn()=> $f103Store->admit($f103Wrong,$f103Site)) &&
    f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
    'F1-103 matching tenant/order cannot authorize changed operation');
f94Assert($f103Store->admit($f103Command,$f103Site)['idempotentReplay']===true &&
    f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
    'F1-103 current exact snapshot permits safe no-write replay');
$f103EpochSource->unavailable=true;
f94Assert(f94Denied(fn()=> $f103Store->admit($f103Command,$f103Site)) &&
    f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
    'F1-105 epoch outage denies PG replay without modifying admissions');
$f103EpochSource->unavailable=false;
putenv('SITES_F102_PROJECT_SNAPSHOT_LAB');

// F1-110: executable synthetic signed command -> replay fence ->
// independent gateway -> exact minted site -> PostgreSQL admission.
$f110Command=$command(
    'tenant-f110','operation-f110','order-f110',
    'contract-f110',1,'onepage','idempotency-f110'
);
$f110Site=LabAuthorizedProjectAdmissionStore::siteRefFor($f110Command);
$f110Key='DO-NOT-USE-F1-110-SYNTHETIC-ONLY-KEY-123456789';
$f110Keys=new F115FictionalPortalSigningKeys($f110Key);
$f110Now=time();
$f110Sign=static fn(string $nonce):array =>
    SyntheticSignedCommand::sign(
        $f110Command,$f110Key,$nonce,$f110Now-1,$f110Now+60
    );
$f110Authority=new F94FictionalAuthority();
$f110Fence=new F110MemoryReplayFence();
$f110Intake=new LabSignedProjectIntake($app,$f110Authority,$f110Fence,$f110Keys);
$f110Original=$f110Sign('f110-unique-nonce-0001');

putenv('SITES_F110_SIGNED_PROJECT_LAB=0');
f94Assert(f94Denied(fn()=> $f110Intake->admitSigned($f110Original)) &&
    $f110Fence->calls===0 && $f110Authority->checks===0 &&
    f94Count($app,'tenant-f110','sites_projects')===0,
    'F1-110 disabled lab refuses signed intake before consuming nonce/authority/DB');
putenv('SITES_F110_SIGNED_PROJECT_LAB=1');

$f110Authority->allowed=true;
f94Assert(f94Denied(fn()=> (new LabSignedProjectIntake(
    $app,$f110Authority
))->admitSigned($f110Original)) &&
    f94Count($app,'tenant-f110','sites_projects')===0,
    'F1-110 default denying replay store prevents signed admission');
$f110Authority->allowed=false;
f94Assert(f94Denied(fn()=> $f110Intake->admitSigned($f110Original)) &&
    $f110Fence->calls===1 &&
    f94Count($app,'tenant-f110','sites_project_admissions')===0,
    'F1-110 valid HMAC and nonce still fail without independent authority');
$f110Authority->allowed=true;
f94Assert(f94Denied(fn()=> $f110Intake->admitSigned($f110Original)) &&
    f94Count($app,'tenant-f110','sites_projects')===0,
    'F1-110 previously consumed denied nonce cannot be reused after new grant');

$f110Second=$f110Sign('f110-unique-nonce-0002');
$f110Created=$f110Intake->admitSigned($f110Second);
f94Assert($f110Created['tenantRef']==='tenant-f110' &&
    $f110Created['siteRef']===$f110Site &&
    $f110Created['idempotentReplay']===false &&
    $f110Created['portalIdentityOperational']===false &&
    $f110Created['officialClientAcceptance']===false &&
    $f110Created['publishable']===false &&
    f94Count($app,'tenant-f110','sites_projects')===1 &&
    f94Count($app,'tenant-f110','sites_project_admissions')===1,
    'F1-110 fresh signed fictional command creates exactly one nonpublishable project');

f94Assert(f94Denied(fn()=> $f110Intake->admitSigned($f110Second)) &&
    f94Count($app,'tenant-f110','sites_project_admissions')===1,
    'F1-110 exact signed envelope replay is rejected before the SQL ledger');

$f110Third=$f110Sign('f110-unique-nonce-0003');
f94Assert($f110Intake->admitSigned($f110Third)['idempotentReplay']===true &&
    f94Count($app,'tenant-f110','sites_project_admissions')===1,
    'F1-110 newly signed nonce can safely replay identical idempotent project');

$f110Tampered=$f110Sign('f110-unique-nonce-0004');
$f110Tampered['payload']['tenantRef']='tenant-f110-other';
f94Assert(f94Denied(fn()=> $f110Intake->admitSigned($f110Tampered)) &&
    f94Count($app,'tenant-f110-other','sites_project_admissions')===0,
    'F1-110 changing signed tenant invalidates MAC without cross-tenant effects');

$f110Authority->allowed=false;
$f110Revoked=$f110Sign('f110-unique-nonce-0005');
f94Assert(f94Denied(fn()=> $f110Intake->admitSigned($f110Revoked)) &&
    f94Count($app,'tenant-f110','sites_project_admissions')===1,
    'F1-110 revoked authority denies a new signed nonce without mutation');
$f110Authority->allowed=true;
f94Assert(f94Denied(fn()=> $f110Intake->admitSigned($f110Revoked)),
    'F1-110 restoring a grant cannot reactivate a consumed revoked nonce');

$f110Restored=$f110Sign('f110-unique-nonce-0006');
f94Assert($f110Intake->admitSigned($f110Restored)['idempotentReplay']===true &&
    f94Count($app,'tenant-f110','sites_project_admissions')===1,
    'F1-110 refreshed signature and fictional grant allow same safe idempotent replay');

// F1-110: exercise the FULL lab chain with the candidate F1-102/105
// current Portal project snapshot and independent epoch gate as well.
putenv('SITES_F102_PROJECT_SNAPSHOT_LAB=1');
$f110SnapshotIntake=new LabSignedProjectIntake($app,$f103Gateway,$f110Fence,$f110Keys);
$f110SnapshotSigned=SyntheticSignedCommand::sign(
    $f103Command,$f110Key,'f110-snapshot-nonce-0007',$f110Now-1,$f110Now+60
);
try {
    f94Assert(
        $f110SnapshotIntake->admitSigned($f110SnapshotSigned)['idempotentReplay']===true &&
        f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
        'F1-110 signed Portal candidate, current snapshot, epoch and PG admission interoperate');
    $f103EpochSource->epoch=6;
    $f110StaleSigned=SyntheticSignedCommand::sign(
        $f103Command,$f110Key,'f110-snapshot-nonce-0008',$f110Now-1,$f110Now+60
    );
    f94Assert(f94Denied(fn()=>
        $f110SnapshotIntake->admitSigned($f110StaleSigned)) &&
        f94Count($app,'tenant-f103-c','sites_project_admissions')===1,
        'F1-110 a newer revocation epoch blocks an otherwise valid signed command');
} finally {
    $f103EpochSource->epoch=5;
    putenv('SITES_F102_PROJECT_SNAPSHOT_LAB');
}
// F1-116: the signing key may be revoked AFTER the signed command
// passes HMAC verification and AFTER entry authorization, but BEFORE
// a new project+admission would COMMIT. Roll back BOTH rows.
$f116New=$command(
    'tenant-f116','operation-f116','order-f116',
    'contract-f116',1,'onepage','idempotency-f116'
);
$f116Signed=SyntheticSignedCommand::sign(
    $f116New,$f110Key,'f116-revoke-before-commit-0001',time()-1,time()+60
);
$f116Before=$f110Keys->calls;
$f110Keys->onLookup=static function() use ($f110Keys,$f116Before):void {
    if ($f110Keys->calls===$f116Before+3) $f110Keys->key=null;
};
try {
    f94Assert(f94Denied(fn()=> $f110Intake->admitSigned($f116Signed)) &&
        f94Count($app,'tenant-f116','sites_projects')===0 &&
        f94Count($app,'tenant-f116','sites_project_admissions')===0 &&
        !$app->inTransaction(),
        'F1-116 final key revocation rolls back project and admission records');
    f94Assert($f110Keys->calls===$f116Before+3,
        'F1-116 key checked for HMAC and both admission decisions');
} finally {
    $f110Keys->key=$f110Key;
    $f110Keys->onLookup=null;
}
$f116Retry=SyntheticSignedCommand::sign(
    $f116New,$f110Key,'f116-fresh-key-and-nonce-0002',time()-1,time()+60
);
f94Assert($f110Intake->admitSigned($f116Retry)['idempotentReplay']===false &&
    f94Count($app,'tenant-f116','sites_projects')===1 &&
    f94Count($app,'tenant-f116','sites_project_admissions')===1,
    'F1-116 fresh trusted key and nonce allow one new nonpublishable project');

putenv('SITES_F110_SIGNED_PROJECT_LAB');

echo "\n$checks F1-94 persisted, authorization-gated project admission assertions passed.\n";
echo "Synthetic gateway only; no real Portal IAM, SQL access for clients or go-live.\n";
