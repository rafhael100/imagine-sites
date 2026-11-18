<?php

declare(strict_types=1);

// Application protocol checks only. The PDO doubles below return scripted
// input/response formats; they establish no SQL identity, RLS, authority,
// persistence, revocation or successful access to a real journal.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});

use Imagine\Sites\Publication\PgPublicationJournal;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline request reader failure: '.$label);
    ++$checks;
};
$deny = static function (callable $operation, string $label, ?callable $after = null) use ($ok): void {
    $error = null;
    ob_start();
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error !== null && $error::class === RuntimeException::class &&
        $error->getMessage() === 'Publication journal request unavailable.' &&
        $error->getPrevious() === null && $error->getCode() === 0 && $output === '' &&
        ($after === null || $after()), $label);
};

final class RequestReaderProtocolStatement extends PDOStatement
{
    public function __construct(private mixed $value, private ?Closure $execute = null) {}
    public function execute(?array $params = null): bool
    {
        if ($this->execute !== null) ($this->execute)($params);
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->value;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->value; }
}

final class RequestReaderProtocolPDO extends PDO
{
    public int $calls = 0;
    public int $contextReads = 0;
    public int $identityReads = 0;
    public int $executions = 0;
    public array $prepared = [];
    public array $parameters = [];
    public array $queries = [];
    public string $driver = 'pgsql';
    public int $errorMode = PDO::ERRMODE_EXCEPTION;
    public bool $externalTransaction = false;
    public ?Throwable $attributeFailure = null;
    public ?Throwable $executeFailure = null;
    public array $contexts = [
        ['default_read_only' => 'on', 'transaction_read_only' => 'on', 'client_encoding' => 'UTF8'],
        ['default_read_only' => 'on', 'transaction_read_only' => 'on', 'client_encoding' => 'UTF8'],
    ];
    public array $identities = [
        ['database' => 'protocol_database', 'login' => 'protocol_reader', 'pid' => 101, 'safe' => true],
        ['database' => 'protocol_database', 'login' => 'protocol_reader', 'pid' => 101, 'safe' => true],
    ];

    public function __construct(private mixed $response) {}
    public function getAttribute(int $attribute): mixed
    {
        ++$this->calls;
        if ($this->attributeFailure !== null) throw $this->attributeFailure;
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => $this->driver,
            PDO::ATTR_ERRMODE => $this->errorMode,
            default => throw new LogicException('Unexpected protocol attribute'),
        };
    }
    public function inTransaction(): bool
    {
        ++$this->calls;
        return $this->externalTransaction;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        ++$this->calls;
        $this->queries[] = $query;
        if (str_starts_with($query, "SELECT pg_catalog.current_setting('default_transaction_read_only')")) {
            return new RequestReaderProtocolStatement($this->contexts[$this->contextReads++] ?? false);
        }
        if (str_starts_with($query, 'SELECT current_database() AS database,session_user AS login,pg_backend_pid() AS pid,')) {
            return new RequestReaderProtocolStatement($this->identities[$this->identityReads++] ?? false);
        }
        throw new LogicException('Unexpected protocol query');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->calls;
        $this->prepared[] = $query;
        return new RequestReaderProtocolStatement($this->response, function (?array $parameters): void {
            ++$this->executions;
            $this->parameters[] = $parameters;
            if ($this->executeFailure !== null) throw $this->executeFailure;
        });
    }
}

$encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR);
$format = [
    'operationRef' => '11111111-1111-4111-8111-111111111111',
    'tenantRef' => 'protocol-tenant', 'siteRef' => '44444444-4444-4444-8444-444444444444',
    'releaseRef' => 'protocol-release', 'artifactDigest' => str_repeat('a', 64),
    'editorialRevision' => 3, 'bindingRevision' => 1, 'orderRef' => 'protocol-order',
    'contractRef' => 'protocol-contract', 'contractRevision' => 1, 'productCode' => 'onepage',
    'commandDigest' => str_repeat('b', 64), 'portalAcceptanceRef' => 'protocol-unverified-reference',
    'idempotencyKey' => 'protocol-key', 'technicalStatus' => 'requested', 'revision' => 1,
    'attemptNumber' => 0, 'leaseExpiresAt' => null, 'providerRequestRef' => null, 'receiptDigest' => null,
    'createdAt' => '2026-11-18T00:00:00.000000Z', 'updatedAt' => '2026-11-18T00:00:00.000000Z',
    'canPublish' => false, 'publicationVerified' => false,
];
$selectors = [$format['siteRef'], $format['releaseRef'], $format['bindingRevision'],
    $format['portalAcceptanceRef'], $format['idempotencyKey']];
