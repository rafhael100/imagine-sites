<?php

declare(strict_types=1);

use Imagine\Sites\Publication\PrivateReleaseBinding;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit candidate only. No application boot, route, worker or automatic retry.
const RELEASE_BINDING_MANIFEST = '/run/secrets/release_binding';
const RELEASE_BINDING_CA = '/run/secrets/sites_release_binding_ca';
const RELEASE_BINDING_WRITER_PASSWORD = '/run/secrets/sites_release_binding_writer';
const RELEASE_BINDING_REVOKER_PASSWORD = '/run/secrets/sites_release_binding_revoker';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php binding.php plan\n";
    echo "php binding.php bind --plan-sha256=<lowercase-sha256> --authorize-bind=SITES_RELEASE_BINDING_ONLY_APPROVED\n";
    echo "php binding.php revoke --plan-sha256=<lowercase-sha256> --authorize-revoke=SITES_RELEASE_REVOCATION_ONLY_APPROVED\n";
    echo "Reads a fixed private manifest, CA and only the password selected by its action.\n";
    echo "A write command must match the manifest action before opening a password or database connection.\n";
    echo "Plan is read-only; each write invokes one existing SQL operation with verified target and TLS.\n";
    echo "No automatic retry or revision increment; no external publication or unpublication.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function release_binding_read(string $path, int $maximum, int $minimum = 1): array
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
    throw new RuntimeException('RELEASE_BINDING_DENIED');
});
$exitCode = 0;
try {
    $operation = $argv[1] ?? null;
    $expectedPlanSha256 = null;
    if ($operation === 'plan') {
        if ($argc !== 2) {
            throw new DomainException();
        }
    } elseif (in_array($operation, ['bind', 'revoke'], true)) {
        if ($argc !== 4) {
            throw new DomainException();
        }
        $authorizationName = $operation === 'bind' ? 'authorize-bind' : 'authorize-revoke';
        $authorizationValue = $operation === 'bind'
            ? 'SITES_RELEASE_BINDING_ONLY_APPROVED'
            : 'SITES_RELEASE_REVOCATION_ONLY_APPROVED';
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
                throw new DomainException();
            }
            [$name, $value] = explode('=', substr($argument, 2), 2);
            if (!in_array($name, ['plan-sha256', $authorizationName], true) || isset($options[$name])) {
                throw new DomainException();
            }
            $options[$name] = $value;
        }
        if (count($options) !== 2 || ($options[$authorizationName] ?? null) !== $authorizationValue ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['plan-sha256'] ?? '') !== 1) {
            throw new DomainException();
        }
        $expectedPlanSha256 = $options['plan-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_RELEASE_BINDING_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_RELEASE_BINDING_CI') !== '1';
    if (!$ci && !$staging) {
        throw new DomainException();
    }

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [RELEASE_BINDING_MANIFEST => release_binding_read(RELEASE_BINDING_MANIFEST, 16384)];
    $input = PrivateReleaseBinding::parseManifest($files[RELEASE_BINDING_MANIFEST]['bytes']);
    // The command cannot select a different write from the reviewed manifest.
    // Check this before reading either password or opening a PDO connection.
    if ($operation !== 'plan' && $operation !== $input['action']) {
        throw new DomainException();
    }
    $passwordPath = match ($input['action']) {
        'bind' => RELEASE_BINDING_WRITER_PASSWORD,
        'revoke' => RELEASE_BINDING_REVOKER_PASSWORD,
    };
    $ciLogin = $input['action'] === 'bind' ? 'rr_a_binding_writer' : 'rr_a_revoker';
    $target = $input['target'];
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['login'] !== $ciLogin ||
            $target['serverAddress'] !== '127.0.0.1'))) {
        throw new DomainException();
    }
    $files[RELEASE_BINDING_CA] = release_binding_read(RELEASE_BINDING_CA, 65536);
    $ca = $files[RELEASE_BINDING_CA]['bytes'];
    $caSha256 = $files[RELEASE_BINDING_CA]['sha256'];
    if (!hash_equals($target['caSha256'], $caSha256) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $files[$passwordPath] = release_binding_read($passwordPath, 4096, 16);
    $password = rtrim($files[$passwordPath]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) {
        throw new DomainException();
    }
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.RELEASE_BINDING_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $binding = new PrivateReleaseBinding($db);
    $plan = $binding->plan($input, $caSha256);
    if ($operation !== 'plan' && !hash_equals($plan['planSha256'], $expectedPlanSha256)) {
        throw new DomainException();
    }

    // Recheck only the three inputs selected by the original action. Refuse
    // any observed change before opening this candidate session for one write.
    $bounds = [
        RELEASE_BINDING_MANIFEST => [16384, 1],
        RELEASE_BINDING_CA => [65536, 1],
        $passwordPath => [4096, 16],
    ];
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $current = release_binding_read($path, $maximum, $minimum);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) {
            throw new DomainException();
        }
    }
    unset($files, $current, $ca);
    if ($operation !== 'plan') {
        $db->exec('SET default_transaction_read_only=off');
        $result = $binding->apply($input, $caSha256, $expectedPlanSha256);
    } else {
        $result = $plan;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // A failure after SQL may follow a committed event. It does not establish
    // rollback, creation, replay or permission to retry with a different CAS.
    fwrite(STDERR, "RELEASE_BINDING_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
