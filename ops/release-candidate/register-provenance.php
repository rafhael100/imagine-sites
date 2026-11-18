<?php

declare(strict_types=1);

use App\Integration\StoredBriefRendering;
use App\Integration\StoredBriefReleaseRegistration;
use App\Integration\StoredBriefSnapshotReader;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Production\BriefCandidateJson;
use Imagine\Sites\Production\BriefRenderPlanInput;
use Imagine\Sites\Publication\ReleaseArtifactConnection;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit candidate only. No Laravel boot, external artifact, output file or retry.
const BRIEF_ARTIFACT_REGISTRATION_MANIFEST = '/run/secrets/brief_artifact_registration';
const BRIEF_ARTIFACT_REGISTRATION_PLAN = '/run/secrets/brief_artifact_registration_plan';
const BRIEF_ARTIFACT_REGISTRATION_EDITORIAL = '/run/secrets/brief_artifact_registration_editorial';
const BRIEF_ARTIFACT_REGISTRATION_READER = '/run/secrets/sites_brief_artifact_registration_reader';
const BRIEF_ARTIFACT_REGISTRATION_WRITER = '/run/secrets/sites_brief_artifact_registration_writer';
const BRIEF_ARTIFACT_REGISTRATION_CA = '/run/secrets/sites_brief_artifact_registration_ca';
const BRIEF_ARTIFACT_REGISTRATION_BOUNDS = [
    BRIEF_ARTIFACT_REGISTRATION_MANIFEST => [16384, 1],
    BRIEF_ARTIFACT_REGISTRATION_PLAN => [32768, 1],
    BRIEF_ARTIFACT_REGISTRATION_EDITORIAL => [32768, 1],
    BRIEF_ARTIFACT_REGISTRATION_READER => [4096, 16],
    BRIEF_ARTIFACT_REGISTRATION_WRITER => [4096, 16],
    BRIEF_ARTIFACT_REGISTRATION_CA => [65536, 1],
];

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php register-provenance.php plan\n";
    echo "php register-provenance.php record --commitment-sha256=<lowercase-sha256> --authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED\n";
    echo "Reads six fixed private inputs; the stored snapshot is read and rendered internally.\n";
    echo "Reader and writer use separate exclusive logins on the same PostgreSQL18 endpoint with pinned verify-full TLS.\n";
    echo "Plan is read-only. Record requires the exact commitment and explicit confirmation before enabling one writer call.\n";
    echo "commitmentSha256 covers the writer target and exact plan/editorial/HTML bytes; planSha256 is the raw plan hash.\n";
    echo "The commitment does not reserve the reader login or epoch; its target and current access are checked separately.\n";
    echo "No supplied HTML/sidecar, private selectors on stdout, automatic retry, migration, grant, binding or publication.\n";
    echo "A failed or lost result can follow commit; use the separate exact historical inspector before deciding on another action.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function brief_artifact_registration_read(string $path): array
{
    [$maximum, $minimum] = BRIEF_ARTIFACT_REGISTRATION_BOUNDS[$path];
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

/** All six inputs remain individually pinned, including both credentials. */
function brief_artifact_registration_recheck(array $files): void
{
    foreach (BRIEF_ARTIFACT_REGISTRATION_BOUNDS as $path => $_) {
        $current = brief_artifact_registration_read($path);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) throw new DomainException();
    }
}

