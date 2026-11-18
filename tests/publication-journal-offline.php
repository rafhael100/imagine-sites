<?php

declare(strict_types=1);

// Input/response format and default-denial tests only. No SQL identity, journal
// provenance, current authority, provider ACK or publication is fabricated.
// Positive persistence and privileges are exercised in the separate PG18 lab.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});

use Imagine\Sites\Publication\PgPublicationJournal;
use Imagine\Sites\Publication\PgPublicationJournalGuard;
use Imagine\Sites\Publication\PublicationJournalConflict;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline publication journal failure: '.$label);
    ++$checks;
};
$deny = static function (callable $operation, string $class, string $message, string $label) use ($ok): void {
    try { $operation(); } catch (Throwable $error) {
        $ok($error::class === $class && $error->getMessage() === $message && $error->getPrevious() === null,
            $label.' sanitized with the exact exception class');
        return;
    }
    $ok(false, $label.' unexpectedly accepted');
};
$encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR);

// This array illustrates a response FORMAT. It is not obtained from a server,
// is not passed off as a journal operation and grants no publishing authority.
$format = [
    'operationRef' => '11111111-1111-4111-8111-111111111111',
    'tenantRef' => 'offline-tenant', 'siteRef' => '44444444-4444-4444-8444-444444444444',
    'releaseRef' => 'offline-release', 'artifactDigest' => str_repeat('a', 64),
    'editorialRevision' => 3, 'bindingRevision' => 1, 'orderRef' => 'offline-order',
    'contractRef' => 'offline-contract', 'contractRevision' => 1, 'productCode' => 'onepage',
    'commandDigest' => str_repeat('b', 64), 'portalAcceptanceRef' => 'offline-unverified-reference',
    'idempotencyKey' => 'offline-journal-key', 'technicalStatus' => 'requested', 'revision' => 1,
    'attemptNumber' => 0, 'leaseExpiresAt' => null, 'providerRequestRef' => null, 'receiptDigest' => null,
    'createdAt' => '2026-11-18T00:00:00.000000Z', 'updatedAt' => '2026-11-18T00:00:00.000000Z',
    'canPublish' => false, 'publicationVerified' => false,
];
$ok(PgPublicationJournalGuard::operation($encode($format)) === $format,
    '24-field format preserves exact metadata and permanent false flags');
$dispatched = array_replace($format, [
    'technicalStatus' => 'dispatched', 'revision' => 2, 'attemptNumber' => 1,
    'leaseExpiresAt' => '2026-11-18T00:00:30.123456Z', 'updatedAt' => '2026-11-18T00:00:00.123456Z',
]);
$acknowledged = array_replace($dispatched, [
    'technicalStatus' => 'acknowledged', 'revision' => 3,
    'providerRequestRef' => 'offline-provider-reference', 'receiptDigest' => str_repeat('c', 64),
    'updatedAt' => '2026-11-18T00:00:10.000001Z',
]);
$expired = array_replace($dispatched, [
    'technicalStatus' => 'inconclusive', 'revision' => 3, 'updatedAt' => '2026-11-18T00:00:31.000000Z',
]);
$late = array_replace($expired, [
    'revision' => 4, 'providerRequestRef' => 'offline-provider-reference', 'receiptDigest' => str_repeat('c', 64),
]);
$directLate = array_replace($late, ['revision' => 3]);
foreach ([$dispatched, $acknowledged, $expired, $directLate, $late] as $i => $example) {
    $parsed = PgPublicationJournalGuard::operation($encode($example));
    $ok($parsed === $example && $parsed['canPublish'] === false && $parsed['publicationVerified'] === false,
        'technical response format '.$i.' never establishes verified publication');
}
$token = '22222222-2222-4222-8222-222222222222';
$attempt = ['operation' => $dispatched, 'leaseToken' => $token];
$ok(PgPublicationJournalGuard::attempt($encode($attempt)) === $attempt,
    'attempt format keeps a token separate from the public snapshot');

