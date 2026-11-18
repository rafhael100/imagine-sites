<?php

declare(strict_types=1);

use App\Integration\StoredBriefSnapshotReader;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Production\BriefCandidateJson;
use Imagine\Sites\Publication\ReleaseArtifactConnection;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Exact private read only. No Laravel boot, receiver replay, rendering or retry.
const BRIEF_SNAPSHOT_INSPECTION_MANIFEST = '/run/secrets/brief_snapshot_inspection';
const BRIEF_SNAPSHOT_INSPECTION_PASSWORD = '/run/secrets/sites_brief_snapshot_inspection_reader';
const BRIEF_SNAPSHOT_INSPECTION_CA = '/run/secrets/sites_brief_snapshot_inspection_ca';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php inspect-brief-snapshot.php inspect\n";
    echo "Reads one exact stored briefing snapshot through three fixed private inputs.\n";
    echo "Requires PostgreSQL18, pinned verify-full TLS and a separate restricted read-only principal.\n";
    echo "manifestSha256 selects the original snapshot JSON bytes, not the receiver approval commitment.\n";
    echo "The reader revalidates the stored candidate profile; source approval remains unauthenticated.\n";
    echo "No raw content, private references, timestamp, writer replay, listing, output file, retry or publication.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function brief_snapshot_inspection_read(string $path, int $maximum, int $minimum = 1): array
{
    clearstatcache(true, $path);
    $before = lstat($path);
    $bytes = SiteReleasePreparationFiles::read($path, $maximum, $minimum);
    clearstatcache(true, $path);
    $after = lstat($path);
    if (!is_array($before) || !is_array($after)) throw new DomainException();
    $identity = [];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
        if ($before[$field] !== $after[$field]) throw new DomainException();
        $identity[$field] = $after[$field];
    }
    return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'identity' => $identity];
}

/** Reuse the protected reader before connection/SQL and before releasing stdout. */
function brief_snapshot_inspection_recheck(array $files): void
{
    $bounds = [BRIEF_SNAPSHOT_INSPECTION_MANIFEST => [16384, 1],
        BRIEF_SNAPSHOT_INSPECTION_CA => [65536, 1], BRIEF_SNAPSHOT_INSPECTION_PASSWORD => [4096, 16]];
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $current = brief_snapshot_inspection_read($path, $maximum, $minimum);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) throw new DomainException();
    }
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('BRIEF_SNAPSHOT_INSPECTION_DENIED');
});
$exitCode = 0;
try {
    if ($argc !== 2 || $argv[1] !== 'inspect' || PHP_INT_SIZE < 8) throw new DomainException();
    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_BRIEF_SNAPSHOT_INSPECTION_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_BRIEF_SNAPSHOT_INSPECTION_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [BRIEF_SNAPSHOT_INSPECTION_MANIFEST =>
        brief_snapshot_inspection_read(BRIEF_SNAPSHOT_INSPECTION_MANIFEST, 16384)];
    $input = BriefCandidateJson::decode($files[BRIEF_SNAPSHOT_INSPECTION_MANIFEST]['bytes'], 16384);
    BriefCandidateJson::keys($input, ['schema', 'target', 'siteRef', 'snapshotRef', 'briefRevision', 'manifestSha256']);
    if ($input->schema !== 'SITES_BRIEF_SNAPSHOT_INSPECTION_V1' || !$input->target instanceof stdClass) {
        throw new DomainException();
    }
    $target = ReleaseArtifactConnection::target(get_object_vars($input->target));
    BriefCandidateJson::uuid($input->siteRef);
    BriefCandidateJson::uuid($input->snapshotRef);
    BriefCandidateJson::integer($input->briefRevision, 1, 2147483647);
    BriefCandidateJson::digest($input->manifestSha256);
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['serverAddress'] !== '127.0.0.1' ||
            !in_array($target['login'], ['bsi_a_reader', 'bsi_b_reader'], true)))) throw new DomainException();

    $files[BRIEF_SNAPSHOT_INSPECTION_CA] = brief_snapshot_inspection_read(BRIEF_SNAPSHOT_INSPECTION_CA, 65536);
    $ca = $files[BRIEF_SNAPSHOT_INSPECTION_CA]['bytes'];
    ReleaseArtifactConnection::observedCa($target, $files[BRIEF_SNAPSHOT_INSPECTION_CA]['sha256']);
    if (!str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $files[BRIEF_SNAPSHOT_INSPECTION_PASSWORD] =
        brief_snapshot_inspection_read(BRIEF_SNAPSHOT_INSPECTION_PASSWORD, 4096, 16);
    $password = rtrim($files[BRIEF_SNAPSHOT_INSPECTION_PASSWORD]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) throw new DomainException();
    brief_snapshot_inspection_recheck($files);

    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.BRIEF_SNAPSHOT_INSPECTION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET row_security=on; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $identity = ReleaseArtifactConnection::observe($db, $target, true, ReleaseArtifactConnection::BRIEF_SNAPSHOT);
    $connection = new PostgresConnection($db, $target['database'], '', ['driver' => 'pgsql']);
    $connection->disableQueryLog();
    $connection->setReconnector(static function (): never {
        throw new RuntimeException('BRIEF_SNAPSHOT_INSPECTION_DENIED');
    });
    brief_snapshot_inspection_recheck($files);
    $snapshot = (new StoredBriefSnapshotReader($connection))->readExact(
        $input->siteRef, $input->snapshotRef, $input->briefRevision, $input->manifestSha256,
    );
    if ($snapshot->siteRef !== $input->siteRef || $snapshot->snapshotRef !== $input->snapshotRef ||
        $snapshot->briefRevision !== $input->briefRevision ||
        !hash_equals($snapshot->manifestSha256, $input->manifestSha256)) throw new DomainException();
    $result = [
        'schema' => 'SITES_BRIEF_SNAPSHOT_INSPECTION_RESULT_V1', 'status' => 'inspected',
        'manifestSha256' => $snapshot->manifestSha256, 'payloadSha256' => $snapshot->payloadSha256,
        'briefRevision' => $snapshot->briefRevision, 'sourceVersion' => $snapshot->sourceVersion,
        'authorityVerified' => false, 'clientAccepted' => false,
        'canPublish' => false, 'canAcceptRealOrders' => false,
    ];
    unset($snapshot);
    if (ReleaseArtifactConnection::observe($db, $target, true, ReleaseArtifactConnection::BRIEF_SNAPSHOT) !== $identity) {
        throw new DomainException();
    }
    brief_snapshot_inspection_recheck($files);
    unset($files, $ca);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // Unknown, foreign, revoked, invalid and unavailable selections share one refusal.
    fwrite(STDERR, "BRIEF_SNAPSHOT_INSPECTION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
