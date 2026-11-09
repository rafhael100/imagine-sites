<?php

declare(strict_types=1);

/** F1-14: real disposable PostgreSQL only. No production DB, real keys or HTTP. */
if (PHP_SAPI !== 'cli' || getenv('SITES_F1_14_PG_LAB') !== '1') {
    fwrite(STDERR, "F1-14 lab not enabled\n");
    exit(1);
}
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^(Integration|Security)\\\\[A-Za-z]+$/D', $relative)) {
        throw new RuntimeException('Invalid laboratory PHP class path.');
    }
    require __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
});

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\LabPostgresReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;

$checks = 0;
function checkLab(bool $assertion, string $description): void {
    global $checks;
    if (!$assertion) throw new RuntimeException('FAIL ' . $description);
    ++$checks;
    echo 'PASS ' . $description . "\n";
}
function rejected(callable $callback): bool {
    try { $callback(); }
    catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}
$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=sites_auth_replay_lab';
$db = new PDO($dsn, 'postgres', 'nonce-lab-fictional-only', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db->exec('SET ROLE sites_auth_runtime'); // Test-only switch from throwaway owner.
$store = new LabPostgresReplayStore($db);
$fixture = json_decode(
    file_get_contents(__DIR__ . '/../fixtures/portal-sites/create-project.synthetic.json'),
    true, 512, JSON_THROW_ON_ERROR
);
$command = SiteProjectCommand::fromArray($fixture);
$key = 'DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';
$now = time();

$valid = SyntheticSignedCommand::sign(
    $command, $key, 'nonce-f14-adapter-first-0001', $now, $now + 60
);
$actual = SyntheticSignedCommand::verify($valid, $key, $now, $store);
checkLab($actual->contractRef === 'contract-demo-001', 'valid synthetic signed command consumed with PostgreSQL');
checkLab(rejected(fn() => SyntheticSignedCommand::verify($valid, $key, $now, $store)),
    'replay denied across calls to the same durable store');

$secondConnection = new PDO($dsn, 'postgres', 'nonce-lab-fictional-only', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$secondConnection->exec('SET ROLE sites_auth_runtime');
$secondStore = new LabPostgresReplayStore($secondConnection);
checkLab(rejected(fn() => SyntheticSignedCommand::verify($valid, $key, $now, $secondStore)),
    'separate PDO connection cannot re-consume nonce');

$untouched = SyntheticSignedCommand::sign(
    $command, $key, 'nonce-f14-signature-first-0002', $now, $now + 60
);
$tamper = $untouched;
$tamper['payload']['tenantRef'] = 'attacker-different-tenant';
checkLab(rejected(fn() => SyntheticSignedCommand::verify($tamper, $key, $now, $store)),
    'malicious signed-payload alteration rejected before nonce consumption');
checkLab(
    SyntheticSignedCommand::verify($untouched, $key, $now, $store)->tenantRef === 'tenant-demo-001',
    'untampered message still allowed after rejected forgery');

checkLab(!$store->rememberOnce('untrusted-service', 'nonce-f14-other-issuer-3', $now + 60),
    'wrong issuer denied by adapter');
checkLab(!$store->rememberOnce('imagine-portal', 'nonce-f14-expired-0004', $now - 1),
    'PostgreSQL rejects expired nonce');
checkLab(!$store->rememberOnce('imagine-portal', 'nonce-f14-long-ttl-0005', $now + 500),
    'PostgreSQL rejects excessive TTL');
checkLab(!$store->rememberOnce('imagine-portal', '../not-allowed', $now + 60),
    'unsafe nonce string rejected');

$db->exec('RESET ROLE');
checkLab(rejected(fn() => new LabPostgresReplayStore($db)),
    'constructor rejects privileged schema-owner role');
$db->exec('SET ROLE sites_auth_runtime');
putenv('SITES_F1_14_PG_LAB=0');
checkLab(rejected(fn() => new LabPostgresReplayStore($db)),
    'lab adapter cannot initialize without the explicit switch');
putenv('SITES_F1_14_PG_LAB=1');

echo "\n$checks F1-14 PostgreSQL PDO signed contract checks passed; no real HTTP or identity.\n";