$mutations = [
    static function (&$v): void { unset($v['commandDigest']); },
    static fn (&$v) => $v['unknown'] = 'not-allowed',
    static fn (&$v) => $v['leaseToken'] = 'must-not-be-in-a-snapshot',
    static fn (&$v) => $v['operationRef'] = 'not-a-uuid',
    static fn (&$v) => $v['siteRef'] = 'https://example.invalid/site',
    static fn (&$v) => $v['tenantRef'] = '../another-tenant',
    static fn (&$v) => $v['releaseRef'] = str_repeat('r', 129),
    static fn (&$v) => $v['orderRef'] = '',
    static fn (&$v) => $v['contractRef'] = ['offline-contract'],
    static fn (&$v) => $v['portalAcceptanceRef'] = "reference\n",
    static fn (&$v) => $v['idempotencyKey'] = 'space not allowed',
    static fn (&$v) => $v['artifactDigest'] = strtoupper(str_repeat('a', 64)),
    static fn (&$v) => $v['commandDigest'] = str_repeat('b', 63),
    static fn (&$v) => $v['editorialRevision'] = '3',
    static fn (&$v) => $v['editorialRevision'] = 2147483648,
    static fn (&$v) => $v['bindingRevision'] = 0,
    static fn (&$v) => $v['contractRevision'] = 1.5,
    static fn (&$v) => $v['productCode'] = 'sites_onepage',
    static fn (&$v) => $v['technicalStatus'] = 'published',
    static fn (&$v) => $v['canPublish'] = true,
    static fn (&$v) => $v['canPublish'] = 'false',
    static fn (&$v) => $v['publicationVerified'] = true,
    static fn (&$v) => $v['publicationVerified'] = 0,
    static fn (&$v) => $v['revision'] = 0,
    static fn (&$v) => $v['revision'] = 2,
    static fn (&$v) => $v['attemptNumber'] = 1,
    static fn (&$v) => $v['attemptNumber'] = '0',
    static fn (&$v) => $v['leaseExpiresAt'] = '2026-11-18T00:00:30.000000Z',
    static fn (&$v) => $v['providerRequestRef'] = 'offline-provider-reference',
    static fn (&$v) => $v['receiptDigest'] = str_repeat('c', 64),
    static fn (&$v) => $v['createdAt'] = '2026-02-30T00:00:00.000000Z',
    static fn (&$v) => $v['createdAt'] = '2026-11-18T00:00:00Z',
    static fn (&$v) => $v['createdAt'] = '2026-11-18T00:00:00.000000+00:00',
    static fn (&$v) => $v['createdAt'] = '0000-11-18T00:00:00.000000Z',
    static fn (&$v) => $v['updatedAt'] = '2026-11-17T23:59:59.999999Z',
    static fn (&$v) => $v['updatedAt'] = '2026-11-18T00:00:01.000000Z',
];
foreach ($mutations as $i => $mutate) {
    $candidate = $format;
    $mutate($candidate);
    $deny(static fn () => PgPublicationJournalGuard::operation($encode($candidate)),
        RuntimeException::class, 'Publication journal response unavailable', 'invalid response format '.$i);
}
foreach ([null, false, '', 'null', '[]', '{', $encode($format).' trailing', str_repeat(' ', 8193)] as $i => $raw) {
    $deny(static fn () => PgPublicationJournalGuard::operation($raw), RuntimeException::class,
        'Publication journal response unavailable', 'non-snapshot response '.$i);
}
foreach ([
    array_replace($dispatched, ['attemptNumber' => 2]),
    array_replace($dispatched, ['leaseExpiresAt' => null]),
    array_replace($dispatched, ['leaseExpiresAt' => $format['createdAt']]),
    array_replace($dispatched, ['updatedAt' => $dispatched['leaseExpiresAt']]),
    array_replace($dispatched, ['providerRequestRef' => 'provider', 'receiptDigest' => str_repeat('c', 64)]),
    array_replace($acknowledged, ['providerRequestRef' => null, 'receiptDigest' => null]),
    array_replace($acknowledged, ['revision' => 4]),
    array_replace($acknowledged, ['updatedAt' => $dispatched['leaseExpiresAt']]),
    array_replace($expired, ['revision' => 2]),
    array_replace($expired, ['revision' => 4]),
    array_replace($expired, ['updatedAt' => $dispatched['updatedAt']]),
    array_replace($late, ['receiptDigest' => 'not-a-digest']),
] as $i => $candidate) {
    $deny(static fn () => PgPublicationJournalGuard::operation($encode($candidate)), RuntimeException::class,
        'Publication journal response unavailable', 'inconsistent technical state '.$i);
}
foreach ([
    ['operation' => $dispatched],
    ['operation' => $dispatched, 'leaseToken' => 'not-a-uuid'],
    ['operation' => $format, 'leaseToken' => $token],
    ['operation' => $acknowledged, 'leaseToken' => $token],
    ['operation' => $dispatched, 'leaseToken' => $token, 'canSend' => true],
] as $i => $candidate) {
    $deny(static fn () => PgPublicationJournalGuard::attempt($encode($candidate)), RuntimeException::class,
        'Publication journal response unavailable', 'invalid attempt response '.$i);
}

