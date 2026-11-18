<?php

declare(strict_types=1);

use Imagine\Sites\Publication\PrivatePublicationRequest;
use Imagine\Sites\Publication\PrivatePublicationRequestReceipt;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit request only. No application boot, attempt, ACK, provider or retry.
const PUBLICATION_REQUEST_MANIFEST = '/run/secrets/publication_request';
const PUBLICATION_REQUEST_PASSWORD = '/run/secrets/sites_publication_request_writer';
const PUBLICATION_REQUEST_CA = '/run/secrets/sites_publication_request_ca';
const PUBLICATION_REQUEST_OUTPUT = '/var/lib/imagine-sites/publication-requests';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php request.php plan\n";
    echo "php request.php apply --plan-sha256=<lowercase-sha256> --authorize-request=SITES_PUBLICATION_REQUEST_ONLY_APPROVED\n";
    echo "Reads three fixed private inputs and verifies PostgreSQL18 with pinned verify-full TLS.\n";
    echo "Plan is read-only; it does not inspect a current binding or verify customer acceptance.\n";
    echo "Apply reserves a new private receipt before one request_operation call; references stay out of stdout.\n";
    echo "A failure can follow a SQL commit or leave an empty/partial receipt. No overwrite, cleanup or automatic retry.\n";
    echo "No attempt, lease token, ACK, provider call or publication authority.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function publication_request_read(string $path, int $maximum, int $minimum = 1): array
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

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('PUBLICATION_REQUEST_DENIED');
});
$exitCode = 0;
$receipt = null;
try {
    $operation = $argv[1] ?? null;
    $expectedPlanSha256 = null;
    if ($operation === 'plan') {
        if ($argc !== 2) throw new DomainException();
    } elseif ($operation === 'apply') {
        if ($argc !== 4) throw new DomainException();
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new DomainException();
            [$name, $value] = explode('=', substr($argument, 2), 2);
            if (!in_array($name, ['plan-sha256', 'authorize-request'], true) || isset($options[$name])) {
                throw new DomainException();
            }
            $options[$name] = $value;
        }
        if (count($options) !== 2 ||
            ($options['authorize-request'] ?? null) !== 'SITES_PUBLICATION_REQUEST_ONLY_APPROVED' ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['plan-sha256'] ?? '') !== 1) throw new DomainException();
        $expectedPlanSha256 = $options['plan-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_PUBLICATION_REQUEST_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_PUBLICATION_REQUEST_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [PUBLICATION_REQUEST_MANIFEST => publication_request_read(PUBLICATION_REQUEST_MANIFEST, 16384)];
    $input = PrivatePublicationRequest::parseManifest($files[PUBLICATION_REQUEST_MANIFEST]['bytes']);
    $target = $input['target'];
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['login'] !== 'pj_request_a_writer' ||
            $target['serverAddress'] !== '127.0.0.1'))) throw new DomainException();
    PrivatePublicationRequestReceipt::checkAvailable(PUBLICATION_REQUEST_OUTPUT, $input['receiptRef']);
    $files[PUBLICATION_REQUEST_CA] = publication_request_read(PUBLICATION_REQUEST_CA, 65536);
    $ca = $files[PUBLICATION_REQUEST_CA]['bytes'];
    $caSha256 = $files[PUBLICATION_REQUEST_CA]['sha256'];
    if (!hash_equals($target['caSha256'], $caSha256) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $files[PUBLICATION_REQUEST_PASSWORD] = publication_request_read(PUBLICATION_REQUEST_PASSWORD, 4096, 16);
    $password = rtrim($files[PUBLICATION_REQUEST_PASSWORD]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) throw new DomainException();
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.PUBLICATION_REQUEST_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $request = new PrivatePublicationRequest($db);
    $plan = $request->plan($input, $caSha256);
    if ($operation === 'apply') {
        if (!hash_equals($plan['planSha256'], $expectedPlanSha256)) throw new DomainException();
        $receipt = PrivatePublicationRequestReceipt::reserve(PUBLICATION_REQUEST_OUTPUT,
            $input['receiptRef'], $expectedPlanSha256);
    }

    // Reserve before SQL, then reread every input before enabling this one
    // session for writing. A refusal here may leave the empty reservation.
    $bounds = [PUBLICATION_REQUEST_MANIFEST => [16384, 1], PUBLICATION_REQUEST_CA => [65536, 1],
        PUBLICATION_REQUEST_PASSWORD => [4096, 16]];
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $current = publication_request_read($path, $maximum, $minimum);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) throw new DomainException();
    }
    unset($files, $current, $ca);
    if ($operation === 'apply') {
        $receipt->assertReserved();
        $db->exec('SET default_transaction_read_only=off');
        $applied = $request->apply($input, $caSha256, $expectedPlanSha256);
        $receipt->write($applied['receipt']);
        $receipt->close();
        $receipt = null;
        $result = $applied['result'];
    } else {
        PrivatePublicationRequestReceipt::checkAvailable(PUBLICATION_REQUEST_OUTPUT, $input['receiptRef']);
        $result = $plan;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // SQL may have committed before the private receipt or its response failed.
    // Never infer rollback, delete a reservation or repeat the request here.
    fwrite(STDERR, "PUBLICATION_REQUEST_DENIED\n");
    $exitCode = 1;
} finally {
    if ($receipt !== null) {
        try { $receipt->close(); } catch (Throwable) {}
    }
    restore_error_handler();
}
exit($exitCode);
