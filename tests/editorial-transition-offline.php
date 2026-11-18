<?php

declare(strict_types=1);

// Format, exact-byte commitment, connection isolation and default denials.
// No successful SQL identity, private duty, head or transition is fabricated.
// The separate PG18/TLS fixture proves actual CAS and independent actors.
require __DIR__.'/../apps/sites/vendor/autoload.php';

use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use App\Editorial\PrivateEditorialTransition;
use App\Editorial\EditorialRevisionWriter;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline editorial transition failure: '.$label);
    ++$checks;
};
$deny = static function (callable $operation, string $label) use ($ok): void {
    $error = null;
    ob_start();
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error !== null && $error::class === RuntimeException::class &&
        $error->getMessage() === 'EDITORIAL_TRANSITION_DENIED' && $error->getPrevious() === null &&
        $error->getCode() === 0 && $output === '', $label.' has only the bounded denial');
};
$encode = static fn (array $value): string => json_encode($value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$renderer = new StaticSiteRenderer();
$brief = new SiteBrief('onepage', 'Empresa fictícia 😀', 'Artefato privado de laboratório',
    'Texto https://example.invalid onboarding=aberto onclick="nada" & <marcação>.',
    'Conheça a demonstração', ['Ação e texto inerte', 'Sem captação']);
$html = $renderer->render($brief)->html;
$manifest = [
    'schema' => 'SITES_EDITORIAL_TRANSITION_V1',
    'target' => [
        'host' => 'localhost', 'port' => 55449, 'database' => 'postgres', 'databaseOid' => 1,
        'login' => 'rr_a_editor', 'serverAddress' => '127.0.0.1', 'caSha256' => str_repeat('a', 64),
    ],
    'siteRef' => '11111111-1111-4111-8111-111111111111',
    'expectedRevision' => 0,
    'action' => 'register',
    'artifactDigest' => hash('sha256', $html),
    'sizeBytes' => strlen($html),
];
$json = $encode($manifest);
$ok(InertPreviewAudit::isSafe($html), 'fixture is an inert renderer artifact with escaped literal copy');
$ok(PrivateEditorialTransition::validateManifest($manifest) === $manifest &&
    PrivateEditorialTransition::parseManifest($json) === $manifest,
    'a strict private selector is accepted as format only');
$reordered = array_reverse($manifest, true);
$reordered['target'] = array_reverse($manifest['target'], true);
$ok(PrivateEditorialTransition::parseManifest($encode($reordered)) === $manifest,
    'root and target key order are canonical without adding authority');
$escapedKeys = str_replace(['"schema":', '"host":'], ['"\\u0073chema":', '"\\u0068ost":'], $json);
$ok(PrivateEditorialTransition::parseManifest($escapedKeys) === $manifest,
    'one unambiguous escaped key retains JSON semantics');
$ok(PrivateEditorialTransition::parseManifest(str_pad($json, 16384, " \t\r\n")) === $manifest,
    'the 16384-byte transport bound includes whitespace');

foreach ([
    ['host' => '127.0.0.1', 'port' => 1, 'databaseOid' => 1],
    ['host' => '::1', 'serverAddress' => '::1', 'port' => 65535, 'databaseOid' => 4294967295],
    ['host' => 'db-1.example.invalid', 'database' => '_'.str_repeat('d', 62), 'login' => '_'.str_repeat('r', 62)],
    ['host' => 'DB.EXAMPLE.INVALID'],
] as $i => $replacement) {
    $candidate = $manifest;
    $candidate['target'] = array_replace($candidate['target'], $replacement);
    $ok(PrivateEditorialTransition::parseManifest($encode($candidate)) === $candidate,
        'allowed coordinates preserve exact values '.$i);
}
foreach ([['expectedRevision' => 0, 'sizeBytes' => 1],
    ['expectedRevision' => 2147483646, 'sizeBytes' => 150000]] as $i => $replacement) {
    $candidate = array_replace($manifest, $replacement);
    $ok(PrivateEditorialTransition::parseManifest($encode($candidate)) === $candidate,
        'selector bounds are format only and do not verify artifact bytes '.$i);
}

foreach (['register', 'revise', 'submit', 'rework', 'approve_internal', 'reopen'] as $action) {
    $candidate = array_replace($manifest, ['action' => $action]);
    $ok(PrivateEditorialTransition::parseManifest($encode($candidate)) === $candidate,
        'known action is a selector, not proof of private duty or head: '.$action);
}

$mutations = [
    static function (&$v): void { unset($v['schema']); },
    static function (&$v): void { unset($v['target']); },
    static function (&$v): void { unset($v['siteRef']); },
    static function (&$v): void { unset($v['expectedRevision']); },
    static function (&$v): void { unset($v['action']); },
    static function (&$v): void { unset($v['artifactDigest']); },
    static function (&$v): void { unset($v['sizeBytes']); },
    static fn (&$v) => $v['schema'] = 'SITES_EDITORIAL_TRANSITION_V2',
    static fn (&$v) => $v['schema'] = 1,
    static fn (&$v) => $v['tenantRef'] = 'must-not-select-a-tenant',
    static fn (&$v) => $v['orderRef'] = 'must-not-authorize-an-order',
    static fn (&$v) => $v['contractRef'] = 'must-not-authorize-a-contract',
    static fn (&$v) => $v['productCode'] = 'onepage',
    static fn (&$v) => $v['actorRole'] = 'reviewer',
    static fn (&$v) => $v['releaseRef'] = 'not-an-editorial-selector',
    static fn (&$v) => $v['clientAccepted'] = true,
    static fn (&$v) => $v['canPublish'] = true,
    static fn (&$v) => $v['canAcceptRealOrders'] = true,
    static fn (&$v) => $v['htmlPath'] = '/tmp/not-an-input',
    static fn (&$v) => $v['siteRef'] = strtoupper('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
    static fn (&$v) => $v['siteRef'] = '11111111111141118111111111111111',
    static fn (&$v) => $v['siteRef'] .= "\n",
    static fn (&$v) => $v['siteRef'] .= "\0",
    static fn (&$v) => $v['siteRef'] = ['11111111-1111-4111-8111-111111111111'],
    static fn (&$v) => $v['expectedRevision'] = -1,
    static fn (&$v) => $v['expectedRevision'] = 2147483647,
    static fn (&$v) => $v['expectedRevision'] = '0',
    static fn (&$v) => $v['expectedRevision'] = 0.0,
    static fn (&$v) => $v['expectedRevision'] = true,
    static fn (&$v) => $v['action'] = '',
    static fn (&$v) => $v['action'] = 'accept_client',
    static fn (&$v) => $v['action'] = 'publish',
    static fn (&$v) => $v['action'] = 'APPROVE_INTERNAL',
    static fn (&$v) => $v['action'] = "approve_internal\n",
    static fn (&$v) => $v['action'] = ['approve_internal'],
    static fn (&$v) => $v['action'] = null,
    static fn (&$v) => $v['action'] = true,
    static fn (&$v) => $v['artifactDigest'] = str_repeat('A', 64),
    static fn (&$v) => $v['artifactDigest'] = str_repeat('a', 63),
    static fn (&$v) => $v['artifactDigest'] = str_repeat('g', 64),
    static fn (&$v) => $v['artifactDigest'] = null,
    static fn (&$v) => $v['sizeBytes'] = 0,
    static fn (&$v) => $v['sizeBytes'] = 150001,
    static fn (&$v) => $v['sizeBytes'] = '1',
    static fn (&$v) => $v['sizeBytes'] = 1.0,
    static fn (&$v) => $v['sizeBytes'] = true,
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
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('g', 64),
    static fn (&$v) => $v['target']['caSha256'] = null,
];
foreach ($mutations as $i => $mutate) {
    $candidate = $manifest;
    $mutate($candidate);
    $deny(static fn () => PrivateEditorialTransition::validateManifest($candidate), 'invalid array selector '.$i);
    $deny(static fn () => PrivateEditorialTransition::parseManifest($encode($candidate)), 'invalid JSON selector '.$i);
}

foreach ([
    '', 'null', 'false', '1', '"selector"', '[]', '[{}]', '{}', '{',
    $json.' trailing', str_pad($json, 16385, ' '), $json."\0",
    str_replace('localhost', "invalid\xC3\x28", $json),
    str_replace('localhost', '\\uD800', $json),
    str_repeat('[', 16).str_repeat(']', 16),
    // A valid last occurrence must not rescue an ambiguous object.
    str_replace('"schema":', '"schema":"WRONG","schema":', $json),
    str_replace('"schema":', '"schema":"WRONG","\\u0073chema":', $json),
    str_replace('"host":', '"host":"bad;options=1","host":', $json),
    str_replace('"host":', '"\\u0068ost":"bad;options=1","host":', $json),
    str_replace('"databaseOid":1', '"databaseOid":2,"databaseOid":1', $json),
    str_replace('"siteRef":', '"siteRef":"bad","siteRef":', $json),
    str_replace('"expectedRevision":0', '"expectedRevision":1,"expectedRevision":0', $json),
    str_replace('"expectedRevision":0', '"expectedRevision":1,"\\u0065xpectedRevision":0', $json),
    str_replace('"action":', '"action":"publish","action":', $json),
    str_replace('"artifactDigest":', '"artifactDigest":"bad","artifactDigest":', $json),
    str_replace('"sizeBytes":', '"sizeBytes":0,"sizeBytes":', $json),
] as $i => $raw) {
    $deny(static fn () => PrivateEditorialTransition::parseManifest($raw), 'ambiguous or malformed transport '.$i);
}

// Negative-only PDO: it cannot produce a query result or a successful write.
final class UnavailableEditorialTransitionPDO extends PDO
{
    public int $calls = 0;
    public function __construct(private Throwable $failure) {}
    public function getAttribute(int $attribute): mixed
    {
        ++$this->calls;
        throw $this->failure;
    }
}
$privateError = new PDOException('PRIVATE_EDITORIAL_SQL_DETAIL_DO_NOT_EXPOSE');
$privateError->errorInfo = ['42501', 0, 'PRIVATE_EDITORIAL_BOUND_VALUE'];
$db = new UnavailableEditorialTransitionPDO($privateError);
$transition = new PrivateEditorialTransition($db);
$expectedPlan = static fn (array $input, string $bytes): string => hash('sha256',
    "SITES_EDITORIAL_TRANSITION_PLAN_V1\0".
    json_encode(PrivateEditorialTransition::validateManifest($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).
    "\0".$bytes);
$planSha256 = $expectedPlan($manifest, $html);
$invalid = $manifest;
$invalid['siteRef'] = 'invalid';
$deny(static fn () => $transition->plan($invalid, $html, $manifest['target']['caSha256']),
    'invalid plan manifest is refused before PDO');
$deny(static fn () => $transition->apply($invalid, $html, $manifest['target']['caSha256'], $planSha256),
    'invalid apply manifest is refused before PDO');
foreach (['', str_repeat('b', 64), str_repeat('a', 63), str_repeat('A', 64), "private\0CA"] as $i => $ca) {
    $deny(static fn () => $transition->plan($manifest, $html, $ca), 'invalid or mismatched observed CA '.$i);
}
foreach (['', str_repeat('b', 64), str_repeat('A', 64), str_repeat('a', 63), $planSha256."\n"] as $i => $sha) {
    $deny(static fn () => $transition->apply($manifest, $html, $manifest['target']['caSha256'], $sha),
        'invalid or mismatched expected plan '.$i);
}
foreach ([['artifactDigest' => str_repeat('0', 64)], ['sizeBytes' => strlen($html) - 1],
    ['sizeBytes' => strlen($html) + 1], ['sizeBytes' => mb_strlen($html, 'UTF-8')]] as $i => $replacement) {
    $candidate = array_replace($manifest, $replacement);
    $deny(static fn () => $transition->plan($candidate, $html, $candidate['target']['caSha256']),
        'content hash and byte length must match exactly '.$i);
}
$ok($db->calls === 0, 'invalid inputs and unmatched plans never reach PDO');

$unsafeHtml = [
    'empty' => '',
    'not HTML' => 'Fictional private bytes',
    'NUL' => str_replace('</body>', "\0</body>", $html),
    'invalid UTF8' => str_replace('</body>', "\xC3\x28</body>", $html),
    'active script' => str_replace('</body>', '<script>alert(1)</script></body>', $html),
    'external link' => str_replace('href="#contato"', 'href="https://example.invalid/active"', $html),
    'event attribute' => str_replace('<main id="principal">', '<main id="principal" onclick="x">', $html),
    'remote CSS' => str_replace('</style>', 'body{background:url(https://example.invalid/a)}</style>', $html),
    'private preview banner' => str_replace('<main id="principal">',
        '<aside role="note">Prévia privada sites_preview</aside><main id="principal">', $html),
    'oversize' => str_replace('</html>', str_repeat(' ', 150001 - strlen($html)).'</html>', $html),
];
foreach ($unsafeHtml as $label => $bytes) {
    $candidate = array_replace($manifest, ['artifactDigest' => hash('sha256', $bytes), 'sizeBytes' => strlen($bytes)]);
    $deny(static fn () => $transition->plan($candidate, $bytes, $candidate['target']['caSha256']),
        'self-consistent digest does not authorize '.$label);
    $deny(static fn () => $transition->apply($candidate, $bytes, $candidate['target']['caSha256'], $planSha256),
        'apply also rejects '.$label);
}
$ok($db->calls === 0, 'malformed or active artifacts are refused before the database');

// Even a valid commitment cannot establish the private editor/reviewer duty,
// current head, existence of registry bytes, customer consent or publication.
$deny(static fn () => $transition->plan($manifest, $html, $manifest['target']['caSha256']),
    'valid artifact still needs a real SQL plan connection');
$ok($db->calls === 1, 'plan never fabricates SQL identity or authorization');
$db->calls = 0;
$deny(static fn () => $transition->apply($reordered, $html, $manifest['target']['caSha256'], $planSha256),
    'canonical reordered input accepts the same commitment but still needs SQL');
$ok($db->calls === 1, 'a matching plan cannot bypass a real connection');

// Every valid selector is part of the commitment, independent of key order.
$selectorChanges = [
    static fn (&$v) => $v['siteRef'] = '22222222-2222-4222-8222-222222222222',
    static fn (&$v) => $v['expectedRevision'] = 1,
    static fn (&$v) => $v['action'] = 'submit',
    static fn (&$v) => $v['target']['host'] = 'db.example.invalid',
    static fn (&$v) => $v['target']['port'] = 5432,
    static fn (&$v) => $v['target']['database'] = 'sites_staging',
    static fn (&$v) => $v['target']['databaseOid'] = 2,
    static fn (&$v) => $v['target']['login'] = 'other_writer',
    static fn (&$v) => $v['target']['serverAddress'] = '127.0.0.2',
    static fn (&$v) => $v['target']['caSha256'] = str_repeat('b', 64),
];
foreach ($selectorChanges as $i => $mutate) {
    $candidate = $manifest;
    $mutate($candidate);
    $db->calls = 0;
    $deny(static fn () => $transition->apply($candidate, $html, $candidate['target']['caSha256'], $planSha256),
        'changed valid selector needs a different plan '.$i);
    $ok($db->calls === 0 && $expectedPlan($candidate, $html) !== $planSha256,
        'changed selector is denied before PDO '.$i);
}
$exactVariants = [
    'extra byte' => str_replace('</html>', ' </html>', $html),
    'NFD and CRLF text' => str_replace('Ação e texto inerte', "Ac\u{0327}a\u{0303}o\r\ne texto inerte", $html),
    'second product' => $renderer->render(new SiteBrief('landing_page', $brief->brand, $brief->headline,
        $brief->description, $brief->callToAction, $brief->benefits))->html,
    'maximum bytes' => str_replace('</html>', str_repeat(' ', 150000 - strlen($html)).'</html>', $html),
];
foreach ($exactVariants as $label => $bytes) {
    $candidate = array_replace($manifest, ['artifactDigest' => hash('sha256', $bytes), 'sizeBytes' => strlen($bytes)]);
    $db->calls = 0;
    $deny(static fn () => $transition->apply($candidate, $bytes, $candidate['target']['caSha256'], $planSha256),
        'old plan cannot apply changed exact bytes: '.$label);
    $ok($db->calls === 0, 'old commitment fails before PDO: '.$label);
    $newPlan = $expectedPlan($candidate, $bytes);
    $deny(static fn () => $transition->apply($candidate, $bytes, $candidate['target']['caSha256'], $newPlan),
        'a new exact-byte commitment still requires real SQL: '.$label);
    $ok($db->calls === 1 && $newPlan !== $planSha256,
        'accepted bytes are committed without normalization or SQL authority: '.$label);
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
        throw new PDOException('PRIVATE_EDITORIAL_QUERY_DETAIL_DO_NOT_EXPOSE');
    }
};
foreach ([$wrongDriver, $activeTransaction, $silentErrors, $unavailableQuery] as $i => $connection) {
    $deny(static fn () => (new PrivateEditorialTransition($connection))
        ->plan($manifest, $html, $manifest['target']['caSha256']), 'ineligible or unavailable planning session '.$i);
    $deny(static fn () => (new PrivateEditorialTransition($connection))
        ->apply($manifest, $html, $manifest['target']['caSha256'], $planSha256),
        'ineligible or unavailable editorial session '.$i);
}

// Exercise the actual local adapter against a PDO that always fails. Reflection
// exposes only construction for this negative test; no SQL row is synthesized.
final class LostEditorialTransitionPDO extends PDO
{
    public int $prepares = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->prepares;
        throw new PDOException('server has gone away: PRIVATE_EDITORIAL_BOUND_VALUES');
    }
}
$lostDb = new LostEditorialTransitionPDO();
$isolated = new PrivateEditorialTransition($lostDb);
$adapter = (new ReflectionMethod(PrivateEditorialTransition::class, 'connection'))->invoke($isolated, 'postgres');
$ok($adapter instanceof Illuminate\Database\PostgresConnection &&
    $adapter->getRawPdo() === $lostDb && $adapter->getRawReadPdo() === null &&
    $adapter->getReadPdo() === $lostDb && $adapter->getPdo() === $lostDb,
    'the local adapter cannot choose a separate read connection');
$ok($adapter->getEventDispatcher() === null && !$adapter->logging() &&
    $adapter->getQueryLog() === [] && $adapter->transactionLevel() === 0 && !$adapter->pretending(),
    'local adapter has no events, query log, transaction or pretend execution');
$deny(static fn () => $adapter->reconnect(), 'reconnector refuses before opening another connection');
$ok($lostDb->prepares === 0, 'explicit reconnect never issues SQL');
$deny(static fn () => (new EditorialRevisionWriter($adapter))->transition(
    $manifest['siteRef'], $manifest['expectedRevision'], $manifest['action'], $manifest['artifactDigest']),
    'lost connection stays bounded without retrying the mutating SELECT');
$ok($lostDb->prepares === 1 && $adapter->getRawPdo() === $lostDb && $adapter->getQueryLog() === [],
    'the existing writer attempts exactly one preparation and cannot reconnect or replay');

echo "EDITORIAL TRANSITION OFFLINE PASS: $checks input, exact-byte commitment, isolated connection and default-denial assertions; no SQL authority claimed.\n";

