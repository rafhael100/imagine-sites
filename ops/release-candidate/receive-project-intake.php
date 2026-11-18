<?php

declare(strict_types=1);

use App\Integration\PortalProjectIntakeRecorder;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Integration\PrivateProjectIntakeReceipt;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\BriefCandidateJson;
use Imagine\Sites\Publication\ReleaseArtifactConnection;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Private candidate input only. No application boot, remote fetch or retry.
const PROJECT_INTAKE_RECEIVE_TARGET = '/run/secrets/sites_project_intake_receive_target';
const PROJECT_INTAKE_RECEIVE_COMMAND = '/run/secrets/sites_project_intake_receive_command';
const PROJECT_INTAKE_RECEIVE_PASSWORD = '/run/secrets/sites_project_intake_receive_password';
const PROJECT_INTAKE_RECEIVE_CA = '/run/secrets/sites_project_intake_receive_ca';
const PROJECT_INTAKE_RECEIVE_OUTPUT = '/run/sites-project-intake-receipts';
const PROJECT_INTAKE_RECEIVE_FILES = [
    PROJECT_INTAKE_RECEIVE_TARGET => [16384, 1],
    PROJECT_INTAKE_RECEIVE_COMMAND => [16384, 1],
    PROJECT_INTAKE_RECEIVE_PASSWORD => [4096, 16],
    PROJECT_INTAKE_RECEIVE_CA => [65536, 1],
];

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php receive-project-intake.php plan\n";
    echo "php receive-project-intake.php record --commitment-sha256=<lowercase-sha256> --authorize-receive=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED\n";
    echo "Reads four fixed private inputs and verifies PostgreSQL18 with pinned verify-full TLS.\n";
    echo "Plan is read-only; it does not authenticate a private tenant binding or reserve a receipt.\n";
    echo "Record reserves /run/sites-project-intake-receipts/<receiptRef>.json before one native intake call.\n";
    echo "The private receipt keeps the native fifteen fields; stdout contains only hashes and technical state.\n";
    echo "Failure can follow a SQL commit or leave an empty/partial receipt. No overwrite, cleanup or automatic retry.\n";
    echo "No Portal authority, production, client acceptance, publication or transport is enabled.\n";
    exit(0);
}

