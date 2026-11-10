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
        '/\A(?:Integration|Domain|Persistence)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $rest
    )) throw new RuntimeException('Invalid F1-94 test class name');
    require __DIR__.'/../src/'.str_replace('\\', '/', $rest).'.php';
});

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Persistence\LabAuthorizedProjectAdmissionStore;

final class F94FictionalAuthority implements PortalProjectAuthorityGateway
{
    public bool $allowed = false;
    public int $checks = 0;

    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        ++$this->checks;
        return new PolicyDecision(
            $this->allowed,
            $this->allowed ? [] : ['fictional_gateway_is_denied']
        );
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
$fixture = new F94FictionalAuthority();
$store = new LabAuthorizedProjectAdmissionStore($app, $fixture);

putenv('SITES_F94_PROJECT_ADMISSION_LAB=0');
f94Assert(f94Denied(fn()=> $store->admit($original, 'site-f94-001')) &&
    $fixture->checks===0,
    'disabled lab flag denies before authority or database access');
putenv('SITES_F94_PROJECT_ADMISSION_LAB=1');

f94Assert(f94Denied(fn()=> (new LabAuthorizedProjectAdmissionStore($app))
    ->admit($original, 'site-f94-001')),
    'default trusted-authority gateway is always DENY');
f94Assert(f94Denied(fn()=> $store->admit($original, 'site-f94-001')),
    'explicitly denied fictional Portal authority cannot create a project');
f94Assert(f94Count($app,'tenant-f94-a','sites_projects')===0 &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===0,
    'denied commands do not leave database rows');

$fixture->allowed=true;
$created=$store->admit($original,'site-f94-001');
f94Assert($created['schemaVersion']==='sites.synthetic.project-admission-result.v1' &&
    $created['tenantRef']==='tenant-f94-a' &&
    $created['siteRef']==='site-f94-001' &&
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

$retry=$store->admit($original,'site-f94-001');
f94Assert($retry['idempotentReplay']===true &&
    $retry['semanticFingerprint']===$created['semanticFingerprint'] &&
    f94Count($app,'tenant-f94-a','sites_projects')===1 &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'same exact idempotency key returns existing result with zero duplicate writes');

$alteredOrder=$command(
    'tenant-f94-a','operation-f94-001','order-f94-different',
    'contract-f94-001',1,'onepage','idem-f94-001'
);
f94Assert(f94Denied(fn()=> $store->admit($alteredOrder, 'site-f94-001')),
    'same key with changed order or semantic fingerprint is rejected');
$alteredOp=$command(
    'tenant-f94-a','operation-f94-changed','order-f94-001',
    'contract-f94-001',1,'onepage','idem-f94-001'
);
f94Assert(f94Denied(fn()=> $store->admit($alteredOp, 'site-f94-001')),
    'same idempotency key cannot switch the operation');
f94Assert(f94Denied(fn()=> $store->admit($original, 'site-f94-changed')),
    'same idempotency key cannot switch the site');

$newIdem=$command(
    'tenant-f94-a','operation-f94-002','order-f94-002',
    'contract-f94-002',1,'onepage','idem-f94-002'
);
f94Assert(f94Denied(fn()=> $store->admit($newIdem, 'site-f94-001')) &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'second operation cannot bind an already admitted site and leaves no partial write');

$sameOpNewSite=$command(
    'tenant-f94-a','operation-f94-001','order-f94-003',
    'contract-f94-003',1,'onepage','idem-f94-new'
);
f94Assert(f94Denied(fn()=> $store->admit($sameOpNewSite, 'site-f94-new')) &&
    f94Count($app,'tenant-f94-a','sites_projects')===1,
    'reusing operation ID with different idempotency fails atomically');

$other=$command(
    'tenant-f94-b','operation-f94-001','order-f94-001',
    'contract-f94-001',1,'landing_page','idem-f94-001'
);
$second=$store->admit($other,'site-f94-001');
f94Assert($second['tenantRef']==='tenant-f94-b' &&
    $second['productCode']==='landing_page' &&
    f94Count($app,'tenant-f94-b','sites_project_admissions')===1 &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'tenant B has independent compound keys, product, storage and idempotency');

f94Assert((int)$app->query('SELECT count(*) FROM sites_projects')->fetchColumn()===0 &&
    (int)$app->query('SELECT count(*) FROM sites_project_admissions')->fetchColumn()===0,
    'tenant context SET LOCAL does not persist across requests');

$fixture->allowed=false;
f94Assert(f94Denied(fn()=> $store->admit($original,'site-f94-001')) &&
    f94Count($app,'tenant-f94-a','sites_project_admissions')===1,
    'revoked fictional gateway denies even an otherwise valid idempotent retry');
$fixture->allowed=true;
f94Assert(f94Denied(fn()=> (
    new LabAuthorizedProjectAdmissionStore($admin, $fixture)
)->admit($original,'site-f94-001')),
    'administrator role is rejected even if the fictional gateway allows');
f94Assert(f94Denied(fn()=> $store->admit($original,'../site-f94-invalid')),
    'untrusted site reference is not passed into SQL or filesystem');

$checksBefore=$fixture->checks;
$app->beginTransaction();
try {
    f94Assert(f94Denied(fn()=> $store->admit($original,'site-f94-001')) &&
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

echo "\n$checks F1-94 persisted, authorization-gated project admission assertions passed.\n";
echo "Synthetic gateway only; no real Portal IAM, SQL access for clients or go-live.\n";
