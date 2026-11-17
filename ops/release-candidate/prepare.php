<?php

declare(strict_types=1);

use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Publication\PgSiteCommercialReleaseBindingSource;
use Imagine\Sites\Publication\PgSiteReleaseRegistryGuard;
use Imagine\Sites\Publication\SiteReleasePreparation;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;
use Imagine\Sites\Publication\SiteReleasePreparationInput;

// Explicit CLI only. No Laravel boot, route, provider binding or DB mutation.
const RELEASE_PREPARATION_MANIFEST = '/run/secrets/release_preparation';
const RELEASE_PREPARATION_PASSWORD = '/run/secrets/sites_release_reader';
const RELEASE_PREPARATION_CA = '/run/secrets/sites_release_ca';
const RELEASE_PREPARATION_HTML = '/run/sites-release-input/index.html';
const RELEASE_PREPARATION_OUTPUT = '/var/lib/imagine-sites/release-candidates';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php prepare.php prepare\n";
    echo "Reads fixed private mounts; requires pinned PostgreSQL18 with verify-full.\n";
    echo "Writes a new private technical review package; never publishes or accepts orders.\n";
    exit(0);
}

/** Observe only information available to the restricted login itself. */
function release_preparation_target(PDO $db, array $target): void
{
    $identity = PgSiteReleaseRegistryGuard::snapshot($db);
    $row = $db->query(<<<'SQL'
SELECT d.oid::bigint AS database_oid,host(inet_server_addr()) AS server_address,
 inet_server_port() AS server_port,
 current_setting('default_transaction_read_only')='on' AS read_only,
 current_setting('client_encoding')='UTF8' AS utf8,
 s.ssl IS TRUE AND s.version IN ('TLSv1.2','TLSv1.3') AND s.bits>=128 AS tls
FROM pg_database d LEFT JOIN pg_stat_ssl s ON s.pid=pg_backend_pid()
WHERE d.datname=current_database()
SQL)->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $identity['database'] !== $target['database'] ||
        $identity['login'] !== $target['login'] ||
        (string)($row['database_oid'] ?? '') !== (string)$target['databaseOid'] ||
        ($row['server_address'] ?? null) !== $target['serverAddress'] ||
        (string)($row['server_port'] ?? '') !== (string)$target['port'] ||
        ($row['read_only'] ?? null) !== true || ($row['utf8'] ?? null) !== true ||
        ($row['tls'] ?? null) !== true ||
        !hash_equals($target['caSha256'], hash('sha256',
            SiteReleasePreparationFiles::read(RELEASE_PREPARATION_CA, 65536)))) {
        throw new DomainException();
    }
}

// Never expose PDO diagnostics, paths, SQL, content or credentials on failure.
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('Private preparation I/O unavailable.');
});
$exitCode = 0;
try {
    if ($argc !== 2 || $argv[1] !== 'prepare') throw new DomainException();
    $ci = getenv('GITHUB_ACTIONS') === 'true' &&
        getenv('SITES_RELEASE_PACKAGE_CI') === '1' && getenv('APP_ENV') === 'testing';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' &&
        getenv('SITES_RELEASE_PACKAGE_CI') !== '1' && getenv('APP_ENV') === 'staging';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $input = SiteReleasePreparationInput::parse(
        SiteReleasePreparationFiles::read(RELEASE_PREPARATION_MANIFEST, 16384));
    $target = $input['target'];
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['login'] !== 'rr_a_reader' ||
            $target['serverAddress'] !== '127.0.0.1'))) throw new DomainException();

    $artifact = new ReleaseArtifact(SiteReleasePreparationFiles::read(RELEASE_PREPARATION_HTML, 150000));
    $password = rtrim(SiteReleasePreparationFiles::read(RELEASE_PREPARATION_PASSWORD, 4096, 16), "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) throw new DomainException();
    $ca = SiteReleasePreparationFiles::read(RELEASE_PREPARATION_CA, 65536);
    if (!hash_equals($target['caSha256'], hash('sha256', $ca)) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    // Validated scalar coordinates cannot inject libpq options. The hostname
    // is verified against the fixed, fingerprint-pinned CA; the server's own
    // address/port, database OID and SQL identity are then observed separately.
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.RELEASE_PREPARATION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET client_encoding='UTF8'; SET statement_timeout='10s'; SET lock_timeout='3s'");
    release_preparation_target($db, $target);
    $package = (new SiteReleasePreparation(new PgSiteCommercialReleaseBindingSource($db)))->prepare(
        $input['command'], $input['manifest'], $input['editorialRevision'], $artifact);
    release_preparation_target($db, $target);
    SiteReleasePreparationFiles::write(RELEASE_PREPARATION_OUTPUT, $input['packageRef'], $package);
    echo json_encode([
        'schemaVersion' => 'sites.release-preparation.result.v1',
        'status' => 'prepared_for_internal_review',
        'artifactDigest' => $package['artifactDigest'],
        'packageDigest' => $package['packageDigest'],
        'bindingRevision' => $package['bindingRevision'],
        'editorialRevision' => $package['editorialRevision'],
        'canPublish' => false,
        'canAcceptRealOrders' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    fwrite(STDERR, "RELEASE_PREPARATION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