/** @return array{bytes:string,sha256:string,identity:array<string,int>} */
function project_intake_receive_read(string $path): array
{
    [$maximum, $minimum] = PROJECT_INTAKE_RECEIVE_FILES[$path];
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

function project_intake_receive_recheck(array $original): void
{
    foreach (PROJECT_INTAKE_RECEIVE_FILES as $path => $_) {
        $current = project_intake_receive_read($path);
        if ($current['identity'] !== $original[$path]['identity'] ||
            !hash_equals($original[$path]['sha256'], $current['sha256']) ||
            !hash_equals($original[$path]['bytes'], $current['bytes'])) throw new DomainException();
    }
}

function project_intake_receive_password(string $bytes): string
{
    $value = rtrim($bytes, "\r\n");
    if (strlen($value) < 16 || str_contains($value, "\0") ||
        str_contains($value, "\r") || str_contains($value, "\n")) throw new DomainException();
    return $value;
}

function project_intake_receive_connect(array $target, string $password): PDO
{
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.PROJECT_INTAKE_RECEIVE_CA.';gssencmode=disable;connect_timeout=5';
    $pdo = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET row_security=on; SET statement_timeout='10s'; SET lock_timeout='3s'");
    return $pdo;
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('PROJECT_INTAKE_RECEIVE_DENIED');
});
$exitCode = 0;
$reservation = null;
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
            if (!in_array($key, ['commitment-sha256', 'authorize-receive'], true) || isset($options[$key])) {
                throw new DomainException();
            }
            $options[$key] = $value;
        }
        if (count($options) !== 2 ||
            ($options['authorize-receive'] ?? null) !== 'SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED' ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['commitment-sha256'] ?? '') !== 1) {
            throw new DomainException();
        }
        $expectedCommitment = $options['commitment-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_PROJECT_INTAKE_RECEIVER_CLI_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_PROJECT_INTAKE_RECEIVER_CLI_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [PROJECT_INTAKE_RECEIVE_TARGET => project_intake_receive_read(PROJECT_INTAKE_RECEIVE_TARGET)];
    $config = BriefCandidateJson::decode($files[PROJECT_INTAKE_RECEIVE_TARGET]['bytes'], 16384);
    BriefCandidateJson::keys($config, ['schema', 'target', 'receiptRef']);
    if ($config->schema !== 'SITES_PROJECT_INTAKE_RECEIVER_V1' ||
        !$config->target instanceof stdClass) throw new DomainException();
    BriefCandidateJson::uuid($config->receiptRef);
    $target = ReleaseArtifactConnection::target(get_object_vars($config->target));
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['serverAddress'] !== '127.0.0.1' ||
            !in_array($target['login'], ['pir_a_receiver', 'pir_b_receiver'], true)))) {
        throw new DomainException();
    }

    $files[PROJECT_INTAKE_RECEIVE_COMMAND] = project_intake_receive_read(PROJECT_INTAKE_RECEIVE_COMMAND);
    $decoded = BriefCandidateJson::decode($files[PROJECT_INTAKE_RECEIVE_COMMAND]['bytes'], 16384);
    $command = SiteProjectCommand::fromArray(get_object_vars($decoded));
    BriefCandidateJson::integer($command->contractRevision, 1, 2147483647);
    $commandSha256 = $files[PROJECT_INTAKE_RECEIVE_COMMAND]['sha256'];
    $receiptPath = PROJECT_INTAKE_RECEIVE_OUTPUT.'/'.$config->receiptRef.'.json';
    $commitment = hash('sha256', "sites.project-intake-receiver.v1\0".
        json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\0".
        $commandSha256."\0".$receiptPath);
    if ($operation === 'record' && !hash_equals($commitment, $expectedCommitment)) {
        throw new DomainException();
    }
    PrivateProjectIntakeReceipt::checkAvailable(PROJECT_INTAKE_RECEIVE_OUTPUT, $config->receiptRef);
    $files[PROJECT_INTAKE_RECEIVE_CA] = project_intake_receive_read(PROJECT_INTAKE_RECEIVE_CA);
    $ca = $files[PROJECT_INTAKE_RECEIVE_CA]['bytes'];
    if (!str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    ReleaseArtifactConnection::observedCa($target, $files[PROJECT_INTAKE_RECEIVE_CA]['sha256']);
    $files[PROJECT_INTAKE_RECEIVE_PASSWORD] = project_intake_receive_read(PROJECT_INTAKE_RECEIVE_PASSWORD);
    $password = project_intake_receive_password($files[PROJECT_INTAKE_RECEIVE_PASSWORD]['bytes']);
    project_intake_receive_recheck($files);

    $pdo = project_intake_receive_connect($target, $password);
    unset($password);
    $identity = ReleaseArtifactConnection::observe(
        $pdo, $target, true, ReleaseArtifactConnection::PROJECT_INTAKE_RECEIVER,
    );
    project_intake_receive_recheck($files);
    $result = [
        'schema' => 'SITES_PROJECT_INTAKE_RECEIVER_PLAN_RESULT_V1',
        'status' => 'planned',
        'commitmentSha256' => $commitment,
        'commandSha256' => $commandSha256,
        'commandDigest' => $command->semanticFingerprint(),
        'authorityVerified' => false,
        'clientAccepted' => false,
        'canPublish' => false,
    ];

    if ($operation === 'record') {
        $reservation = PrivateProjectIntakeReceipt::reserve(
            PROJECT_INTAKE_RECEIVE_OUTPUT, $config->receiptRef, $command,
        );
        project_intake_receive_recheck($files);
        $reservation->assertReserved();
        $pdo->exec('SET default_transaction_read_only=off');
        if (ReleaseArtifactConnection::observe(
            $pdo, $target, false, ReleaseArtifactConnection::PROJECT_INTAKE_RECEIVER,
        ) !== $identity) throw new DomainException();
        project_intake_receive_recheck($files);
        $reservation->assertReserved();
        $connection = new PostgresConnection($pdo, $target['database'], '', ['driver' => 'pgsql']);
        $connection->disableQueryLog();
        $connection->setReconnector(static function (): never {
            throw new RuntimeException('PROJECT_INTAKE_RECEIVE_DENIED');
        });
        if ($connection->getPdo() !== $pdo || $connection->getReadPdo() !== $pdo) throw new DomainException();
        $receipt = (new PortalProjectIntakeRecorder($connection))->record($command);
        if ($connection->getPdo() !== $pdo || $connection->getReadPdo() !== $pdo ||
            $connection->transactionLevel() !== 0 || ReleaseArtifactConnection::observe(
                $pdo, $target, false, ReleaseArtifactConnection::PROJECT_INTAKE_RECEIVER,
            ) !== $identity) throw new DomainException();
        project_intake_receive_recheck($files);
        $written = $reservation->write($receipt);
        $reservation->close();
        $reservation = null;
        unset($receipt);
        $result['schema'] = 'SITES_PROJECT_INTAKE_RECEIVER_RESULT_V1';
        $result['status'] = 'recorded';
        $result['receiptSha256'] = $written['receiptSha256'];
        $result['sizeBytes'] = $written['sizeBytes'];
    } else {
        PrivateProjectIntakeReceipt::checkAvailable(PROJECT_INTAKE_RECEIVE_OUTPUT, $config->receiptRef);
    }

    if (ReleaseArtifactConnection::observe(
        $pdo, $target, $operation === 'plan', ReleaseArtifactConnection::PROJECT_INTAKE_RECEIVER,
    ) !== $identity) throw new DomainException();
    project_intake_receive_recheck($files);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // A refusal can follow a commit or file write. Never infer rollback or retry.
    fwrite(STDERR, "PROJECT_INTAKE_RECEIVE_DENIED\n");
    $exitCode = 1;
} finally {
    if ($reservation !== null) {
        try { $reservation->close(); } catch (Throwable) {}
    }
    restore_error_handler();
}
exit($exitCode);
