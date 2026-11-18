<?php

declare(strict_types=1);

use Imagine\Sites\Publication\PrivateReleaseRegistration;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit candidate only. No application boot, route, worker or automatic retry.
const RELEASE_REGISTRATION_MANIFEST = '/run/secrets/release_registration';
const RELEASE_REGISTRATION_PASSWORD = '/run/secrets/sites_release_artifact_writer';
const RELEASE_REGISTRATION_CA = '/run/secrets/sites_release_registration_ca';
const RELEASE_REGISTRATION_HTML = '/run/sites-release-registration/index.html';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php register.php plan\n";
    echo "php register.php record --plan-sha256=<lowercase-sha256> --authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED\n";
    echo "Reads fixed private mounts and verifies PostgreSQL18 with pinned verify-full TLS.\n";
    echo "Plan is read-only. Record can register one exact immutable artifact after explicit plan confirmation.\n";
    echo "No migration, grant, binding, editorial transition or publication; no automatic retry.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function release_registration_read(string $path, int $maximum, int $minimum = 1): array
{
    clearstatcache(true, $path);
    $before = lstat($path);
    $bytes = SiteReleasePreparationFiles::read($path, $maximum, $minimum);
    clearstatcache(true, $path);
    $after = lstat($path);
    if (!is_array($before) || !is_array($after)) {
        throw new DomainException();
    }
    $identity = [];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        if ($before[$key] !== $after[$key]) {
            throw new DomainException();
        }
        $identity[$key] = $after[$key];
    }
    return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'identity' => $identity];
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) {
        return true;
    }
    throw new RuntimeException('RELEASE_REGISTRATION_DENIED');
});
$exitCode = 0;
try {
    $operation = $argv[1] ?? null;
    $expectedPlanSha256 = null;
    if ($operation === 'plan') {
        if ($argc !== 2) {
            throw new DomainException();
        }
    } elseif ($operation === 'record') {
        if ($argc !== 4) {
            throw new DomainException();
        }
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
                throw new DomainException();
            }
            [$name, $value] = explode('=', substr($argument, 2), 2);
            if (!in_array($name, ['plan-sha256', 'authorize-record'], true) || isset($options[$name])) {
                throw new DomainException();
            }
            $options[$name] = $value;
        }
        if (count($options) !== 2 || ($options['authorize-record'] ?? null) !== 'SITES_RELEASE_ARTIFACT_ONLY_APPROVED' ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['plan-sha256'] ?? '') !== 1) {
            throw new DomainException();
        }
        $expectedPlanSha256 = $options['plan-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_RELEASE_REGISTRATION_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_RELEASE_REGISTRATION_CI') !== '1';
    if (!$ci && !$staging) {
        throw new DomainException();
    }

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $bounds = [
        RELEASE_REGISTRATION_MANIFEST => [16384, 1],
        RELEASE_REGISTRATION_PASSWORD => [4096, 16],
        RELEASE_REGISTRATION_CA => [65536, 1],
        RELEASE_REGISTRATION_HTML => [150000, 1],
    ];
    $files = [];
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $files[$path] = release_registration_read($path, $maximum, $minimum);
    }
    $input = PrivateReleaseRegistration::parseManifest($files[RELEASE_REGISTRATION_MANIFEST]['bytes']);
    $target = $input['target'];
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 ||
            !in_array($target['login'], ['rr_a_artifact_writer', 'rr_registration_a_artifact_writer'], true) ||
            $target['serverAddress'] !== '127.0.0.1'))) {
        throw new DomainException();
    }
    $ca = $files[RELEASE_REGISTRATION_CA]['bytes'];
    $caSha256 = $files[RELEASE_REGISTRATION_CA]['sha256'];
    if (!hash_equals($target['caSha256'], $caSha256) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $password = rtrim($files[RELEASE_REGISTRATION_PASSWORD]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) {
        throw new DomainException();
    }
    $html = $files[RELEASE_REGISTRATION_HTML]['bytes'];
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.RELEASE_REGISTRATION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $registration = new PrivateReleaseRegistration($db);
    $plan = $registration->plan($input, $html, $caSha256);
    if ($operation === 'record' && !hash_equals($plan['planSha256'], $expectedPlanSha256)) {
        throw new DomainException();
    }

    // Connection and planning may take time. Refuse every observed byte or
    // custody change before opening this candidate session for the one write.
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $current = release_registration_read($path, $maximum, $minimum);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) {
            throw new DomainException();
        }
    }
    unset($files, $current, $ca);
    if ($operation === 'record') {
        $db->exec('SET default_transaction_read_only=off');
        $result = $registration->record($input, $html, $caSha256, $expectedPlanSha256);
    } else {
        $result = $plan;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // A failure after the SQL call can follow a committed artifact. This is a
    // bounded refusal, never a rollback guarantee or permission to retry.
    fwrite(STDERR, "RELEASE_REGISTRATION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
