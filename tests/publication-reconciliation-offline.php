<?php

declare(strict_types=1);

// Exact selectors, input commitment and default denials only. No successful
// SQL identity, private principal, journal snapshot or replay is fabricated.
// The PG18/TLS fixture proves expiration, CAS, response projection and writes.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});

use Imagine\Sites\Publication\PrivatePublicationReconciliation;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline publication reconciliation failure: '.$label);
    ++$checks;
};
$deny = static function (callable $operation, string $label) use ($ok): void {
    $error = null;
    ob_start();
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error !== null && $error::class === RuntimeException::class &&
        $error->getMessage() === 'PUBLICATION_RECONCILIATION_DENIED' && $error->getPrevious() === null &&
        $error->getCode() === 0 && $output === '', $label.' has only the bounded denial');
};
$encode = static fn (array $value): string => json_encode($value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$manifest = [
    'schema' => 'SITES_PUBLICATION_RECONCILIATION_V1',
    'target' => [
        'host' => 'localhost', 'port' => 55449, 'database' => 'postgres', 'databaseOid' => 1,
        'login' => 'pj_a_reconciler', 'serverAddress' => '127.0.0.1', 'caSha256' => str_repeat('a', 64),
    ],
    'operationRef' => '11111111-1111-4111-8111-111111111111',
    'expectedRevision' => 2,
];
$json = $encode($manifest);
$ok(PHP_INT_SIZE === 8, 'the bigint candidate requires a 64-bit PHP integer runtime');
$ok(PrivatePublicationReconciliation::validateManifest($manifest) === $manifest &&
    PrivatePublicationReconciliation::parseManifest($json) === $manifest,
    'the exact private operation selector is accepted as format only');
$reordered = array_reverse($manifest, true);
$reordered['target'] = array_reverse($manifest['target'], true);
$ok(PrivatePublicationReconciliation::parseManifest($encode($reordered)) === $manifest,
    'every key is canonical without changing its value');
$escaped = str_replace(['"schema":', '"host":', '"expectedRevision":'],
    ['"\\u0073chema":', '"\\u0068ost":', '"\\u0065xpectedRevision":'], $json);
$ok(PrivatePublicationReconciliation::parseManifest($escaped) === $manifest,
    'unambiguous escaped JSON keys retain their normal meaning');
$ok(PrivatePublicationReconciliation::parseManifest(str_pad($json, 16384, " \t\r\n")) === $manifest,
    'JSON whitespace counts within the exact transport bound');
foreach ([1, 3, 4, PHP_INT_MAX - 1] as $revision) {
    $candidate = $manifest; $candidate['expectedRevision'] = $revision;
    $ok(PrivatePublicationReconciliation::parseManifest($encode($candidate)) === $candidate,
        'a positive bounded CAS is format only, not proof of eligible state '.$revision);
}
foreach ([
    ['host' => '127.0.0.1', 'port' => 1, 'databaseOid' => 1],
    ['host' => '::1', 'serverAddress' => '::1', 'port' => 65535, 'databaseOid' => 4294967295],
    ['host' => 'db-1.example.invalid', 'database' => '_'.str_repeat('d', 62), 'login' => '_'.str_repeat('r', 62)],
    ['host' => 'DB.EXAMPLE.INVALID'],
] as $i => $replacement) {
    $candidate = $manifest;
    $candidate['target'] = array_replace($candidate['target'], $replacement);
    $ok(PrivatePublicationReconciliation::parseManifest($encode($candidate)) === $candidate,
        'allowed target bounds preserve exact coordinates '.$i);
}

foreach ([
    '', 'null', 'false', '1', '"selector"', '[]', '[{}]', '{}', '{',
    $json.' trailing', str_pad($json, 16385, ' '), $json."\0",
    str_replace('localhost', "invalid\xC3\x28", $json),
    str_replace('localhost', '\\uD800', $json),
    str_repeat('[', 16).str_repeat(']', 16),
    // A valid final occurrence must not rescue an ambiguous object.
    str_replace('"schema":', '"schema":"WRONG","schema":', $json),
    str_replace('"schema":', '"schema":"WRONG","\\u0073chema":', $json),
    str_replace('"target":', '"target":{},"target":', $json),
    str_replace('"host":', '"host":"bad;options=1","host":', $json),
    str_replace('"host":', '"\\u0068ost":"bad;options=1","host":', $json),
    str_replace('"databaseOid":1', '"databaseOid":2,"databaseOid":1', $json),
    str_replace('"operationRef":', '"operationRef":"bad","operationRef":', $json),
    str_replace('"operationRef":', '"operationRef":"bad","\\u006fperationRef":', $json),
    str_replace('"expectedRevision":', '"expectedRevision":-1,"expectedRevision":', $json),
    str_replace('"expectedRevision":', '"expectedRevision":-1,"\\u0065xpectedRevision":', $json),
    str_replace('"expectedRevision":2', '"expectedRevision":9223372036854775808', $json),
    str_replace('"expectedRevision":2', '"expectedRevision":2e0', $json),
] as $i => $raw) {
    $deny(static fn () => PrivatePublicationReconciliation::parseManifest($raw),
        'ambiguous or malformed transport '.$i);
}

// These are forbidden inputs, even when they look like existing journal data.
foreach ([
    'tenantRef' => 'must-not-select-a-tenant', 'siteRef' => $manifest['operationRef'],
    'releaseRef' => 'not-a-release-selector', 'orderRef' => 'not-order-authority',
    'contractRef' => 'not-contract-authority', 'productCode' => 'onepage',
    'actorRole' => 'reconciler', 'authorizationEpoch' => 1, 'action' => 'reconcile',
    'artifactDigest' => str_repeat('a', 64), 'receiptDigest' => str_repeat('b', 64),
    'providerRequestRef' => 'must-not-invent-an-ack', 'idempotencyKey' => 'must-not-enable-another-attempt',
    'portalAcceptanceRef' => 'not-verified-consent', 'leaseToken' => $manifest['operationRef'],
    'leaseSeconds' => 1, 'leaseExpiresAt' => '2026-11-18T00:00:00.000000Z',
    'observedAt' => '2026-11-19T00:00:00.000000Z', 'technicalStatus' => 'inconclusive',
    'retry' => true, 'reset' => true, 'created' => false, 'replayed' => true, 'expiredNow' => true,
    'canPublish' => true, 'publicationVerified' => true, 'canAcceptRealOrders' => true,
] as $key => $value) {
    $candidate = $manifest; $candidate[$key] = $value;
    $deny(static fn () => PrivatePublicationReconciliation::validateManifest($candidate), 'forbidden selector '.$key);
    $deny(static fn () => PrivatePublicationReconciliation::parseManifest($encode($candidate)), 'forbidden JSON field '.$key);
}
$mutations = [
    static function (&$v): void { unset($v['schema']); },
    static function (&$v): void { unset($v['target']); },
    static function (&$v): void { unset($v['operationRef']); },
    static function (&$v): void { unset($v['expectedRevision']); },
    static fn (&$v) => $v['schema'] = 'SITES_PUBLICATION_RECONCILIATION_V2',
    static fn (&$v) => $v['schema'] = 'SITES_PUBLICATION_INSPECTION_V1',
    static fn (&$v) => $v['schema'] = 1,
    static fn (&$v) => $v['operationRef'] = strtoupper('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
    static fn (&$v) => $v['operationRef'] = '11111111111141118111111111111111',
    static fn (&$v) => $v['operationRef'] .= "\n",
    static fn (&$v) => $v['operationRef'] .= "\0",
    static fn (&$v) => $v['operationRef'] = ['11111111-1111-4111-8111-111111111111'],
    static fn (&$v) => $v['operationRef'] = null,
    static fn (&$v) => $v['expectedRevision'] = 0,
    static fn (&$v) => $v['expectedRevision'] = -1,
    static fn (&$v) => $v['expectedRevision'] = PHP_INT_MAX,
    static fn (&$v) => $v['expectedRevision'] = '2',
    static fn (&$v) => $v['expectedRevision'] = (string) (PHP_INT_MAX - 1),
    static fn (&$v) => $v['expectedRevision'] = 2.0,
    static fn (&$v) => $v['expectedRevision'] = true,
    static fn (&$v) => $v['expectedRevision'] = null,
    static fn (&$v) => $v['target'] = null,
    static fn (&$v) => $v['target'] = [],
    static fn (&$v) => $v['target'] = 'localhost',
    static function (&$v): void { unset($v['target']['databaseOid']); },
    static fn (&$v) => $v['target']['password'] = 'private-fixture-value',
    static fn (&$v) => $v['target']['sslmode'] = 'disable',
    static fn (&$v) => $v['target']['host'] = '',
    static fn (&$v) => $v['target']['host'] = str_repeat('a', 254),
    static fn (&$v) => $v['target']['host'] = 'db..example.invalid',
    static fn (&$v) => $v['target']['host'] = '-db.example.invalid',
    static fn (&$v) => $v['target']['host'] = 'localhost;sslmode=disable',
    static fn (&$v) => $v['target']['host'] = 'localhost dbname=other',
    static fn (&$v) => $v['target']['host'] = '/var/run/postgresql',
    static fn (&$v) => $v['target']['host'] = "localhost\0",
    static fn (&$v) => $v['target']['host'] = true,
    static fn (&$v) => $v['target']['port'] = 0,
    static fn (&$v) => $v['target']['port'] = 65536,
    static fn (&$v) => $v['target']['port'] = '55449',
    static fn (&$v) => $v['target']['port'] = 55449.0,
    static fn (&$v) => $v['target']['port'] = true,
    static fn (&$v) => $v['target']['database'] = 'Postgres',
    static fn (&$v) => $v['target']['database'] = 'db;options=-csearch_path=public',
    static fn (&$v) => $v['target']['database'] = str_repeat('d', 64),
    static fn (&$v) => $v['target']['database'] = 1,
    static fn (&$v) => $v['target']['databaseOid'] = 0,
    static fn (&$v) => $v['target']['databaseOid'] = 4294967296,
    static fn (&$v) => $v['target']['databaseOid'] = '1',
    static fn (&$v) => $v['target']['databaseOid'] = 1.0,
    static fn (&$v) => $v['target']['databaseOid'] = null,
    static fn (&$v) => $v['target']['login'] = 'reconciler;user=owner',
    static fn (&$v) => $v['target']['login'] = str_repeat('r', 64),
    static fn (&$v) => $v['target']['login'] = ['reconciler'],
    static fn (&$v) => $v['target']['serverAddress'] = 'localhost',
    static fn (&$v) => $v['target']['serverAddress'] = '127.0.0.1/32',
    static fn (&$v) => $v['target']['serverAddress'] = '',
    static fn (&$v) => $v['target']['serverAddress'] = 127001,
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('A', 64),
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('a', 63),
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('a', 65),
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('g', 64),
    static fn (&$v) => $v['target']['caSha256'] = null,
];
foreach ($mutations as $i => $mutate) {
    $candidate = $manifest; $mutate($candidate);
    $deny(static fn () => PrivatePublicationReconciliation::validateManifest($candidate), 'invalid array selector '.$i);
    $deny(static fn () => PrivatePublicationReconciliation::parseManifest($encode($candidate)), 'invalid JSON selector '.$i);
}

// Negative-only PDO: it cannot return a SQL row or fabricate expiry/replay.
final class UnavailablePublicationReconciliationPDO extends PDO
{
    public int $calls = 0;
    public function __construct(private Throwable $failure) {}
    public function getAttribute(int $attribute): mixed
    {
        ++$this->calls;
        throw $this->failure;
    }
}
$privateError = new PDOException('PRIVATE_RECONCILIATION_SQL_DETAIL_DO_NOT_EXPOSE');
$privateError->errorInfo = ['42501', 0, 'PRIVATE_RECONCILIATION_BOUND_VALUE'];
$db = new UnavailablePublicationReconciliationPDO($privateError);
$service = new PrivatePublicationReconciliation($db);
$expectedPlan = static fn (array $input): string => hash('sha256', "SITES_PUBLICATION_RECONCILIATION_PLAN_V1\0".
    json_encode(PrivatePublicationReconciliation::validateManifest($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$plan = $expectedPlan($manifest);
$invalid = $manifest; $invalid['operationRef'] = 'invalid';
$deny(static fn () => $service->plan($invalid, $manifest['target']['caSha256']), 'invalid plan input fails before PDO');
$deny(static fn () => $service->apply($invalid, $manifest['target']['caSha256'], $plan), 'invalid apply input fails before PDO');
foreach (['', str_repeat('b', 64), str_repeat('a', 63), str_repeat('A', 64), "private\0CA"] as $i => $ca) {
    $deny(static fn () => $service->plan($manifest, $ca), 'invalid or mismatched CA for plan '.$i);
    $deny(static fn () => $service->apply($manifest, $ca, $plan), 'invalid or mismatched CA for apply '.$i);
}
$canonical = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
foreach (['', str_repeat('b', 64), str_repeat('A', 64), str_repeat('a', 63), $plan."\n",
    hash('sha256', $canonical), hash('sha256', "SITES_PUBLICATION_INSPECTION_PLAN_V1\0".$canonical),
    hash('sha256', "SITES_PUBLICATION_RECONCILIATION_PLAN_V1\0".$canonical."\0")] as $i => $sha) {
    $deny(static fn () => $service->apply($manifest, $manifest['target']['caSha256'], $sha),
        'invalid, mismatched or differently scoped plan '.$i);
}
$ok($db->calls === 0, 'invalid inputs, CA mismatches and unreviewed plans cannot reach the database');
$deny(static fn () => $service->plan($manifest, $manifest['target']['caSha256']),
    'valid format cannot establish real SQL identity or private authority');
$ok($db->calls === 1, 'a valid selector still requires a real planning connection');
$db->calls = 0;
$deny(static fn () => $service->apply($reordered, $manifest['target']['caSha256'], $plan),
    'reordered input accepts the same canonical commitment but still requires SQL');
$ok($db->calls === 1, 'a matching plan does not fabricate a SQL mutation or replay');
$db->calls = 0;
$maximum = $manifest; $maximum['expectedRevision'] = PHP_INT_MAX - 1;
$deny(static fn () => $service->apply($maximum, $maximum['target']['caSha256'], $expectedPlan($maximum)),
    'the maximum bounded CAS is exact through hashing but still requires SQL');
$ok($db->calls === 1, 'bigint format alone cannot prove a journal head');
$changes = [
    static fn (&$v) => $v['operationRef'] = '22222222-2222-4222-8222-222222222222',
    static fn (&$v) => $v['expectedRevision'] = 3,
    static fn (&$v) => $v['target']['host'] = 'db.example.invalid',
    static fn (&$v) => $v['target']['port'] = 5432,
    static fn (&$v) => $v['target']['database'] = 'sites_staging',
    static fn (&$v) => $v['target']['databaseOid'] = 2,
    static fn (&$v) => $v['target']['login'] = 'other_reconciler',
    static fn (&$v) => $v['target']['serverAddress'] = '127.0.0.2',
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('b', 64),
];
foreach ($changes as $i => $mutate) {
    $candidate = $manifest; $mutate($candidate); $db->calls = 0;
    $deny(static fn () => $service->apply($candidate, $candidate['target']['caSha256'], $plan),
        'changed valid selector needs a new commitment '.$i);
    $ok($db->calls === 0 && $expectedPlan($candidate) !== $plan,
        'changed operation, CAS or target is denied before PDO '.$i);
}

$wrongDriver = new class extends PDO {
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return 'sqlite'; }
};
$activeTransaction = new class extends PDO {
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return 'pgsql'; }
    public function inTransaction(): bool { return true; }
};
$silentErrors = new class extends PDO {
    public function __construct() {}
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'pgsql' : PDO::ERRMODE_SILENT;
    }
    public function inTransaction(): bool { return false; }
};
$unavailableQuery = new class extends PDO {
    public function __construct() {}
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'pgsql' : PDO::ERRMODE_EXCEPTION;
    }
    public function inTransaction(): bool { return false; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        throw new PDOException('PRIVATE_RECONCILIATION_QUERY_DETAIL_DO_NOT_EXPOSE');
    }
};
foreach ([$wrongDriver, $activeTransaction, $silentErrors, $unavailableQuery] as $i => $connection) {
    $deny(static fn () => (new PrivatePublicationReconciliation($connection))
        ->plan($manifest, $manifest['target']['caSha256']), 'incompatible planning session '.$i);
    $deny(static fn () => (new PrivatePublicationReconciliation($connection))
        ->apply($manifest, $manifest['target']['caSha256'], $plan), 'incompatible apply session '.$i);
}

echo "PUBLICATION RECONCILIATION OFFLINE PASS: $checks strict selectors, CAS commitments and default-denial assertions; no SQL authority, expiration or replay claimed.\n";