function brief_artifact_registration_password(string $bytes): string
{
    $password = rtrim($bytes, "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) throw new DomainException();
    return $password;
}

/** Every new connection starts read-only; only the selected writer may change later. */
function brief_artifact_registration_connect(array $target, string $password): PDO
{
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.BRIEF_ARTIFACT_REGISTRATION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET row_security=on; SET statement_timeout='10s'; SET lock_timeout='3s'");
    return $db;
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('BRIEF_ARTIFACT_REGISTRATION_DENIED');
});
$exitCode = 0;
try {
    $operation = $argv[1] ?? null;
    $expectedCommitmentSha256 = null;
    if ($operation === 'plan') {
        if ($argc !== 2) throw new DomainException();
    } elseif ($operation === 'record') {
        if ($argc !== 4) throw new DomainException();
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new DomainException();
            [$name, $value] = explode('=', substr($argument, 2), 2);
            if (!in_array($name, ['commitment-sha256', 'authorize-record'], true) || isset($options[$name])) {
                throw new DomainException();
            }
            $options[$name] = $value;
        }
        if (count($options) !== 2 ||
            ($options['authorize-record'] ?? null) !== 'SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED' ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['commitment-sha256'] ?? '') !== 1) {
            throw new DomainException();
        }
        $expectedCommitmentSha256 = $options['commitment-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_BRIEF_ARTIFACT_REGISTRATION_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_BRIEF_ARTIFACT_REGISTRATION_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [BRIEF_ARTIFACT_REGISTRATION_MANIFEST =>
        brief_artifact_registration_read(BRIEF_ARTIFACT_REGISTRATION_MANIFEST)];
    $input = BriefCandidateJson::decode($files[BRIEF_ARTIFACT_REGISTRATION_MANIFEST]['bytes'], 16384);
    BriefCandidateJson::keys($input, ['schema', 'target', 'snapshotReaderLogin']);
    if ($input->schema !== 'SITES_BRIEF_ARTIFACT_REGISTRATION_V1' || !$input->target instanceof stdClass) {
        throw new DomainException();
    }
    $target = ReleaseArtifactConnection::target(get_object_vars($input->target));
    $readerTarget = ReleaseArtifactConnection::target(
        array_replace($target, ['login' => $input->snapshotReaderLogin]),
    );
    if ($readerTarget['login'] === $target['login'] ||
        ($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['serverAddress'] !== '127.0.0.1' ||
            $target['login'] !== 'bpr_b_writer' ||
            !in_array($readerTarget['login'], ['bpr_b_source_reader', 'bpr_a_source_reader'], true)))) {
        throw new DomainException();
    }

    $files[BRIEF_ARTIFACT_REGISTRATION_PLAN] = brief_artifact_registration_read(BRIEF_ARTIFACT_REGISTRATION_PLAN);
    $files[BRIEF_ARTIFACT_REGISTRATION_EDITORIAL] = brief_artifact_registration_read(BRIEF_ARTIFACT_REGISTRATION_EDITORIAL);
    $planJson = $files[BRIEF_ARTIFACT_REGISTRATION_PLAN]['bytes'];
    $editorialJson = $files[BRIEF_ARTIFACT_REGISTRATION_EDITORIAL]['bytes'];
    // The shared parser/renderer validate all local bytes before any PDO exists.
    // The service alone owns the commitment algorithm; this wrapper never copies it.
    $renderPlan = BriefRenderPlanInput::fromJson($planJson);
    $expectedArtifact = $renderPlan->renderEditorial($editorialJson);

    $files[BRIEF_ARTIFACT_REGISTRATION_CA] = brief_artifact_registration_read(BRIEF_ARTIFACT_REGISTRATION_CA);
    $ca = $files[BRIEF_ARTIFACT_REGISTRATION_CA]['bytes'];
    $caSha256 = $files[BRIEF_ARTIFACT_REGISTRATION_CA]['sha256'];
    ReleaseArtifactConnection::observedCa($target, $caSha256);
    ReleaseArtifactConnection::observedCa($readerTarget, $caSha256);
    if (!str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $files[BRIEF_ARTIFACT_REGISTRATION_READER] = brief_artifact_registration_read(BRIEF_ARTIFACT_REGISTRATION_READER);
    $files[BRIEF_ARTIFACT_REGISTRATION_WRITER] = brief_artifact_registration_read(BRIEF_ARTIFACT_REGISTRATION_WRITER);
    $readerPassword = brief_artifact_registration_password($files[BRIEF_ARTIFACT_REGISTRATION_READER]['bytes']);
    $writerPassword = brief_artifact_registration_password($files[BRIEF_ARTIFACT_REGISTRATION_WRITER]['bytes']);
    brief_artifact_registration_recheck($files);

    $reader = brief_artifact_registration_connect($readerTarget, $readerPassword);
    unset($readerPassword);
    $readerIdentity = ReleaseArtifactConnection::observe($reader, $readerTarget, true,
        ReleaseArtifactConnection::BRIEF_SNAPSHOT);
    $snapshotConnection = new PostgresConnection($reader, $readerTarget['database'], '', ['driver' => 'pgsql']);
    $snapshotConnection->disableQueryLog();
    $snapshotConnection->setReconnector(static function (): never {
        throw new RuntimeException('BRIEF_ARTIFACT_REGISTRATION_DENIED');
    });
    $writer = brief_artifact_registration_connect($target, $writerPassword);
    unset($writerPassword);
    $writerIdentity = ReleaseArtifactConnection::observe($writer, $target, true,
        ReleaseArtifactConnection::BRIEF_ARTIFACT);
    $registration = new StoredBriefReleaseRegistration(
        new StoredBriefRendering(new StoredBriefSnapshotReader($snapshotConnection)), $writer,
    );
    brief_artifact_registration_recheck($files);
    $planned = $registration->plan($target, $planJson, $editorialJson, $caSha256);
    if (array_keys($planned) !== ['schemaVersion', 'status', 'commitmentSha256', 'planSha256',
        'artifactDigest', 'sizeBytes', 'authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] ||
        $planned['schemaVersion'] !== 'sites.brief-artifact-registration.plan.candidate.v0.1' ||
        $planned['status'] !== 'planned' || $planned['planSha256'] !== $renderPlan->planSha256 ||
        $planned['artifactDigest'] !== $expectedArtifact->digest || $planned['sizeBytes'] !== $expectedArtifact->sizeBytes) {
        throw new DomainException();
    }
    BriefCandidateJson::digest($planned['commitmentSha256']);
    foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) {
        if ($planned[$flag] !== false) throw new DomainException();
    }
    if (ReleaseArtifactConnection::observe($reader, $readerTarget, true,
        ReleaseArtifactConnection::BRIEF_SNAPSHOT) !== $readerIdentity ||
        ReleaseArtifactConnection::observe($writer, $target, true,
        ReleaseArtifactConnection::BRIEF_ARTIFACT) !== $writerIdentity) throw new DomainException();
    if ($operation === 'record' && !hash_equals($planned['commitmentSha256'], $expectedCommitmentSha256)) {
        throw new DomainException();
    }

    // The confirmation does not reserve reader identity/epoch. Revalidate all
    // private inputs before changing only this writer session to read-write.
    brief_artifact_registration_recheck($files);
    $result = [
        'schema' => 'SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_RESULT_V1', 'status' => 'planned',
        'commitmentSha256' => $planned['commitmentSha256'], 'planSha256' => $planned['planSha256'],
        'artifactDigest' => $planned['artifactDigest'], 'sizeBytes' => $planned['sizeBytes'],
    ];
    if ($operation === 'record') {
        $writer->exec('SET default_transaction_read_only=off');
        $receipt = $registration->record($target, $planJson, $editorialJson, $caSha256, $expectedCommitmentSha256);
        if (array_keys($receipt) !== ['schemaVersion', 'status', 'siteRef', 'releaseRef', 'operationId',
            'idempotencyKey', 'snapshotRef', 'briefRevision', 'manifestSha256', 'planSha256', 'editorialSha256',
            'artifactDigest', 'sizeBytes', 'recordedAt', 'created', 'authorityVerified', 'clientAccepted',
            'canPublish', 'canAcceptRealOrders', 'commitmentSha256'] ||
            $receipt['schemaVersion'] !== 'sites.brief-artifact-registration.receipt.candidate.v0.1' ||
            $receipt['status'] !== 'recorded' || $receipt['commitmentSha256'] !== $expectedCommitmentSha256 ||
            $receipt['planSha256'] !== $renderPlan->planSha256 ||
            $receipt['artifactDigest'] !== $expectedArtifact->digest || $receipt['sizeBytes'] !== $expectedArtifact->sizeBytes ||
            !is_string($receipt['recordedAt']) || !is_bool($receipt['created'])) throw new DomainException();
        foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) {
            if ($receipt[$flag] !== false) throw new DomainException();
        }
        $result['schema'] = 'SITES_BRIEF_ARTIFACT_REGISTRATION_RESULT_V1';
        $result['status'] = 'recorded';
        $result['recordedAt'] = $receipt['recordedAt'];
        $result['created'] = $receipt['created'];
        unset($receipt);
    }
    if (ReleaseArtifactConnection::observe($reader, $readerTarget, true,
        ReleaseArtifactConnection::BRIEF_SNAPSHOT) !== $readerIdentity ||
        ReleaseArtifactConnection::observe($writer, $target, $operation === 'plan',
        ReleaseArtifactConnection::BRIEF_ARTIFACT) !== $writerIdentity) throw new DomainException();
    brief_artifact_registration_recheck($files);
    $result += ['authorityVerified' => false, 'clientAccepted' => false,
        'canPublish' => false, 'canAcceptRealOrders' => false];
    unset($files, $ca, $input, $planJson, $editorialJson, $renderPlan, $expectedArtifact);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // A postcheck or lost response can follow commit. No rollback or retry is
    // inferred, and conflicts expose no selector, raw content or SQL detail.
    fwrite(STDERR, "BRIEF_ARTIFACT_REGISTRATION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