$sql = 'SELECT sites_publication.read_request_operation(?::uuid,?::text,?::bigint,?::text,?::text)';
$dispatched = array_replace($format, [
    'technicalStatus' => 'dispatched', 'revision' => 2, 'attemptNumber' => 1,
    'leaseExpiresAt' => '2026-11-18T00:00:30.123456Z', 'updatedAt' => '2026-11-18T00:00:00.123456Z',
]);
$acknowledged = array_replace($dispatched, [
    'technicalStatus' => 'acknowledged', 'revision' => 3,
    'providerRequestRef' => 'protocol-provider-reference', 'receiptDigest' => str_repeat('c', 64),
    'updatedAt' => '2026-11-18T00:00:10.000001Z',
]);
$inconclusive = array_replace($dispatched, [
    'technicalStatus' => 'inconclusive', 'revision' => 3, 'updatedAt' => '2026-11-18T00:00:31.000000Z',
]);
$late = array_replace($inconclusive, [
    'revision' => 4, 'providerRequestRef' => 'protocol-provider-reference', 'receiptDigest' => str_repeat('c', 64),
]);
foreach ([$format, $dispatched, $acknowledged, $inconclusive, $late] as $i => $response) {
    $db = new RequestReaderProtocolPDO($encode($response));
    $result = (new PgPublicationJournal($db))->readRequest(...$selectors);
    $ok($result === $response && count($result) === 24,
        'scripted state '.$i.' keeps the exact snapshot, including false flags and any reported receipt');
    $ok($db->prepared === [$sql] && $db->parameters === [$selectors] && $db->executions === 1 &&
        $db->contextReads === 2 && $db->identityReads === 2 &&
        str_contains($db->queries[0], "pg_catalog.current_setting('transaction_read_only')") &&
        str_contains($db->queries[0], "pg_catalog.current_setting('client_encoding')"),
        'state '.$i.' uses one exact selector query between two context observations');
}

// The binding revision is a PostgreSQL bigint, independently of the editorial
// revision's INTEGER bound. These are response formats, not eligible bindings.
foreach ([2147483648, PHP_INT_MAX] as $revision) {
    $response = array_replace($format, ['bindingRevision' => $revision]);
    $arguments = array_replace($selectors, [2 => $revision]);
    $db = new RequestReaderProtocolPDO($encode($response));
    $ok((new PgPublicationJournal($db))->readRequest(...$arguments) === $response &&
        $db->parameters === [$arguments], 'positive int64 binding bound is not narrowed to int32');
}

$db = new RequestReaderProtocolPDO(null);
$journal = new PgPublicationJournal($db);
foreach ([
    [0 => 'invalid'], [1 => '../release'], [2 => 0], [2 => -1],
    [3 => ''], [4 => "protocol-key\n"], [4 => str_repeat('k', 129)],
] as $i => $replacement) {
    $arguments = array_replace($selectors, $replacement);
    $deny(static fn () => $journal->readRequest(...$arguments), 'invalid selector '.$i.' has the uniform refusal');
}
$ok($db->calls === 0, 'all invalid selectors fail before any PDO access');

// Each response mismatch starts from an otherwise valid 24-field snapshot.
foreach ([
    ['siteRef' => '55555555-5555-4555-8555-555555555555'],
    ['releaseRef' => 'another-release'], ['bindingRevision' => 2],
    ['portalAcceptanceRef' => 'another-unverified-reference'], ['idempotencyKey' => 'another-key'],
] as $i => $replacement) {
    $db = new RequestReaderProtocolPDO($encode(array_replace($format, $replacement)));
    $deny(static fn () => (new PgPublicationJournal($db))->readRequest(...$selectors),
        'response selector '.$i.' cannot substitute the requested identity',
        static fn () => $db->executions === 1 && $db->prepared === [$sql]);
}
$missing = $format;
unset($missing['commandDigest']);
foreach ([
    null, false, 'null', $encode($missing),
    $encode($format + ['leaseToken' => 'must-not-enter-a-snapshot']),
    $encode(array_replace($format, ['canPublish' => true])),
] as $i => $raw) {
    $db = new RequestReaderProtocolPDO($raw);
    $deny(static fn () => (new PgPublicationJournal($db))->readRequest(...$selectors),
        'absent or malformed response '.$i.' has no fallback',
        static fn () => $db->executions === 1 && $db->prepared === [$sql]);
}

