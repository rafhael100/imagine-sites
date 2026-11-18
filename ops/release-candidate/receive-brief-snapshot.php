<?php

declare(strict_types=1);

use App\Integration\PortalBriefSnapshotRecorder;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Production\BriefCandidateJson;
use Imagine\Sites\Production\BriefSnapshotInput;
use Imagine\Sites\Publication\ReleaseArtifactConnection;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Candidate input: no Portal transport, no Laravel boot, no network fetch, no retry.
const BRIEF_SNAPSHOT_RECEIVE_TARGET = '/run/secrets/sites_brief_snapshot_receive_target';
const BRIEF_SNAPSHOT_RECEIVE_MANIFEST = '/run/secrets/sites_brief_snapshot_receive_manifest';
const BRIEF_SNAPSHOT_RECEIVE_BODY = '/run/secrets/sites_brief_snapshot_receive_body';
const BRIEF_SNAPSHOT_RECEIVE_PASSWORD = '/run/secrets/sites_brief_snapshot_receive_password';
const BRIEF_SNAPSHOT_RECEIVE_CA = '/run/secrets/sites_brief_snapshot_receive_ca';
const BRIEF_SNAPSHOT_RECEIVE_FILES = [
    BRIEF_SNAPSHOT_RECEIVE_TARGET => [16384, 1],
    BRIEF_SNAPSHOT_RECEIVE_MANIFEST => [32768, 1],
    BRIEF_SNAPSHOT_RECEIVE_BODY => [150000, 1],
    BRIEF_SNAPSHOT_RECEIVE_PASSWORD => [4096, 16],
    BRIEF_SNAPSHOT_RECEIVE_CA => [65536, 1],
];

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php receive-brief-snapshot.php plan\n";
    echo "php receive-brief-snapshot.php record --commitment-sha256=<lowercase-sha256> --authorize-store=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED\n";
    echo "Five fixed private inputs. The target binds the exact native receiver login, database, TLS CA and server identity.\n";
    echo "Plan uses a read-only connection; record confirms the planned commitment and makes one authorized SQL call.\n";
    echo "No production or Portal authority, publication, remote import, retry or response-loss inference.\n";
    exit(0);
}

/** @return array{bytes:string,sha256:string,identity:array<string,int>} */
function brief_snapshot_receive_read(string $path): array
{
    [$maximum, $minimum] = BRIEF_SNAPSHOT_RECEIVE_FILES[$path];
    clearstatcache(true, $path);
    $before = lstat($path);
    $bytes = SiteReleasePreparationFiles::read($path, $maximum, $minimum);
    clearstatcache(true, $path);
    $after = lstat($path);
    if (!is_array($before) || !is_array($after)) throw new DomainException();
    $identity = [];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        if ($before[$key] !== $after[$key]) throw new DomainException();
        $identity[$key] = $after[$key];
    }
    return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'identity' => $identity];
}

function brief_snapshot_receive_recheck(array $original): void
{
    foreach (BRIEF_SNAPSHOT_RECEIVE_FILES as $path => $_) {
        $current = brief_snapshot_receive_read($path);
        if ($current['identity'] !== $original[$path]['identity'] ||
            !hash_equals($original[$path]['sha256'], $current['sha256']) ||
            !hash_equals($original[$path]['bytes'], $current['bytes'])) throw new DomainException();
    }
}

function brief_snapshot_receive_password(string $bytes): string
{
    $value = rtrim($bytes, "\r\n");
    if (strlen($value) < 16 || str_contains($value, "\0") ||
        str_contains($value, "\r") || str_contains($value, "\n")) throw new DomainException();
    return $value;
}

