<?php

declare(strict_types=1);

use Imagine\Sites\Publication\PrivatePublicationRequestRecovery;
use Imagine\Sites\Publication\PrivatePublicationRequestReceipt;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit read-only recovery. No application boot or SQL writer/session switch.
const PUBLICATION_RECOVERY_MANIFEST = '/run/secrets/publication_request_recovery';
const PUBLICATION_RECOVERY_PASSWORD = '/run/secrets/sites_publication_request_recovery_reader';
const PUBLICATION_RECOVERY_CA = '/run/secrets/sites_publication_request_recovery_ca';
const PUBLICATION_RECOVERY_OUTPUT = '/var/lib/imagine-sites/publication-recoveries';
const PUBLICATION_RECOVERY_BOUNDS = [
    PUBLICATION_RECOVERY_MANIFEST => [16384, 1],
    PUBLICATION_RECOVERY_PASSWORD => [4096, 16],
    PUBLICATION_RECOVERY_CA => [65536, 1],
];

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php recover-request.php plan\n";
    echo "php recover-request.php recover --plan-sha256=<lowercase-sha256> --authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED\n";
    echo "Reads the original request envelope with reader LOGIN and three fixed private inputs.\n";
    echo "Uses a distinct private receipt directory; operationRef is never printed.\n";
    echo "Read-only PostgreSQL18 verify-full TLS; no request, provider, retry or publication.\n";
    echo "A missing record or lost response cannot justify reissuing a writer request.\n";
    exit(0);
}

/** Pin identity and exact bytes including the manifest and password. */
function publication_recovery_file(string $path): array
{
    [$max, $min] = PUBLICATION_RECOVERY_BOUNDS[$path];
    clearstatcache(true, $path);
    $before = lstat($path);
    $bytes = SiteReleasePreparationFiles::read($path, $max, $min);
    clearstatcache(true, $path);
    $after = lstat($path);
    if (!is_array($before) || !is_array($after)) throw new DomainException();
    $identity = [];
    foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $key) {
        if ($before[$key] !== $after[$key]) throw new DomainException();
        $identity[$key] = $after[$key];
    }
    return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'identity' => $identity];
}

function publication_recovery_recheck(array $original): void
{
    foreach (PUBLICATION_RECOVERY_BOUNDS as $path => $_) {
        $current = publication_recovery_file($path);
        if ($current['identity'] !== $original[$path]['identity'] ||
            !hash_equals($current['sha256'], $original[$path]['sha256']) ||
            !hash_equals($current['bytes'], $original[$path]['bytes'])) throw new DomainException();
    }
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('PUBLICATION_RECOVERY_DENIED');
});
$status = 0;
$reservation = null;
try {
    $operation = $argv[1] ?? null;
    $expectedPlan = null;
    if ($operation === 'plan') {
        if ($argc !== 2) throw new DomainException();
    } elseif ($operation === 'recover') {
        if ($argc !== 4) throw new DomainException();
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new DomainException();
            [$key, $value] = explode('=', substr($argument, 2), 2);
            if (!in_array($key, ['plan-sha256','authorize-recovery'], true) || isset($options[$key])) {
                throw new DomainException();
            }
            $options[$key] = $value;
        }
        if (count($options) !== 2 ||
            ($options['authorize-recovery'] ?? null) !== 'SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED' ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['plan-sha256'] ?? '') !== 1) throw new DomainException();
        $expectedPlan = $options['plan-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_PUBLICATION_REQUEST_RECOVERY_CI') === '1' &&
        getenv('SITES_PUBLICATION_REQUEST_READER_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_PUBLICATION_REQUEST_RECOVERY_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [];
    foreach (PUBLICATION_RECOVERY_BOUNDS as $path => $_) $files[$path] = publication_recovery_file($path);
    $input = PrivatePublicationRequestRecovery::parseManifest(
        $files[PUBLICATION_RECOVERY_MANIFEST]['bytes']);
    $target = $input['target'];
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 ||
            !in_array($target['login'], ['pjrr_a_reader', 'pjrrc_a_reader', 'pjrrc_b_reader'], true) ||
            $target['serverAddress'] !== '127.0.0.1'))) throw new DomainException();

    PrivatePublicationRequestReceipt::checkAvailable(PUBLICATION_RECOVERY_OUTPUT, $input['receiptRef']);
    $ca = $files[PUBLICATION_RECOVERY_CA]['bytes'];
    $caHash = $files[PUBLICATION_RECOVERY_CA]['sha256'];
    if (!hash_equals($target['caSha256'], $caHash) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $password = rtrim($files[PUBLICATION_RECOVERY_PASSWORD]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) throw new DomainException();
    publication_recovery_recheck($files);

    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.PUBLICATION_RECOVERY_CA.';gssencmode=disable;connect_timeout=5';
    $pdo = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $pdo->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET row_security=on; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $service = new PrivatePublicationRequestRecovery($pdo);
    $planned = $service->plan($input, $caHash);
    if ($operation === 'recover') {
        if (!hash_equals($planned['planSha256'], $expectedPlan)) throw new DomainException();
        $reservation = PrivatePublicationRequestReceipt::reserveRecovery(
            PUBLICATION_RECOVERY_OUTPUT, $input['receiptRef'], $expectedPlan);
    }

    // No read until all input mounts and the retained empty receipt are valid.
    publication_recovery_recheck($files);
    if ($operation === 'recover') {
        $reservation->assertReserved();
        $recovered = $service->recover($input, $caHash, $expectedPlan);
        publication_recovery_recheck($files);
        $reservation->write($recovered['receipt']);
        $reservation->close();
        $reservation = null;
        $result = $recovered['result'];
    } else {
        PrivatePublicationRequestReceipt::checkAvailable(PUBLICATION_RECOVERY_OUTPUT,$input['receiptRef']);
        $result = $planned;
    }
    // Never print the operationRef, commercial selectors or credential bytes.
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // An unknown result never proves the original write absent.
    fwrite(STDERR, "PUBLICATION_RECOVERY_DENIED\n");
    $status = 1;
} finally {
    if ($reservation !== null) {
        try { $reservation->close(); } catch (Throwable) {}
    }
    restore_error_handler();
}
exit($status);
