<?php

declare(strict_types=1);

use App\Editorial\PrivateEditorialTransition;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit candidate only. No application boot, route, worker or automatic retry.
const EDITORIAL_TRANSITION_MANIFEST = '/run/secrets/editorial_transition';
const EDITORIAL_TRANSITION_PASSWORD = '/run/secrets/sites_editorial_operator';
const EDITORIAL_TRANSITION_CA = '/run/secrets/sites_editorial_ca';
const EDITORIAL_TRANSITION_HTML = '/run/sites-editorial-input/index.html';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php transition.php plan\n";
    echo "php transition.php apply --plan-sha256=<lowercase-sha256> --authorize-transition=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED\n";
    echo "Reads fixed private mounts and verifies PostgreSQL18 with pinned verify-full TLS.\n";
    echo "Plan is read-only. Apply requests one editorial transition with the reviewed expected revision.\n";
    echo "SQL determines the current operator duty; internal review is not customer acceptance or publication.\n";
    echo "No automatic retry or revision increment after a conflict or lost response.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function editorial_transition_read(string $path, int $maximum, int $minimum = 1): array
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
    throw new RuntimeException('EDITORIAL_TRANSITION_DENIED');
});
$exitCode = 0;
try {
    $operation = $argv[1] ?? null;
    $expectedPlanSha256 = null;
    if ($operation === 'plan') {
        if ($argc !== 2) {
            throw new DomainException();
        }
    } elseif ($operation === 'apply') {
        if ($argc !== 4) {
            throw new DomainException();
        }
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
                throw new DomainException();
            }
            [$name, $value] = explode('=', substr($argument, 2), 2);
            if (!in_array($name, ['plan-sha256', 'authorize-transition'], true) || isset($options[$name])) {
                throw new DomainException();
            }
            $options[$name] = $value;
        }
        if (count($options) !== 2 ||
            ($options['authorize-transition'] ?? null) !== 'SITES_EDITORIAL_TRANSITION_ONLY_APPROVED' ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['plan-sha256'] ?? '') !== 1) {
            throw new DomainException();
        }
        $expectedPlanSha256 = $options['plan-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_EDITORIAL_TRANSITION_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_EDITORIAL_TRANSITION_CI') !== '1';
    if (!$ci && !$staging) {
        throw new DomainException();
    }

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $bounds = [
        EDITORIAL_TRANSITION_MANIFEST => [16384, 1],
        EDITORIAL_TRANSITION_PASSWORD => [4096, 16],
        EDITORIAL_TRANSITION_CA => [65536, 1],
        EDITORIAL_TRANSITION_HTML => [150000, 1],
    ];
    $files = [];
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $files[$path] = editorial_transition_read($path, $maximum, $minimum);
    }
    $input = PrivateEditorialTransition::parseManifest($files[EDITORIAL_TRANSITION_MANIFEST]['bytes']);
    $target = $input['target'];
    // Both isolated CI actors are eligible targets. Their names never decide
    // which action is authorized: the existing SQL binding determines duty.
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || !in_array($target['login'], ['rr_a_editor', 'rr_a_reviewer'], true) ||
            $target['serverAddress'] !== '127.0.0.1'))) {
        throw new DomainException();
    }
    $ca = $files[EDITORIAL_TRANSITION_CA]['bytes'];
    $caSha256 = $files[EDITORIAL_TRANSITION_CA]['sha256'];
    if (!hash_equals($target['caSha256'], $caSha256) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $password = rtrim($files[EDITORIAL_TRANSITION_PASSWORD]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) {
        throw new DomainException();
    }
    $html = $files[EDITORIAL_TRANSITION_HTML]['bytes'];
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.EDITORIAL_TRANSITION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $editorial = new PrivateEditorialTransition($db);
    $plan = $editorial->plan($input, $html, $caSha256);
    if ($operation === 'apply' && !hash_equals($plan['planSha256'], $expectedPlanSha256)) {
        throw new DomainException();
    }

    // Refuse any observed byte or custody change after connection/planning
    // and before opening this candidate session for its single SQL action.
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $current = editorial_transition_read($path, $maximum, $minimum);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) {
            throw new DomainException();
        }
    }
    unset($files, $current, $ca);
    if ($operation === 'apply') {
        $db->exec('SET default_transaction_read_only=off');
        $result = $editorial->apply($input, $html, $caSha256, $expectedPlanSha256);
    } else {
        $result = $plan;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // A failure after SQL can follow a committed revision. It does not prove
    // rollback or authorize retrying with the same or an incremented revision.
    fwrite(STDERR, "EDITORIAL_TRANSITION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