function brief_snapshot_receive_connect(array $target, string $password): PDO
{
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.BRIEF_SNAPSHOT_RECEIVE_CA.';gssencmode=disable;connect_timeout=5';
    $pdo = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    // Begin in read-only mode, even for an eventual writer.
    $pdo->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET row_security=on; SET statement_timeout='10s'; SET lock_timeout='3s'");
    return $pdo;
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('BRIEF_SNAPSHOT_RECEIVE_DENIED');
});
$status = 0;
try {
    $operation = $argv[1] ?? null;
    $expectedCommitment = null;
    if ($operation === 'plan') {
        if ($argc !== 2) throw new DomainException();
    } elseif ($operation === 'record') {
        if ($argc !== 4) throw new DomainException();
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new DomainException();
            [$key, $value] = explode('=', substr($argument, 2), 2);
            if (!in_array($key, ['commitment-sha256', 'authorize-store'], true) || isset($options[$key])) throw new DomainException();
            $options[$key] = $value;
        }
        if (count($options) !== 2 ||
            ($options['authorize-store'] ?? null) !== 'SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED' ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['commitment-sha256'] ?? '') !== 1) {
            throw new DomainException();
        }
        $expectedCommitment = $options['commitment-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [];
    foreach (BRIEF_SNAPSHOT_RECEIVE_FILES as $path => $_) {
        $files[$path] = brief_snapshot_receive_read($path);
    }
    $config = BriefCandidateJson::decode($files[BRIEF_SNAPSHOT_RECEIVE_TARGET]['bytes'], 16384);
    BriefCandidateJson::keys($config, ['schema', 'target']);
    if ($config->schema !== 'SITES_BRIEF_SNAPSHOT_RECEIVER_V1' ||
        !$config->target instanceof stdClass) throw new DomainException();
    $target = ReleaseArtifactConnection::target(get_object_vars($config->target));
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['serverAddress'] !== '127.0.0.1' ||
            !in_array($target['login'], ['bsr_a_receiver', 'bsr_b_receiver'], true)))) {
        throw new DomainException();
    }

    $input = BriefSnapshotInput::fromJson(
        $files[BRIEF_SNAPSHOT_RECEIVE_MANIFEST]['bytes'],
        $files[BRIEF_SNAPSHOT_RECEIVE_BODY]['bytes'],
    );
    $ca = $files[BRIEF_SNAPSHOT_RECEIVE_CA]['bytes'];
    if (!str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    ReleaseArtifactConnection::observedCa($target, $files[BRIEF_SNAPSHOT_RECEIVE_CA]['sha256']);
    $password = brief_snapshot_receive_password($files[BRIEF_SNAPSHOT_RECEIVE_PASSWORD]['bytes']);
    $commitment = hash('sha256', "sites.brief-snapshot-receiver.v1\0".
        json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\0".
        $input->manifestSha256."\0".$input->payloadSha256);
    if ($operation === 'record' && !hash_equals($commitment, $expectedCommitment)) {
        throw new DomainException();
    }
    brief_snapshot_receive_recheck($files);

    $pdo = brief_snapshot_receive_connect($target, $password);
    unset($password);
    $identity = ReleaseArtifactConnection::observe(
        $pdo, $target, true, ReleaseArtifactConnection::BRIEF_SNAPSHOT_RECEIVER,
    );
    brief_snapshot_receive_recheck($files);
    $result = [
        'schema' => 'SITES_BRIEF_SNAPSHOT_RECEIVER_PLAN_RESULT_V1',
        'status' => 'planned',
        'commitmentSha256' => $commitment,
        'manifestSha256' => $input->manifestSha256,
        'payloadSha256' => $input->payloadSha256,
        'briefRevision' => $input->briefRevision,
    ];

    if ($operation === 'record') {
        // The second confirmation protects this invocation, not a future authority.
        $pdo->exec('SET default_transaction_read_only=off');
        if (ReleaseArtifactConnection::observe(
            $pdo, $target, false, ReleaseArtifactConnection::BRIEF_SNAPSHOT_RECEIVER,
        ) !== $identity) throw new DomainException();
        brief_snapshot_receive_recheck($files);
        $connection = new PostgresConnection($pdo, $target['database'], '', ['driver' => 'pgsql']);
        $connection->disableQueryLog();
        $connection->setReconnector(static function (): never {
            throw new RuntimeException('BRIEF_SNAPSHOT_RECEIVE_DENIED');
        });
        $receipt = (new PortalBriefSnapshotRecorder($connection))->record($input);
        if (array_keys($receipt) !== ['schemaVersion', 'status', 'siteRef', 'snapshotRef',
            'briefRevision', 'sourceVersion', 'manifestSha256', 'payloadSha256', 'recordedAt',
            'authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] ||
            $receipt['schemaVersion'] !== 'sites.brief-snapshot.receipt.candidate.v0.1' ||
            $receipt['status'] !== 'recorded' || $receipt['siteRef'] !== $input->siteRef ||
            $receipt['snapshotRef'] !== $input->snapshotRef ||
            $receipt['briefRevision'] !== $input->briefRevision ||
            $receipt['sourceVersion'] !== $input->sourceVersion ||
            $receipt['manifestSha256'] !== $input->manifestSha256 ||
            $receipt['payloadSha256'] !== $input->payloadSha256 ||
            !is_string($receipt['recordedAt'])) throw new DomainException();
        foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $key) {
            if ($receipt[$key] !== false) throw new DomainException();
        }
        $result['schema'] = 'SITES_BRIEF_SNAPSHOT_RECEIVER_RESULT_V1';
        $result['status'] = 'recorded';
        $result['recordedAt'] = $receipt['recordedAt'];
        unset($receipt);
    }

    if (ReleaseArtifactConnection::observe(
        $pdo, $target, $operation === 'plan', ReleaseArtifactConnection::BRIEF_SNAPSHOT_RECEIVER,
    ) !== $identity) throw new DomainException();
    brief_snapshot_receive_recheck($files);
    $result += ['authorityVerified' => false, 'clientAccepted' => false,
        'canPublish' => false, 'canAcceptRealOrders' => false];
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // A failed postcheck can follow a successful database commit. Never retry automatically.
    fwrite(STDERR, "BRIEF_SNAPSHOT_RECEIVE_DENIED\n");
    $status = 1;
} finally {
    restore_error_handler();
}
exit($status);
