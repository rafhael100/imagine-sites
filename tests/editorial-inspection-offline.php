<?php

declare(strict_types=1);

// Pure selector/default-denial tests only. No successful SQL connection,
// reader binding or editorial snapshot is fabricated. PG18/TLS CI proves
// the SQL/migration, cross-tenant isolation and real readonly CLI separately.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../apps/sites/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});

use App\Editorial\PrivateEditorialInspection;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline editorial inspection failure: '.$label);
    ++$checks;
};
$deny = static function (callable $operation, string $label) use ($ok): void {
    $error = null;
    ob_start();
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error !== null && $error::class === RuntimeException::class &&
        $error->getMessage() === 'EDITORIAL_INSPECTION_DENIED' && $error->getPrevious() === null &&
        $error->getCode() === 0 && $output === '', $label.' has only the bounded denial');
};
$encode = static fn (array $value): string => json_encode($value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$manifest = [
    'schema' => 'SITES_EDITORIAL_INSPECTION_V1',
    'target' => [
        'host' => 'localhost', 'port' => 55449, 'database' => 'postgres', 'databaseOid' => 1,
        'login' => 'ei_a_reader', 'serverAddress' => '127.0.0.1', 'caSha256' => str_repeat('a', 64),
    ],
    'siteRef' => '11111111-1111-4111-8111-111111111111',
];
$json = $encode($manifest);
$ok(PrivateEditorialInspection::validateManifest($manifest) === $manifest &&
    PrivateEditorialInspection::parseManifest($json) === $manifest,
    'a strict private selector is accepted as format only');
$reordered = array_reverse($manifest, true);
$reordered['target'] = array_reverse($manifest['target'], true);
$ok(PrivateEditorialInspection::parseManifest($encode($reordered)) === $manifest,
    'keys are canonical without adding authority or data');
$escapedKeys = str_replace(['"schema":', '"host":'], ['"\\u0073chema":', '"\\u0068ost":'], $json);
$ok(PrivateEditorialInspection::parseManifest($escapedKeys) === $manifest,
    'one unambiguous escaped key has normal JSON semantics');
$ok(PrivateEditorialInspection::parseManifest(str_pad($json, 16384, " \t\r\n")) === $manifest,
    'the 16384-byte transport bound includes JSON whitespace');

foreach ([
    ['host' => '127.0.0.1', 'port' => 1, 'databaseOid' => 1],
    ['host' => '::1', 'serverAddress' => '::1', 'port' => 65535, 'databaseOid' => 4294967295],
    ['host' => 'db-1.example.invalid', 'database' => '_'.str_repeat('d', 62), 'login' => '_'.str_repeat('r', 62)],
    ['host' => 'DB.EXAMPLE.INVALID'],
] as $i => $replacement) {
    $candidate = $manifest;
    $candidate['target'] = array_replace($candidate['target'], $replacement);
    $ok(PrivateEditorialInspection::parseManifest($encode($candidate)) === $candidate,
        'allowed coordinates preserve exact values '.$i);
}

$mutations = [
    static function (&$v): void { unset($v['schema']); },
    static function (&$v): void { unset($v['target']); },
    static function (&$v): void { unset($v['siteRef']); },
    static fn (&$v) => $v['schema'] = 'SITES_EDITORIAL_INSPECTION_V2',
    static fn (&$v) => $v['schema'] = 1,
    static fn (&$v) => $v['tenantRef'] = 'must-not-select-a-tenant',
    static fn (&$v) => $v['canPublish'] = true,
    static fn (&$v) => $v['expectedRevision'] = 0,
    static fn (&$v) => $v['action'] = 'register',
    static fn (&$v) => $v['clientAccepted'] = true,
    static fn (&$v) => $v['artifactDigest'] = str_repeat('b', 64),
    static fn (&$v) => $v['reader'] = true,
    static fn (&$v) => $v['siteRef'] = strtoupper('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
    static fn (&$v) => $v['siteRef'] = '11111111111141118111111111111111',
    static fn (&$v) => $v['siteRef'] .= "\n",
    static fn (&$v) => $v['siteRef'] .= "\0",
    static fn (&$v) => $v['siteRef'] = ['11111111-1111-4111-8111-111111111111'],
    static fn (&$v) => $v['siteRef'] = null,
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
    static fn (&$v) => $v['target']['login'] = 'reader;user=owner',
    static fn (&$v) => $v['target']['login'] = str_repeat('r', 64),
    static fn (&$v) => $v['target']['login'] = ['reader'],
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
    $candidate = $manifest;
    $mutate($candidate);
    $deny(static fn () => PrivateEditorialInspection::validateManifest($candidate), 'invalid array selector '.$i);
    $deny(static fn () => PrivateEditorialInspection::parseManifest($encode($candidate)), 'invalid JSON selector '.$i);
}

foreach ([
    '', 'null', 'false', '1', '"selector"', '[]', '[{}]', '{}', '{',
    $json.' trailing', str_pad($json, 16385, ' '), $json."\0",
    str_replace('localhost', "invalid\xC3\x28", $json),
    str_replace('localhost', '\\uD800', $json),
    str_repeat('[', 16).str_repeat(']', 16),
    // The final occurrence is valid, so silently keeping it would accept these.
    str_replace('"schema":', '"schema":"WRONG","schema":', $json),
    str_replace('"schema":', '"schema":"WRONG","\\u0073chema":', $json),
    str_replace('"host":', '"host":"bad;options=1","host":', $json),
    str_replace('"host":', '"\\u0068ost":"bad;options=1","host":', $json),
    str_replace('"databaseOid":1', '"databaseOid":2,"databaseOid":1', $json),
    str_replace('"siteRef":', '"siteRef":"bad","siteRef":', $json),
    str_replace('"siteRef":', '"siteRef":"bad","\\u0073iteRef":', $json),
    str_replace('"target":', '"target":{},"target":', $json),
] as $i => $raw) {
    $deny(static fn () => PrivateEditorialInspection::parseManifest($raw), 'ambiguous or malformed transport '.$i);
}

// Negative-only PDO: no SQL query or successful connection can be fabricated.
final class UnavailableEditorialInspectionPDO extends PDO
{
    public int $calls = 0;
    public function __construct(private Throwable $failure) {}
    public function getAttribute(int $attribute): mixed
    {
        ++$this->calls;
        throw $this->failure;
    }
}
$privateError = new PDOException('PRIVATE_INSPECTION_SQL_DETAIL_DO_NOT_EXPOSE');
$privateError->errorInfo = ['42501', 0, 'PRIVATE_INSPECTION_BOUND_VALUE'];
$db = new UnavailableEditorialInspectionPDO($privateError);
$inspector = new PrivateEditorialInspection($db);
$invalid = $manifest;
$invalid['siteRef'] = 'invalid';
$deny(static fn () => $inspector->inspect($invalid, $manifest['target']['caSha256']),
    'invalid manifest is refused before a connection');
foreach (['', str_repeat('b', 64), str_repeat('a', 63), str_repeat('A', 64), "private\0CA"] as $i => $ca) {
    $deny(static fn () => $inspector->inspect($manifest, $ca), 'invalid or mismatched observed CA '.$i);
}
$ok($db->calls === 0, 'malformed input and CA mismatch never reach PDO');
$deny(static fn () => $inspector->inspect($manifest, $manifest['target']['caSha256']),
    'unavailable SQL connection hides all private diagnostics without Laravel boot');
$ok($db->calls === 1, 'a valid selector still needs a real SQL connection');

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
        throw new PDOException('PRIVATE_INSPECTION_QUERY_DETAIL_DO_NOT_EXPOSE');
    }
};
foreach ([$wrongDriver, $activeTransaction, $silentErrors, $unavailableQuery] as $i => $connection) {
    $deny(static fn () => (new PrivateEditorialInspection($connection))
        ->inspect($manifest, $manifest['target']['caSha256']), 'ineligible or unavailable SQL session '.$i);
}

