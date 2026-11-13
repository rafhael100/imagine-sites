<?php

declare(strict_types=1);

/**
 * F1-363: cross-process ReplayStore backed by disposable PostgreSQL18.
 * The store participates in the SAME transaction as F1-360 admission/outbox.
 * It is a fixture, not a deployable authorization service.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_F360_PG_LAB') !== '1' ||
    getenv('SITES_F363_NONCE_LAB') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_PORT') !== '5432' ||
    getenv('DB_DATABASE') !== 'sites_f360_lab' ||
    getenv('DB_USERNAME') !== 'postgres' ||
    getenv('DB_PASSWORD') !== 'f360-fictional-admin-disposable-only') {
    fwrite(STDERR,"F1-363: refusing execution outside fictional PG18 CI.\n");
    exit(1);
}
$sitesDir=(string)getenv('SITES_F362_SITES_PATH');
$portalDir=(string)getenv('SITES_F362_PORTAL_PATH');
if (!is_file($sitesDir.'/src/Security/SyntheticSignedAuthorityAdmission.php') ||
    !is_file($portalDir.'/apps/portal/app/Domain/SitesIntegration/SyntheticSitesEnvelopeSigner.php')) {
    throw new RuntimeException('F1-363 requires two pinned, read-only candidate source trees.');
}
spl_autoload_register(static function (string $class) use ($sitesDir,$portalDir):void {
    foreach([
        'Imagine\\Sites\\Security\\'=>$sitesDir.'/src/Security/',
        'Imagine\\Sites\\Integration\\'=>$sitesDir.'/src/Integration/',
        'App\\Domain\\SitesIntegration\\'=>
          $portalDir.'/apps/portal/app/Domain/SitesIntegration/',
    ] as $prefix=>$dir) {
        if(!str_starts_with($class,$prefix))continue;
        $short=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
            throw new RuntimeException('Rejected test class name.');
        }
        require $dir.$short.'.php';
        return;
    }
});

use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;
use Imagine\Sites\Security\SyntheticSignedAuthorityAdmission;

final class F363PgReplayStore implements ReplayStore
{
    public function __construct(
        private readonly PDO $connection,
        private readonly string $expectedTenant,
        private readonly int $now,
    ) {}
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $seconds=$expiresAt-$this->now;
        $q=$this->connection->prepare(
            'SELECT sites_f360.remember_nonce(?,?,?,?)'
        );
        $q->execute([$this->expectedTenant,$issuer,$nonce,$seconds]);
        return in_array($q->fetchColumn(),[true,'t','1',1],true);
    }
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$admin=new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',$opts);
$connection1=new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$opts);
$connection2=new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$opts);
$revokedA=new PDO($dsn,'sites_f360_a','f360-fictional-tenant-a-only',$opts);
if((int)$admin->query('SELECT count(*) FROM sites_f360.admissions')
    ->fetchColumn()!==7) {
    throw new RuntimeException('F1-363: missing sequential F1-360..362 prerequisite.');
}
$sql=__DIR__.'/fixtures/f363-pg-durable-nonce.sql';
if(!is_file($sql) || is_link($sql)) {
    throw new RuntimeException('Missing or unsafe disposable F1-363 SQL fixture.');
}
$admin->exec((string)file_get_contents($sql));
$checks=0;
function f363Check(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-363 FAIL: '.$label);
    ++$checks;
}
function f363Denied(callable $run,string $code):bool {
    try{$run();}catch(PDOException $e){return $e->getCode()===$code;}
    return false;
}
function f363AuthDenied(callable $run):bool {
    try{$run();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$now=time();
$commandKey='F363-FICTIONAL-PORTAL-SIGNED-COMMAND-KEY-00001';
$portalKey='F363-FICTIONAL-PORTAL-AUTHORITY-KEY-00002';
$externalKey='F363-FICTIONAL-EXTERNAL-AUTHORITY-KEY-00003';
function f363Wire(SiteProjectCommand $cmd,string $nonce):array {
    global $now,$commandKey;
    $payload=[
        'schemaVersion'=>$cmd->schemaVersion,
        'operationId'=>$cmd->operationId,
        'tenantRef'=>$cmd->tenantRef,
        'orderRef'=>$cmd->orderRef,
        'contractRef'=>$cmd->contractRef,
        'contractRevision'=>$cmd->contractRevision,
        'productCode'=>$cmd->productCode,
        'idempotencyKey'=>$cmd->idempotencyKey,
    ];
    return SyntheticSitesEnvelopeSigner::sign(
        $payload,$commandKey,$nonce,$now,$now+60
    );
}
function f363Proofs(SiteProjectCommand $cmd):array {
    global $now,$portalKey,$externalKey;
    $snapshot=new SyntheticAuthoritySnapshot(
        $cmd->tenantRef,$cmd->contractRef,$cmd->contractRevision,
        true,$now-1,$now+20
    );
    return [
      SyntheticAuthorityAttestation::sign(
        'imagine-portal-authority',$snapshot,$portalKey),
      SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation',$snapshot,$externalKey),
    ];
}
function f363SignedAdmit(PDO $db,SiteProjectCommand $cmd,string $nonce,
    string $expectedTenant='tenant-f360-b'):SiteProjectCommand {
    global $now,$commandKey,$portalKey,$externalKey;
    [$portal,$external]=f363Proofs($cmd);
    return SyntheticSignedAuthorityAdmission::admit(
        f363Wire($cmd,$nonce),$portal,$external,
        $commandKey,$portalKey,$externalKey,$now,
        new F363PgReplayStore($db,$expectedTenant,$now)
    );
}
function f363Persist(PDO $db,SiteProjectCommand $cmd,string $site):string {
    $stmt=$db->prepare(
        'SELECT sites_f360.admit_project(?,?,?,?,?,?,?)'
    );
    $stmt->execute([$cmd->tenantRef,$cmd->idempotencyKey,$site,
        $cmd->contractRef,$cmd->productCode,$cmd->contractRevision,
        $cmd->semanticFingerprint()]);
    return (string)$stmt->fetchColumn();
}
function f363Count(PDO $db):int {
    return (int)$db->query('SELECT count(*) FROM sites_f360.replay_nonces')
        ->fetchColumn();
}
f363Check(f363Denied(static fn()=> $connection1->query(
    'SELECT * FROM sites_f360.replay_nonces'
),'42501'), 'tenant cannot directly read replay ledger');
f363Check(f363Denied(static fn()=> $connection1->exec(
    "DELETE FROM sites_f360.replay_nonces"
),'42501'), 'tenant cannot delete durable replay records');
f363Check(f363Denied(static fn()=> $connection1->query(
    "SELECT sites_f360.remember_nonce('tenant-f360-a','imagine-portal','nonce-f363-fake',60)"
),'42501'), 'tenant B cannot claim nonce on behalf of tenant A');
f363Check(f363Denied(static fn()=> $revokedA->query(
    "SELECT sites_f360.remember_nonce('tenant-f360-a','imagine-portal','nonce-f363-revoked',60)"
),'42501'), 'revoked SQL login cannot consume an authority nonce');
f363Check(f363Denied(static fn()=> $connection1->query(
    "SELECT sites_f360.remember_nonce('tenant-f360-b','untrusted-issuer','nonce-f363-fake',60)"
),'42501'), 'arbitrary issuer cannot use the Portal replay fence');
f363Check(f363Denied(static fn()=> $connection1->query(
    "SELECT sites_f360.remember_nonce('tenant-f360-b','imagine-portal','nonce-f363-fake',121)"
),'22023'), 'nonces with unbounded lifetime rejected');

$cmd=new SiteProjectCommand('1','op-f363-01','tenant-f360-b',
    'order-f363-01','contract-f363-01',1,'onepage','idem-f363-01');
$connection1->beginTransaction();
try {
    $verified=f363SignedAdmit($connection1,$cmd,'nonce-f363-01');
    f363Check($verified->tenantRef==='tenant-f360-b',
        'pinned Portal command admitted with SQL-backed nonce on first worker');
    f363Check(f363Persist($connection1,$verified,'site-f363-01')==='queued',
        'first command and outbox persisted in same transaction as nonce');
    $connection1->commit();
} finally {
    if($connection1->inTransaction())$connection1->rollBack();
}
f363Check(f363Count($admin)===1 &&
    (int)$admin->query(
      "SELECT count(*) FROM sites_f360.outbox WHERE operation_ref='idem-f363-01'"
    )->fetchColumn()===1,
    'committed nonce, admission and outbox are durably present');

f363Check(f363AuthDenied(static fn()=>f363SignedAdmit(
    $connection2,$cmd,'nonce-f363-01'
)), 'a different PHP worker cannot replay a committed Portal nonce');
f363Check(f363Count($admin)===1,
    'rejected cross-worker replay does not mutate durable ledger');

$connection2->beginTransaction();
try {
    $fresh=f363SignedAdmit($connection2,$cmd,'nonce-f363-02');
    f363Check(f363Persist($connection2,$fresh,'site-f363-01')
        ==='duplicate_ignored',
        'fresh signed retry consumes different nonce but not a new project');
    $connection2->commit();
} finally {
    if($connection2->inTransaction())$connection2->rollBack();
}
f363Check(f363Count($admin)===2 &&
    (int)$admin->query(
      "SELECT count(*) FROM sites_f360.outbox WHERE operation_ref='idem-f363-01'"
    )->fetchColumn()===1,
    'two durable nonces but only one idempotent project/outbox');

$bad=new SiteProjectCommand('1','op-f363-fail','tenant-f360-b',
    'order-f363-fail','contract-f363-01',1,'onepage','failoutbox-f360');
$connection1->beginTransaction();
try {
    $v=f363SignedAdmit($connection1,$bad,'nonce-f363-fail');
    f363Check(f363Denied(static fn()=>f363Persist(
        $connection1,$v,'site-f363-fail'
    ),'23514'), 'outbox failure aborts admitted command inside transaction');
} finally {
    if($connection1->inTransaction())$connection1->rollBack();
}
f363Check(f363Count($admin)===2 &&
    (int)$admin->query(
      "SELECT count(*) FROM sites_f360.admissions WHERE operation_ref='failoutbox-f360'"
    )->fetchColumn()===0,
    'rollback removes consumed nonce and admission after outbox error');

$connection2->beginTransaction();
try {
    $v=f363SignedAdmit($connection2,$bad,'nonce-f363-fail');
    f363Check($v->idempotencyKey==='failoutbox-f360',
        'same signed nonce can be retried after fully rolled back transaction');
} finally {
    if($connection2->inTransaction())$connection2->rollBack();
}
f363Check(f363Count($admin)===2,
    'uncommitted retry nonce does not persist');

$foreign=new SiteProjectCommand('1','op-f363-foreign','tenant-f360-a',
    'order-f363-foreign','contract-f363-01',1,'onepage','idem-f363-foreign');
f363Check(f363Denied(static fn()=> $connection1->query(
    "SELECT sites_f360.remember_nonce('tenant-f360-a',
      'imagine-portal','nonce-f363-foreign',60)"
),'42501'), 'signed tenant mismatch cannot poison another tenants replay namespace');

f363Check((int)$admin->query(
    "SELECT count(*) FROM sites_f360.admissions WHERE operation_ref='idem-f363-01'"
)->fetchColumn()===1,
    'durable operation remains unique despite retries and failed transactions');
echo "$checks F1-363 durable cross-worker nonce and atomic rollback checks passed.\n";
echo "NO-GO: this is a database fixture with public synthetic keys, not a real Portal IAM/replay adapter or backup retention policy.\n";
