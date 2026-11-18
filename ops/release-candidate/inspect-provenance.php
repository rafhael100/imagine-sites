<?php

declare(strict_types=1);

use App\Integration\StoredBriefReleaseReceiptReader;
use Imagine\Sites\Production\BriefCandidateJson;
use Imagine\Sites\Publication\ReleaseArtifactConnection;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit historical read only. No Laravel boot, registration, source read or retry.
const BRIEF_ARTIFACT_INSPECTION_MANIFEST = '/run/secrets/brief_artifact_inspection';
const BRIEF_ARTIFACT_INSPECTION_PASSWORD = '/run/secrets/sites_brief_artifact_inspection_reader';
const BRIEF_ARTIFACT_INSPECTION_CA = '/run/secrets/sites_brief_artifact_inspection_ca';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php inspect-provenance.php inspect\n";
    echo "Reads one exact historical provenance receipt through three fixed private inputs.\n";
    echo "Requires PostgreSQL18, pinned verify-full TLS and a separate restricted read-only principal.\n";
    echo "planSha256 selects the original render-plan bytes, not a registration approval commitment.\n";
    echo "Historical byte linkage does not certify renderer execution, current eligibility or official authority.\n";
    echo "No raw content, private references, writer replay, listing, output file, retry or publication.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function brief_artifact_inspection_read(string $path, int $maximum, int $minimum = 1): array
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

/** Reuse the same protected reader before SQL and again before releasing stdout. */
function brief_artifact_inspection_recheck(array $files): void
{
    $bounds = [BRIEF_ARTIFACT_INSPECTION_MANIFEST => [16384, 1],
        BRIEF_ARTIFACT_INSPECTION_CA => [65536, 1], BRIEF_ARTIFACT_INSPECTION_PASSWORD => [4096, 16]];
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $current = brief_artifact_inspection_read($path, $maximum, $minimum);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) throw new DomainException();
    }
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('BRIEF_ARTIFACT_INSPECTION_DENIED');
});
$exitCode = 0;
try {
    if ($argc !== 2 || $argv[1] !== 'inspect') throw new DomainException();
    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_BRIEF_ARTIFACT_INSPECTION_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_BRIEF_ARTIFACT_INSPECTION_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [BRIEF_ARTIFACT_INSPECTION_MANIFEST =>
        brief_artifact_inspection_read(BRIEF_ARTIFACT_INSPECTION_MANIFEST, 16384)];
    // The existing strict JSON primitives own duplicate/lexical validation;
    // no alternate parser or interpretation of the stored render plan is added.
    $input = BriefCandidateJson::decode($files[BRIEF_ARTIFACT_INSPECTION_MANIFEST]['bytes'], 16384);
    BriefCandidateJson::keys($input, ['schema', 'target', 'siteRef', 'releaseRef', 'planSha256']);
    if ($input->schema !== 'SITES_BRIEF_ARTIFACT_INSPECTION_V1' || !$input->target instanceof stdClass) {
        throw new DomainException();
    }
    $target = ReleaseArtifactConnection::target(get_object_vars($input->target));
    BriefCandidateJson::uuid($input->siteRef);
    BriefCandidateJson::reference($input->releaseRef);
    BriefCandidateJson::digest($input->planSha256);
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || !in_array($target['login'], ['bai_a_reader', 'bai_b_reader'], true) ||
            $target['serverAddress'] !== '127.0.0.1'))) throw new DomainException();

    $files[BRIEF_ARTIFACT_INSPECTION_CA] = brief_artifact_inspection_read(BRIEF_ARTIFACT_INSPECTION_CA, 65536);
    $ca = $files[BRIEF_ARTIFACT_INSPECTION_CA]['bytes'];
    $caSha256 = $files[BRIEF_ARTIFACT_INSPECTION_CA]['sha256'];
    ReleaseArtifactConnection::observedCa($target, $caSha256);
    if (!str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $files[BRIEF_ARTIFACT_INSPECTION_PASSWORD] =
        brief_artifact_inspection_read(BRIEF_ARTIFACT_INSPECTION_PASSWORD, 4096, 16);
    $password = rtrim($files[BRIEF_ARTIFACT_INSPECTION_PASSWORD]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) throw new DomainException();
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.BRIEF_ARTIFACT_INSPECTION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET row_security=on; SET statement_timeout='10s'; SET lock_timeout='3s'");
    brief_artifact_inspection_recheck($files);
    $receipt = (new StoredBriefReleaseReceiptReader($db))->readExact(
        $target, $input->siteRef, $input->releaseRef, $input->planSha256, $caSha256,
    );
    if (array_keys($receipt) !== ['schemaVersion', 'status', 'siteRef', 'releaseRef', 'operationId',
        'idempotencyKey', 'snapshotRef', 'briefRevision', 'manifestSha256', 'planSha256', 'editorialSha256',
        'artifactDigest', 'sizeBytes', 'recordedAt', 'authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] ||
        $receipt['schemaVersion'] !== 'sites.brief-artifact-registration.inspection.candidate.v0.1' ||
        $receipt['status'] !== 'inspected' || $receipt['siteRef'] !== $input->siteRef ||
        $receipt['releaseRef'] !== $input->releaseRef || $receipt['planSha256'] !== $input->planSha256) {
        throw new DomainException();
    }
    foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) {
        if ($receipt[$flag] !== false) throw new DomainException();
    }
    $result = [
        'schema' => 'SITES_BRIEF_ARTIFACT_INSPECTION_RESULT_V1', 'status' => 'inspected',
        'planSha256' => $receipt['planSha256'], 'artifactDigest' => $receipt['artifactDigest'],
        'sizeBytes' => $receipt['sizeBytes'], 'recordedAt' => $receipt['recordedAt'],
        'authorityVerified' => false, 'clientAccepted' => false,
        'canPublish' => false, 'canAcceptRealOrders' => false,
    ];
    unset($receipt);
    brief_artifact_inspection_recheck($files);
    unset($files, $ca);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // No SQL diagnostic, private selector, credential, pathname or error chain.
    fwrite(STDERR, "BRIEF_ARTIFACT_INSPECTION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