// These pins protect the migration and runtime from an accidental mismatch
// between reviewed SQL bytes and their allowlisted definition. They do not
// claim that SQL compiles or that any reader has authority.
$sql = file_get_contents(__DIR__.'/../contracts/editorial/003-read-editorial-head.sql');
$migration = file_get_contents(__DIR__.'/../apps/sites/database/migrations/2026_11_18_000009_add_editorial_head_read_api.php');
$parts = explode('$editorial_head$', $sql);
$ok(count($parts) === 3, 'one unambiguous SQL function body is available');
$functionHash = hash('sha256', $parts[1]);
$ok((new ReflectionClass(PrivateEditorialInspection::class))->getConstant('FUNCTION_SHA256') === $functionHash,
    'runtime definition pin matches the exact reviewed function body');
$ok(preg_match("/private const FUNCTION_SHA256 = '([a-f0-9]{64})';/", $migration, $functionPin) === 1 &&
    $functionPin[1] === $functionHash,
    'migration definition pin matches the exact reviewed function body');
$ok(preg_match("/private const CONTRACT_SHA256 = '([a-f0-9]{64})';/", $migration, $contractPin) === 1 &&
    $contractPin[1] === hash('sha256', $sql),
    'migration contract pin includes the complete reviewed SQL file');

echo "EDITORIAL INSPECTION OFFLINE PASS: $checks input, definition-pin and default-denial assertions; no SQL authority claimed.\n";