foreach ([0, 1] as $phase) {
    foreach ([
        ['default_read_only' => 'off'], ['transaction_read_only' => 'off'], ['client_encoding' => 'LATIN1'],
    ] as $i => $replacement) {
        $db = new RequestReaderProtocolPDO($encode($format));
        $db->contexts[$phase] = array_replace($db->contexts[$phase], $replacement);
        $deny(static fn () => (new PgPublicationJournal($db))->readRequest(...$selectors),
            'context '.$i.' is checked '.($phase === 0 ? 'before' : 'after').' the read',
            static fn () => $db->executions === $phase && count($db->prepared) === $phase);
    }
}
foreach ([
    ['database' => 'different_database'], ['login' => 'different_reader'], ['pid' => 102],
] as $i => $replacement) {
    $db = new RequestReaderProtocolPDO($encode($format));
    $db->identities[1] = array_replace($db->identities[1], $replacement);
    $deny(static fn () => (new PgPublicationJournal($db))->readRequest(...$selectors),
        'connection identity '.$i.' must remain fixed',
        static fn () => $db->executions === 1 && $db->prepared === [$sql]);
}
foreach (['driver', 'transaction', 'errors'] as $mode) {
    $db = new RequestReaderProtocolPDO($encode($format));
    if ($mode === 'driver') $db->driver = 'sqlite';
    if ($mode === 'transaction') $db->externalTransaction = true;
    if ($mode === 'errors') $db->errorMode = PDO::ERRMODE_SILENT;
    $deny(static fn () => (new PgPublicationJournal($db))->readRequest(...$selectors),
        'ineligible '.$mode.' refuses before selecting',
        static fn () => $db->prepared === [] && $db->executions === 0);
}
foreach (['42501', '40001', '08006'] as $sqlstate) {
    $db = new RequestReaderProtocolPDO($encode($format));
    $failure = new PDOException('PRIVATE_REQUEST_READER_DIAGNOSTIC');
    $failure->errorInfo = [$sqlstate, 0, 'PRIVATE_REQUEST_READER_BOUND_VALUE'];
    $db->executeFailure = $failure;
    $deny(static fn () => (new PgPublicationJournal($db))->readRequest(...$selectors),
        'SQLSTATE '.$sqlstate.' does not expose details or request a retry',
        static fn () => $db->executions === 1 && $db->prepared === [$sql]);
}
$db = new RequestReaderProtocolPDO(null);
$db->attributeFailure = new PDOException('PRIVATE_REQUEST_READER_CONNECTION_DETAIL');
$deny(static fn () => (new PgPublicationJournal($db))->readRequest(...$selectors),
    'unavailable connection has the same public refusal',
    static fn () => $db->calls === 1 && $db->prepared === []);

// The new context is opt-in: the existing UUID reader keeps its documented
// nullable result and makes no new read-only-setting query.
foreach ([$encode($format), null] as $raw) {
    $db = new RequestReaderProtocolPDO($raw);
    $db->contexts = [['default_read_only' => 'off', 'transaction_read_only' => 'off', 'client_encoding' => 'LATIN1']];
    $expected = $raw === null ? null : $format;
    $ok((new PgPublicationJournal($db))->read($format['operationRef']) === $expected &&
        $db->contextReads === 0 && $db->identityReads === 2 && $db->executions === 1 &&
        $db->prepared === ['SELECT sites_publication.read_operation(?::uuid)'],
        'legacy UUID reader keeps its prior connection and nullable response contract');
}

echo "PUBLICATION REQUEST READER OFFLINE PASS: $checks checks; PDO protocol doubles only, no SQL authority or persistence tested.\n";
