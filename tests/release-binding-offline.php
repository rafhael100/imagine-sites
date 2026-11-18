<?php

declare(strict_types=1);

// Exact selectors, plan commitment and default denials only. No successful SQL
// identity, principal, binding, revocation or replay is fabricated. The separate
// PG18/TLS fixture proves the existing APIs, private authority and CAS outcomes.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});

use Imagine\Sites\Publication\PrivateReleaseBinding;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline release binding failure: '.$label);
    ++$checks;
};
$deny = static function (callable $operation, string $label) use ($ok): void {
    $error = null;
    ob_start();
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error !== null && $error::class === RuntimeException::class &&
        $error->getMessage() === 'RELEASE_BINDING_DENIED' && $error->getPrevious() === null &&
        $error->getCode() === 0 && $output === '', $label.' has only the bounded denial');
};
$encode = static fn (array $value): string => json_encode($value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$bind = [
    'schema' => 'SITES_RELEASE_BINDING_V1',
    'target' => [
        'host' => 'localhost', 'port' => 55449, 'database' => 'postgres', 'databaseOid' => 1,
        'login' => 'rr_a_binding_writer', 'serverAddress' => '127.0.0.1', 'caSha256' => str_repeat('a', 64),
    ],
    'action' => 'bind',
    'siteRef' => '11111111-1111-4111-8111-111111111111',
    'expectedBindingRevision' => 0,
    'releaseRef' => 'offline.release-1',
    'editorialRevision' => 3,
];
$revoke = $bind;
$revoke['target']['login'] = 'rr_a_revoker';
$revoke['action'] = 'revoke';
$revoke['expectedBindingRevision'] = 1;
unset($revoke['releaseRef'], $revoke['editorialRevision']);
$ok(PHP_INT_SIZE === 8, 'the bigint candidate requires a 64-bit PHP integer runtime');
foreach ([$bind, $revoke] as $manifest) {
    $json = $encode($manifest);
    $ok(PrivateReleaseBinding::validateManifest($manifest) === $manifest &&
        PrivateReleaseBinding::parseManifest($json) === $manifest,
        $manifest['action'].' accepts only the exact private selector as format');
    $reordered = array_reverse($manifest, true);
    $reordered['target'] = array_reverse($manifest['target'], true);
    $ok(PrivateReleaseBinding::parseManifest($encode($reordered)) === $manifest,
        $manifest['action'].' canonicalizes every key without changing values');
    $escaped = str_replace(['"schema":', '"host":'], ['"\\u0073chema":', '"\\u0068ost":'], $json);
    $ok(PrivateReleaseBinding::parseManifest($escaped) === $manifest,
        $manifest['action'].' accepts unambiguous escaped JSON keys');
    $ok(PrivateReleaseBinding::parseManifest(str_pad($json, 16384, " \t\r\n")) === $manifest,
        $manifest['action'].' counts JSON whitespace within its transport bound');
    $maximum = $manifest;
    $maximum['expectedBindingRevision'] = PHP_INT_MAX - 1;
    $ok(PrivateReleaseBinding::parseManifest($encode($maximum)) === $maximum,
        $manifest['action'].' preserves the largest exact CAS that can be incremented');

    foreach ([
        '', 'null', 'false', '1', '"selector"', '[]', '[{}]', '{}', '{',
        $json.' trailing', str_pad($json, 16385, ' '), $json."\0",
        str_replace('localhost', "invalid\xC3\x28", $json),
        str_replace('localhost', '\\uD800', $json),
        str_repeat('[', 16).str_repeat(']', 16),
        // A valid final occurrence does not rescue an ambiguous object.
        str_replace('"schema":', '"schema":"WRONG","schema":', $json),
        str_replace('"schema":', '"schema":"WRONG","\\u0073chema":', $json),
        str_replace('"host":', '"host":"bad;options=1","host":', $json),
        str_replace('"host":', '"\\u0068ost":"bad;options=1","host":', $json),
        str_replace('"databaseOid":1', '"databaseOid":2,"databaseOid":1', $json),
        str_replace('"action":', '"action":"publish","action":', $json),
        str_replace('"siteRef":', '"siteRef":"bad","siteRef":', $json),
        str_replace('"expectedBindingRevision":', '"expectedBindingRevision":-1,"expectedBindingRevision":', $json),
        str_replace('"expectedBindingRevision":', '"expectedBindingRevision":-1,"\\u0065xpectedBindingRevision":', $json),
    ] as $i => $raw) {
        $deny(static fn () => PrivateReleaseBinding::parseManifest($raw),
            $manifest['action'].' ambiguous or malformed transport '.$i);
    }
}
foreach ([
    ['host' => '127.0.0.1', 'port' => 1, 'databaseOid' => 1],
    ['host' => '::1', 'serverAddress' => '::1', 'port' => 65535, 'databaseOid' => 4294967295],
    ['host' => 'db-1.example.invalid', 'database' => '_'.str_repeat('d', 62), 'login' => '_'.str_repeat('r', 62)],
    ['host' => 'DB.EXAMPLE.INVALID'],
] as $i => $replacement) {
    $candidate = $bind;
    $candidate['target'] = array_replace($candidate['target'], $replacement);
    $ok(PrivateReleaseBinding::parseManifest($encode($candidate)) === $candidate,
        'allowed target bounds preserve exact values '.$i);
}
foreach ([['releaseRef' => 'a', 'editorialRevision' => 1],
    ['releaseRef' => 'A'.str_repeat(':._-1', 25).'zz', 'editorialRevision' => 2147483647]] as $i => $replacement) {
    $candidate = array_replace($bind, $replacement);
    $ok(PrivateReleaseBinding::parseManifest($encode($candidate)) === $candidate,
        'bind selector bounds are format only, not proof of an editorial head '.$i);
}

$mutations = [
    static function (&$v): void { unset($v['schema']); },
    static function (&$v): void { unset($v['target']); },
    static function (&$v): void { unset($v['action']); },
    static function (&$v): void { unset($v['siteRef']); },
    static function (&$v): void { unset($v['expectedBindingRevision']); },
    static function (&$v): void { unset($v['releaseRef']); },
    static function (&$v): void { unset($v['editorialRevision']); },
    static fn (&$v) => $v['schema'] = 'SITES_RELEASE_BINDING_V2',
    static fn (&$v) => $v['schema'] = 1,
    static fn (&$v) => $v['action'] = 'revoke', // bind-only fields must not survive a changed action
    static fn (&$v) => $v['action'] = 'publish',
    static fn (&$v) => $v['action'] = 'BIND',
    static fn (&$v) => $v['action'] = "bind\n",
    static fn (&$v) => $v['action'] = ['bind'],
    static fn (&$v) => $v['action'] = true,
    static fn (&$v) => $v['tenantRef'] = 'must-not-select-a-tenant',
    static fn (&$v) => $v['orderRef'] = 'must-not-authorize-an-order',
    static fn (&$v) => $v['contractRef'] = 'must-not-authorize-a-contract',
    static fn (&$v) => $v['productCode'] = 'onepage',
    static fn (&$v) => $v['actorRole'] = 'binding_writer',
    static fn (&$v) => $v['artifactDigest'] = str_repeat('a', 64),
    static fn (&$v) => $v['html'] = '<html>not an input</html>',
    static fn (&$v) => $v['sizeBytes'] = 10,
    static fn (&$v) => $v['canPublish'] = true,
    static fn (&$v) => $v['created'] = false,
    static fn (&$v) => $v['replayed'] = true,
    static fn (&$v) => $v['siteRef'] = strtoupper('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
    static fn (&$v) => $v['siteRef'] = '11111111111141118111111111111111',
    static fn (&$v) => $v['siteRef'] .= "\n",
    static fn (&$v) => $v['siteRef'] .= "\0",
    static fn (&$v) => $v['siteRef'] = ['11111111-1111-4111-8111-111111111111'],
    static fn (&$v) => $v['expectedBindingRevision'] = -1,
    static fn (&$v) => $v['expectedBindingRevision'] = PHP_INT_MAX,
    static fn (&$v) => $v['expectedBindingRevision'] = '0',
    static fn (&$v) => $v['expectedBindingRevision'] = (string) (PHP_INT_MAX - 1),
    static fn (&$v) => $v['expectedBindingRevision'] = 0.0,
    static fn (&$v) => $v['expectedBindingRevision'] = true,
    static fn (&$v) => $v['releaseRef'] = '',
    static fn (&$v) => $v['releaseRef'] = str_repeat('r', 129),
    static fn (&$v) => $v['releaseRef'] = 'release/ref',
    static fn (&$v) => $v['releaseRef'] = '.release',
    static fn (&$v) => $v['releaseRef'] = 'release😀',
    static fn (&$v) => $v['releaseRef'] .= "\n",
    static fn (&$v) => $v['releaseRef'] = 1,
    static fn (&$v) => $v['editorialRevision'] = 0,
    static fn (&$v) => $v['editorialRevision'] = 2147483648,
    static fn (&$v) => $v['editorialRevision'] = '3',
    static fn (&$v) => $v['editorialRevision'] = 3.0,
    static fn (&$v) => $v['editorialRevision'] = true,
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
    static fn (&$v) => $v['target']['login'] = 'binder;user=owner',
    static fn (&$v) => $v['target']['login'] = str_repeat('r', 64),
    static fn (&$v) => $v['target']['login'] = ['binder'],
    static fn (&$v) => $v['target']['serverAddress'] = 'localhost',
    static fn (&$v) => $v['target']['serverAddress'] = '127.0.0.1/32',
    static fn (&$v) => $v['target']['serverAddress'] = '',
    static fn (&$v) => $v['target']['serverAddress'] = 127001,
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('A', 64),
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('a', 63),
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('g', 64),
    static fn (&$v) => $v['target']['caSha256'] = null,
];
foreach ($mutations as $i => $mutate) {
    $candidate = $bind;
    $mutate($candidate);
    $deny(static fn () => PrivateReleaseBinding::validateManifest($candidate), 'invalid array selector '.$i);
    $deny(static fn () => PrivateReleaseBinding::parseManifest($encode($candidate)), 'invalid JSON selector '.$i);
}
foreach ([['expectedBindingRevision' => 0], ['expectedBindingRevision' => -1],
    ['expectedBindingRevision' => PHP_INT_MAX], ['expectedBindingRevision' => '1'],
    ['expectedBindingRevision' => 1.0], ['expectedBindingRevision' => true], ['action' => 'bind'],
    ['releaseRef' => 'must-not-select-release'], ['releaseRef' => null], ['editorialRevision' => 3],
    ['artifactDigest' => str_repeat('a', 64)], ['html' => 'not accepted'], ['actorRole' => 'revoker']] as $i => $replacement) {
    $candidate = array_replace($revoke, $replacement);
    $deny(static fn () => PrivateReleaseBinding::parseManifest($encode($candidate)), 'invalid revoke-only shape '.$i);
}
$json = $encode($bind);
foreach ([
    str_replace('"releaseRef":', '"releaseRef":"bad/ref","releaseRef":', $json),
    str_replace('"editorialRevision":3', '"editorialRevision":0,"editorialRevision":3', $json),
    str_replace('"expectedBindingRevision":0', '"expectedBindingRevision":9223372036854775808', $json),
] as $i => $raw) {
    $deny(static fn () => PrivateReleaseBinding::parseManifest($raw), 'bind duplicate or bigint overflow '.$i);
}

// Negative-only PDO: it cannot return SQL rows or fabricate mutation/replay.
final class UnavailableReleaseBindingPDO extends PDO
{
    public int $calls = 0;
    public function __construct(private Throwable $failure) {}
    public function getAttribute(int $attribute): mixed
    {
        ++$this->calls;
        throw $this->failure;
    }
}
$privateError = new PDOException('PRIVATE_BINDING_SQL_DETAIL_DO_NOT_EXPOSE');
$privateError->errorInfo = ['42501', 0, 'PRIVATE_BINDING_BOUND_VALUE'];
$db = new UnavailableReleaseBindingPDO($privateError);
$service = new PrivateReleaseBinding($db);
$expectedPlan = static fn (array $input): string => hash('sha256', "SITES_RELEASE_BINDING_PLAN_V1\0".
    json_encode(PrivateReleaseBinding::validateManifest($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$bindPlan = $expectedPlan($bind);
$revokePlan = $expectedPlan($revoke);
$ok($bindPlan !== $revokePlan, 'different actions cannot share an input commitment');
foreach ([$bind, $revoke] as $manifest) {
    $plan = $expectedPlan($manifest);
    $invalid = $manifest; $invalid['siteRef'] = 'invalid';
    $deny(static fn () => $service->plan($invalid, $manifest['target']['caSha256']),
        $manifest['action'].' invalid plan input fails before PDO');
    $deny(static fn () => $service->apply($invalid, $manifest['target']['caSha256'], $plan),
        $manifest['action'].' invalid apply input fails before PDO');
    foreach (['', str_repeat('b', 64), str_repeat('a', 63), str_repeat('A', 64), "private\0CA"] as $i => $ca) {
        $deny(static fn () => $service->plan($manifest, $ca), $manifest['action'].' invalid/mismatched CA '.$i);
    }
    foreach (['', str_repeat('b', 64), str_repeat('A', 64), str_repeat('a', 63), $plan."\n",
        $manifest['action'] === 'bind' ? $revokePlan : $bindPlan] as $i => $sha) {
        $deny(static fn () => $service->apply($manifest, $manifest['target']['caSha256'], $sha),
            $manifest['action'].' invalid, mismatched or other-action plan '.$i);
    }
}
$ok($db->calls === 0, 'invalid inputs, mismatched CA and stale plans cannot reach the database');
foreach ([$bind, $revoke] as $manifest) {
    $db->calls = 0;
    $deny(static fn () => $service->plan($manifest, $manifest['target']['caSha256']),
        $manifest['action'].' format cannot establish a real SQL identity or private authority');
    $ok($db->calls === 1, $manifest['action'].' valid input still requires a real SQL plan connection');
    $db->calls = 0;
    $reordered = array_reverse($manifest, true); $reordered['target'] = array_reverse($manifest['target'], true);
    $deny(static fn () => $service->apply($reordered, $manifest['target']['caSha256'], $expectedPlan($manifest)),
        $manifest['action'].' canonical input accepts the same commitment but still requires SQL');
    $ok($db->calls === 1, $manifest['action'].' matching hash cannot create an offline success');
}
$changes = [
    static fn (&$v) => $v['siteRef'] = '22222222-2222-4222-8222-222222222222',
    static fn (&$v) => $v['expectedBindingRevision'] = 2,
    static fn (&$v) => $v['target']['host'] = 'db.example.invalid',
    static fn (&$v) => $v['target']['port'] = 5432,
    static fn (&$v) => $v['target']['database'] = 'sites_staging',
    static fn (&$v) => $v['target']['databaseOid'] = 2,
    static fn (&$v) => $v['target']['login'] = 'other_operator',
    static fn (&$v) => $v['target']['serverAddress'] = '127.0.0.2',
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('b', 64),
];
foreach ([$bind, $revoke] as $manifest) {
    $mutations = $changes;
    if ($manifest['action'] === 'bind') {
        $mutations[] = static fn (&$v) => $v['releaseRef'] = 'offline.release-2';
        $mutations[] = static fn (&$v) => $v['editorialRevision'] = 4;
    }
    foreach ($mutations as $i => $mutate) {
        $candidate = $manifest; $mutate($candidate); $db->calls = 0;
        $deny(static fn () => $service->apply($candidate, $candidate['target']['caSha256'], $expectedPlan($manifest)),
            $manifest['action'].' changed valid selector needs a new commitment '.$i);
        $ok($db->calls === 0 && $expectedPlan($candidate) !== $expectedPlan($manifest),
            $manifest['action'].' changed target or CAS is denied before PDO '.$i);
    }
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
        throw new PDOException('PRIVATE_BINDING_QUERY_DETAIL_DO_NOT_EXPOSE');
    }
};
foreach ([$bind, $revoke] as $manifest) {
    foreach ([$wrongDriver, $activeTransaction, $silentErrors, $unavailableQuery] as $i => $connection) {
        $deny(static fn () => (new PrivateReleaseBinding($connection))
            ->plan($manifest, $manifest['target']['caSha256']), $manifest['action'].' incompatible planning session '.$i);
        $deny(static fn () => (new PrivateReleaseBinding($connection))
            ->apply($manifest, $manifest['target']['caSha256'], $expectedPlan($manifest)),
            $manifest['action'].' incompatible apply session '.$i);
    }
}

echo "RELEASE BINDING OFFLINE PASS: $checks strict selectors, action/CAS commitments and default-denial assertions; no SQL authority or replay claimed.\n";
