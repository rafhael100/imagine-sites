<?php

declare(strict_types=1);

use Imagine\Sites\Publication\PrivatePublicationReconciliation;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit candidate only. No application boot, dispatcher or automatic retry.
const PUBLICATION_RECONCILIATION_MANIFEST = '/run/secrets/publication_reconciliation';
const PUBLICATION_RECONCILIATION_PASSWORD = '/run/secrets/sites_publication_reconciler';
const PUBLICATION_RECONCILIATION_CA = '/run/secrets/sites_publication_reconciliation_ca';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php reconcile.php plan\n";
    echo "php reconcile.php apply --plan-sha256=<lowercase-sha256> --authorize-reconcile=SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED\n";
    echo "Reads three fixed private inputs and verifies PostgreSQL18 with pinned verify-full TLS.\n";
    echo "Plan is read-only and does not read the operation or establish lease expiry.\n";
    echo "Apply invokes one existing reconciliation operation; inconclusive may include a late ACK receipt.\n";
    echo "No automatic retry, lease reset, dispatch or external publication verification.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function publication_reconciliation_read(string $path, int $maximum, int $minimum = 1): array
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
    throw new RuntimeException('PUBLICATION_RECONCILIATION_DENIED');
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
            if (!in_array($name, ['plan-sha256', 'authorize-reconcile'], true) || isset($options[$name])) {
                throw new DomainException();
            }
            $options[$name] = $value;
        }
        if (count($options) !== 2 ||
            ($options['authorize-reconcile'] ?? null) !== 'SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED' ||
            preg_match('/\A[a-f0-9]{64}\z/D', $options['plan-sha256'] ?? '') !== 1) {
            throw new DomainException();
        }
        $expectedPlanSha256 = $options['plan-sha256'];
    } else {
        throw new DomainException();
    }

    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_PUBLICATION_RECONCILIATION_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_PUBLICATION_RECONCILIATION_CI') !== '1';
    if (!$ci && !$staging) {
        throw new DomainException();
    }

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [
        PUBLICATION_RECONCILIATION_MANIFEST => publication_reconciliation_read(PUBLICATION_RECONCILIATION_MANIFEST, 16384),
    ];
    $input = PrivatePublicationReconciliation::parseManifest($files[PUBLICATION_RECONCILIATION_MANIFEST]['bytes']);
    $target = $input['target'];
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['login'] !== 'pj_a_reconciler' ||
            $target['serverAddress'] !== '127.0.0.1'))) {
        throw new DomainException();
    }
    $files[PUBLICATION_RECONCILIATION_CA] = publication_reconciliation_read(PUBLICATION_RECONCILIATION_CA, 65536);
    $ca = $files[PUBLICATION_RECONCILIATION_CA]['bytes'];
    $caSha256 = $files[PUBLICATION_RECONCILIATION_CA]['sha256'];
    if (!hash_equals($target['caSha256'], $caSha256) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $files[PUBLICATION_RECONCILIATION_PASSWORD] = publication_reconciliation_read(PUBLICATION_RECONCILIATION_PASSWORD, 4096, 16);
    $password = rtrim($files[PUBLICATION_RECONCILIATION_PASSWORD]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) {
        throw new DomainException();
    }
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.PUBLICATION_RECONCILIATION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $reconciliation = new PrivatePublicationReconciliation($db);
    $plan = $reconciliation->plan($input, $caSha256);
    if ($operation === 'apply' && !hash_equals($plan['planSha256'], $expectedPlanSha256)) {
        throw new DomainException();
    }

    // Refuse observed byte or custody changes after connection/planning and
    // before opening this candidate session for its single reconciliation.
    $bounds = [
        PUBLICATION_RECONCILIATION_MANIFEST => [16384, 1],
        PUBLICATION_RECONCILIATION_CA => [65536, 1],
        PUBLICATION_RECONCILIATION_PASSWORD => [4096, 16],
    ];
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $current = publication_reconciliation_read($path, $maximum, $minimum);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) {
            throw new DomainException();
        }
    }
    unset($files, $current, $ca);
    if ($operation === 'apply') {
        $db->exec('SET default_transaction_read_only=off');
        $result = $reconciliation->apply($input, $caSha256, $expectedPlanSha256);
    } else {
        $result = $plan;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // A failure after SQL can follow a committed event. It establishes neither
    // rollback nor the presence/absence of an ACK and cannot authorize a retry.
    fwrite(STDERR, "PUBLICATION_RECONCILIATION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
