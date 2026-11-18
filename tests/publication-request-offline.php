<?php

declare(strict_types=1);

// Exact selectors, input commitment and default denials only. No successful
// SQL identity, private principal, operation or replay is fabricated here.
// The PG18/TLS fixture proves the request, all replay states and projections.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});

use Imagine\Sites\Publication\PrivatePublicationRequest;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline publication request failure: '.$label);
    ++$checks;
};
$deny = static function (callable $operation, string $label) use ($ok): void {
    $error = null;
    ob_start();
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error !== null && $error::class === RuntimeException::class &&
        $error->getMessage() === 'PUBLICATION_REQUEST_DENIED' && $error->getPrevious() === null &&
        $error->getCode() === 0 && $output === '', $label.' has only the bounded denial');
};
$encode = static fn (array $value): string => json_encode($value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$manifest = [
    'schema' => 'SITES_PUBLICATION_REQUEST_V1',
    'target' => [
        'host' => 'localhost', 'port' => 55449, 'database' => 'postgres', 'databaseOid' => 1,
        'login' => 'pj_request_a_writer', 'serverAddress' => '127.0.0.1', 'caSha256' => str_repeat('a', 64),
    ],
    'siteRef' => '11111111-1111-4111-8111-111111111111',
    'releaseRef' => 'release-local-1',
    'expectedBindingRevision' => 1,
    'portalAcceptanceRef' => 'acceptance-reference-is-not-verified-consent',
    'idempotencyKey' => 'request-local-1',
    'receiptRef' => 'request_receipt-1',
];
$json = $encode($manifest);
$ok(PHP_INT_SIZE === 8, 'the bigint selector requires a 64-bit PHP integer runtime');
$ok(PrivatePublicationRequest::validateManifest($manifest) === $manifest &&
    PrivatePublicationRequest::parseManifest($json) === $manifest,
    'the exact private request input is accepted as format only');
$reordered = array_reverse($manifest, true);
$reordered['target'] = array_reverse($manifest['target'], true);
$ok(PrivatePublicationRequest::parseManifest($encode($reordered)) === $manifest,
    'all eight root and seven target keys have a deterministic canonical order');
$escaped = str_replace(['"schema":', '"host":', '"receiptRef":'],
    ['"\\u0073chema":', '"\\u0068ost":', '"\\u0072eceiptRef":'], $json);
$ok(PrivatePublicationRequest::parseManifest($escaped) === $manifest,
    'unambiguous escaped JSON keys retain their normal meaning');
$ok(PrivatePublicationRequest::parseManifest(str_pad($json, 16384, " \t\r\n")) === $manifest,
    'JSON whitespace counts within the exact transport bound');
foreach ([2, 2147483648, PHP_INT_MAX] as $revision) {
    $candidate = $manifest; $candidate['expectedBindingRevision'] = $revision;
    $ok(PrivatePublicationRequest::parseManifest($encode($candidate)) === $candidate,
        'a positive bigint selects a binding without incrementing a journal revision '.$revision);
}
foreach (['releaseRef', 'portalAcceptanceRef', 'idempotencyKey'] as $key) {
    foreach (['a', 'A0._:-', str_repeat('z', 128)] as $reference) {
        $candidate = $manifest; $candidate[$key] = $reference;
        $ok(PrivatePublicationRequest::parseManifest($encode($candidate)) === $candidate,
            'opaque reference format and bounds preserve exact bytes '.$key.' '.strlen($reference));
    }
}
foreach (['a', 'A0_-', str_repeat('z', 64)] as $reference) {
    $candidate = $manifest; $candidate['receiptRef'] = $reference;
    $ok(PrivatePublicationRequest::parseManifest($encode($candidate)) === $candidate,
        'a receipt name is a bounded single local name '.strlen($reference));
}
foreach ([
    ['host' => '127.0.0.1', 'port' => 1, 'databaseOid' => 1],
    ['host' => '::1', 'serverAddress' => '::1', 'port' => 65535, 'databaseOid' => 4294967295],
    ['host' => 'db-1.example.invalid', 'database' => '_'.str_repeat('d', 62), 'login' => '_'.str_repeat('r', 62)],
    ['host' => 'DB.EXAMPLE.INVALID'],
] as $i => $replacement) {
    $candidate = $manifest;
    $candidate['target'] = array_replace($candidate['target'], $replacement);
    $ok(PrivatePublicationRequest::parseManifest($encode($candidate)) === $candidate,
        'allowed target bounds preserve exact coordinates '.$i);
}

foreach ([
    '', 'null', 'false', '1', '"request"', '[]', '[{}]', '{}', '{',
    $json.' trailing', str_pad($json, 16385, ' '), $json."\0",
    str_replace('localhost', "invalid\xC3\x28", $json),
    str_replace('localhost', '\\uD800', $json),
    str_repeat('[', 16).str_repeat(']', 16),
    str_replace('"expectedBindingRevision":1', '"expectedBindingRevision":9223372036854775808', $json),
    str_replace('"expectedBindingRevision":1', '"expectedBindingRevision":1e0', $json),
] as $i => $raw) {
    $deny(static fn () => PrivatePublicationRequest::parseManifest($raw),
        'malformed or unbounded transport '.$i);
}
// A valid final occurrence cannot rescue duplicate keys, including escapes.
foreach (array_keys($manifest) as $key) {
    foreach ([$key, '\\u'.sprintf('%04x', ord($key[0])).substr($key, 1)] as $spelling) {
        $raw = str_replace('"'.$key.'":', '"'.$key.'":null,"'.$spelling.'":', $json);
        $deny(static fn () => PrivatePublicationRequest::parseManifest($raw), 'duplicate root field '.$spelling);
    }
}
foreach (['host', 'databaseOid', 'login', 'caSha256'] as $key) {
    foreach ([$key, '\\u'.sprintf('%04x', ord($key[0])).substr($key, 1)] as $spelling) {
        $raw = str_replace('"'.$key.'":', '"'.$key.'":null,"'.$spelling.'":', $json);
        $deny(static fn () => PrivatePublicationRequest::parseManifest($raw), 'duplicate target field '.$spelling);
    }
}

// None of these can be supplied as authority, an operation or a provider ACK.
foreach ([
    'tenantRef' => 'must-not-select-a-tenant', 'operationRef' => $manifest['siteRef'],
    'orderRef' => 'not-order-authority', 'contractRef' => 'not-contract-authority',
    'contractRevision' => 1, 'productCode' => 'onepage', 'actorRole' => 'writer',
    'authorizationEpoch' => 1, 'action' => 'request', 'expectedRevision' => 1,
    'artifactDigest' => str_repeat('a', 64), 'receiptDigest' => str_repeat('b', 64),
    'providerRequestRef' => 'must-not-invent-an-ack', 'leaseToken' => $manifest['siteRef'],
    'leaseSeconds' => 1, 'leaseExpiresAt' => '2026-11-18T00:00:00.000000Z',
    'observedAt' => '2026-11-19T00:00:00.000000Z', 'technicalStatus' => 'requested',
    'retry' => true, 'reset' => true, 'created' => false, 'replayed' => true,
    'receiptPath' => '/tmp/not-allowed.json', 'receiptParent' => '/tmp/not-allowed',
    'canPublish' => true, 'publicationVerified' => true, 'canAcceptRealOrders' => true,
] as $key => $value) {
    $candidate = $manifest; $candidate[$key] = $value;
    $deny(static fn () => PrivatePublicationRequest::validateManifest($candidate), 'forbidden input '.$key);
    $deny(static fn () => PrivatePublicationRequest::parseManifest($encode($candidate)), 'forbidden JSON field '.$key);
}
foreach (array_keys($manifest) as $key) {
    $candidate = $manifest; unset($candidate[$key]);
    $deny(static fn () => PrivatePublicationRequest::validateManifest($candidate), 'missing required field '.$key);
    $deny(static fn () => PrivatePublicationRequest::parseManifest($encode($candidate)), 'missing required JSON field '.$key);
}
foreach (['releaseRef', 'portalAcceptanceRef', 'idempotencyKey'] as $key) {
    foreach (['', str_repeat('a', 129), '-starts-with-punctuation', 'a/b', 'a b', "a\n", "a\0",
        'acentuação', 'emoji-😀', 1, 1.0, true, null, ['request']] as $i => $reference) {
        $candidate = $manifest; $candidate[$key] = $reference;
        $deny(static fn () => PrivatePublicationRequest::validateManifest($candidate), 'invalid opaque selector '.$key.' '.$i);
        $deny(static fn () => PrivatePublicationRequest::parseManifest($encode($candidate)), 'invalid opaque JSON selector '.$key.' '.$i);
    }
}
foreach (['', str_repeat('a', 65), '-receipt', '_receipt', '.', '..', 'receipt.json', 'receipt:name',
    '/absolute', 'parent/name', 'parent\\name', 'a b', "a\n", "a\0", 'recibo-á', 1, true, null, ['receipt']] as $i => $reference) {
    $candidate = $manifest; $candidate['receiptRef'] = $reference;
    $deny(static fn () => PrivatePublicationRequest::validateManifest($candidate), 'invalid receipt name '.$i);
    $deny(static fn () => PrivatePublicationRequest::parseManifest($encode($candidate)), 'invalid JSON receipt name '.$i);
}
$mutations = [
    static fn (&$v) => $v['schema'] = 'SITES_PUBLICATION_REQUEST_V2',
    static fn (&$v) => $v['schema'] = 'SITES_PUBLICATION_RECONCILIATION_V1',
    static fn (&$v) => $v['schema'] = 1,
    static fn (&$v) => $v['siteRef'] = strtoupper('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
    static fn (&$v) => $v['siteRef'] = '11111111111141118111111111111111',
    static fn (&$v) => $v['siteRef'] .= "\n",
    static fn (&$v) => $v['siteRef'] .= "\0",
    static fn (&$v) => $v['siteRef'] = ['11111111-1111-4111-8111-111111111111'],
    static fn (&$v) => $v['siteRef'] = null,
    static fn (&$v) => $v['expectedBindingRevision'] = 0,
    static fn (&$v) => $v['expectedBindingRevision'] = -1,
    static fn (&$v) => $v['expectedBindingRevision'] = '1',
    static fn (&$v) => $v['expectedBindingRevision'] = (string) PHP_INT_MAX,
    static fn (&$v) => $v['expectedBindingRevision'] = 1.0,
    static fn (&$v) => $v['expectedBindingRevision'] = true,
    static fn (&$v) => $v['expectedBindingRevision'] = null,
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
    static fn (&$v) => $v['target']['login'] = 'writer;user=owner',
    static fn (&$v) => $v['target']['login'] = str_repeat('r', 64),
    static fn (&$v) => $v['target']['login'] = ['writer'],
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
    $deny(static fn () => PrivatePublicationRequest::validateManifest($candidate), 'invalid array selector '.$i);
    $deny(static fn () => PrivatePublicationRequest::parseManifest($encode($candidate)), 'invalid JSON selector '.$i);
}

// Negative-only PDO: it cannot return an operation or fabricate SQL authority.
final class UnavailablePublicationRequestPDO extends PDO
{
    public int $calls = 0;
    public function __construct(private Throwable $failure) {}
    public function getAttribute(int $attribute): mixed
    {
        ++$this->calls;
        throw $this->failure;
    }
}
$privateError = new PDOException('PRIVATE_REQUEST_SQL_DETAIL_DO_NOT_EXPOSE');
$privateError->errorInfo = ['42501', 0, 'PRIVATE_REQUEST_BOUND_VALUE'];
$db = new UnavailablePublicationRequestPDO($privateError);
$service = new PrivatePublicationRequest($db);
$expectedPlan = static fn (array $input): string => hash('sha256', "SITES_PUBLICATION_REQUEST_PLAN_V1\0".
    json_encode(PrivatePublicationRequest::validateManifest($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$plan = $expectedPlan($manifest);
$invalid = $manifest; $invalid['siteRef'] = 'invalid';
$deny(static fn () => $service->plan($invalid, $manifest['target']['caSha256']), 'invalid plan input fails before PDO');
$deny(static fn () => $service->apply($invalid, $manifest['target']['caSha256'], $plan), 'invalid apply input fails before PDO');
foreach (['', str_repeat('b', 64), str_repeat('a', 63), str_repeat('A', 64), "private\0CA"] as $i => $ca) {
    $deny(static fn () => $service->plan($manifest, $ca), 'invalid or mismatched CA for plan '.$i);
    $deny(static fn () => $service->apply($manifest, $ca, $plan), 'invalid or mismatched CA for apply '.$i);
}
$canonical = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
foreach (['', str_repeat('b', 64), str_repeat('A', 64), str_repeat('a', 63), $plan."\n",
    hash('sha256', $canonical), hash('sha256', "SITES_PUBLICATION_RECONCILIATION_PLAN_V1\0".$canonical),
    hash('sha256', "SITES_PUBLICATION_REQUEST_PLAN_V1\0".$canonical."\0")] as $i => $sha) {
    $deny(static fn () => $service->apply($manifest, $manifest['target']['caSha256'], $sha),
        'invalid, mismatched or differently scoped plan '.$i);
}
$ok($db->calls === 0, 'invalid inputs, CA mismatches and unreviewed plans cannot reach the database');
$deny(static fn () => $service->plan($manifest, $manifest['target']['caSha256']),
    'valid format cannot establish real SQL identity or private writer authority');
$ok($db->calls === 1, 'a valid input still requires a real planning connection');
$db->calls = 0;
$deny(static fn () => $service->apply($reordered, $manifest['target']['caSha256'], $plan),
    'reordered input accepts the same canonical commitment but still requires SQL');
$ok($db->calls === 1, 'a matching plan does not fabricate an operation or private receipt');
$db->calls = 0;
$maximum = $manifest; $maximum['expectedBindingRevision'] = PHP_INT_MAX;
$deny(static fn () => $service->apply($maximum, $maximum['target']['caSha256'], $expectedPlan($maximum)),
    'the maximum binding selector is exact through hashing but still requires SQL');
$ok($db->calls === 1, 'bigint format alone cannot prove a binding or request');
$changes = [
    static fn (&$v) => $v['siteRef'] = '22222222-2222-4222-8222-222222222222',
    static fn (&$v) => $v['releaseRef'] = 'release-local-2',
    static fn (&$v) => $v['expectedBindingRevision'] = 2,
    static fn (&$v) => $v['portalAcceptanceRef'] = 'another-reference-is-not-verified-consent',
    static fn (&$v) => $v['idempotencyKey'] = 'request-local-2',
    static fn (&$v) => $v['receiptRef'] = 'request_receipt-2',
    static fn (&$v) => $v['target']['host'] = 'db.example.invalid',
    static fn (&$v) => $v['target']['port'] = 5432,
    static fn (&$v) => $v['target']['database'] = 'sites_staging',
    static fn (&$v) => $v['target']['databaseOid'] = 2,
    static fn (&$v) => $v['target']['login'] = 'other_writer',
    static fn (&$v) => $v['target']['serverAddress'] = '127.0.0.2',
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('b', 64),
];
foreach ($changes as $i => $mutate) {
    $candidate = $manifest; $mutate($candidate); $db->calls = 0;
    $deny(static fn () => $service->apply($candidate, $candidate['target']['caSha256'], $plan),
        'changed valid selector or receipt name needs a new commitment '.$i);
    $ok($db->calls === 0 && $expectedPlan($candidate) !== $plan,
        'every changed request selector and target is denied before PDO '.$i);
}
$db->calls = 0;
$newReceipt = $manifest; $newReceipt['receiptRef'] = 'request_receipt-recovery';
$deny(static fn () => $service->apply($newReceipt, $newReceipt['target']['caSha256'], $expectedPlan($newReceipt)),
    'a newly reviewed receipt name still requires the real request and current writer');
$ok($db->calls === 1, 'changing a local receipt name does not fabricate idempotency recovery');

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
        throw new PDOException('PRIVATE_REQUEST_QUERY_DETAIL_DO_NOT_EXPOSE');
    }
};
foreach ([$wrongDriver, $activeTransaction, $silentErrors, $unavailableQuery] as $i => $connection) {
    $deny(static fn () => (new PrivatePublicationRequest($connection))
        ->plan($manifest, $manifest['target']['caSha256']), 'incompatible planning session '.$i);
    $deny(static fn () => (new PrivatePublicationRequest($connection))
        ->apply($manifest, $manifest['target']['caSha256'], $plan), 'incompatible apply session '.$i);
}

echo "PUBLICATION REQUEST OFFLINE PASS: $checks strict selectors, input commitments and default-denial assertions; no SQL authority, operation, private receipt or replay claimed.\n";