// Negative-only PDO: it cannot return a successful connection, statement or
// authority snapshot. It also proves malformed inputs fail before touching PDO.
final class UnavailablePublicationJournalPDO extends PDO
{
    public int $calls = 0;
    public function __construct(private Throwable $failure) {}
    public function getAttribute(int $attribute): mixed
    {
        ++$this->calls;
        throw $this->failure;
    }
}
$privateError = new PDOException('PRIVATE_FIXTURE_SQL_DETAIL_DO_NOT_EXPOSE');
$privateError->errorInfo = ['42501', 0, 'PRIVATE_FIXTURE_BOUND_VALUE'];
$db = new UnavailablePublicationJournalPDO($privateError);
$journal = new PgPublicationJournal($db);
$site = $format['siteRef'];
$operation = $format['operationRef'];
$digest = str_repeat('c', 64);
$invalidCalls = [
    static fn () => $journal->request('invalid', 'release', 1, 'acceptance', 'key'),
    static fn () => $journal->request($site, '../release', 1, 'acceptance', 'key'),
    static fn () => $journal->request($site, 'release', 0, 'acceptance', 'key'),
    static fn () => $journal->request($site, 'release', -1, 'acceptance', 'key'),
    static fn () => $journal->request($site, 'release', 1, '', 'key'),
    static fn () => $journal->request($site, 'release', 1, 'acceptance', str_repeat('k', 129)),
    static fn () => $journal->recordAttempt('invalid', 1, 30),
    static fn () => $journal->recordAttempt($operation, 0, 30),
    static fn () => $journal->recordAttempt($operation, 1, 0),
    static fn () => $journal->recordAttempt($operation, 1, -1),
    static fn () => $journal->recordAttempt($operation, 1, 61),
    static fn () => $journal->recordAcknowledgement('invalid', 2, $token, 'provider', $digest),
    static fn () => $journal->recordAcknowledgement($operation, 0, $token, 'provider', $digest),
    static fn () => $journal->recordAcknowledgement($operation, 2, 'invalid', 'provider', $digest),
    static fn () => $journal->recordAcknowledgement($operation, 2, $token, '', $digest),
    static fn () => $journal->recordAcknowledgement($operation, 2, $token, 'provider', strtoupper($digest)),
    static fn () => $journal->recordAcknowledgement($operation, 2, $token, 'provider', str_repeat('c', 63)),
    static fn () => $journal->reconcileExpired('invalid', 2),
    static fn () => $journal->reconcileExpired($operation, 0),
    static fn () => $journal->read('invalid'),
];
foreach ($invalidCalls as $i => $call) {
    $deny($call, InvalidArgumentException::class, 'Publication journal input denied', 'invalid input '.$i);
}
$ok($db->calls === 0, 'invalid inputs never reach a PDO connection');
foreach ([
    static fn () => $journal->request($site, 'release', 1, 'unverified-acceptance', 'key'),
    static fn () => $journal->recordAttempt($operation, 1, 30),
    static fn () => $journal->recordAcknowledgement($operation, 2, $token, 'provider', $digest),
    static fn () => $journal->reconcileExpired($operation, 2),
    static fn () => $journal->read($operation),
] as $i => $call) {
    $deny($call, RuntimeException::class, 'Publication journal operation denied', 'unavailable real journal '.$i);
}
$ok($db->calls === 5, 'every otherwise valid operation requires the real connection boundary');
$conflict = new PDOException('PRIVATE_FIXTURE_CONFLICT_DETAIL');
$conflict->errorInfo = ['40001', 0, 'PRIVATE_FIXTURE_FENCE'];
$conflicting = new PgPublicationJournal(new UnavailablePublicationJournalPDO($conflict));
$deny(static fn () => $conflicting->read($operation), PublicationJournalConflict::class,
    'Publication journal revision conflict', 'SQLSTATE translation without SQL execution');

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
foreach ([$wrongDriver, $activeTransaction, $silentErrors] as $i => $connection) {
    $deny(static fn () => PgPublicationJournalGuard::snapshot($connection), RuntimeException::class,
        'Publication journal connection unavailable', 'ineligible connection '.$i);
}

echo "PUBLICATION JOURNAL OFFLINE PASS: $checks format, input and default-denial assertions; no SQL authority claimed.\n";
