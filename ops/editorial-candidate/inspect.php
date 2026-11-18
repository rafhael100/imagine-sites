<?php

declare(strict_types=1);

use App\Editorial\PrivateEditorialInspection;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit read only. No application boot, transition, listing or retry.
const EDITORIAL_INSPECTION_MANIFEST = '/run/secrets/editorial_inspection';
const EDITORIAL_INSPECTION_PASSWORD = '/run/secrets/sites_editorial_inspection_reader';
const EDITORIAL_INSPECTION_CA = '/run/secrets/sites_editorial_inspection_ca';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php inspect.php inspect\n";
    echo "Reads the current editorial head of one known project through three fixed private inputs.\n";
    echo "Requires PostgreSQL18, pinned verify-full TLS and a separate restricted read-only principal.\n";
    echo "An authorized project without editorial revisions returns null revision, state and artifactDigest.\n";
    echo "The current head does not identify which call produced a revision or authorize a retry.\n";
    echo "No plan, write flag, transition, listing, output file, customer acceptance or publication.\n";
    exit(0);
}

/** Preserve observed custody across separate reads, excluding access time. */
function editorial_inspection_read(string $path, int $maximum, int $minimum = 1): array
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
    throw new RuntimeException('EDITORIAL_INSPECTION_DENIED');
});
$exitCode = 0;
try {
    if ($argc !== 2 || $argv[1] !== 'inspect') throw new DomainException();
    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_EDITORIAL_INSPECTION_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_EDITORIAL_INSPECTION_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $files = [EDITORIAL_INSPECTION_MANIFEST => editorial_inspection_read(EDITORIAL_INSPECTION_MANIFEST, 16384)];
    $input = PrivateEditorialInspection::parseManifest($files[EDITORIAL_INSPECTION_MANIFEST]['bytes']);
    $target = $input['target'];
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['login'] !== 'ei_a_reader' ||
            $target['serverAddress'] !== '127.0.0.1'))) throw new DomainException();
    $files[EDITORIAL_INSPECTION_CA] = editorial_inspection_read(EDITORIAL_INSPECTION_CA, 65536);
    $ca = $files[EDITORIAL_INSPECTION_CA]['bytes'];
    $caSha256 = $files[EDITORIAL_INSPECTION_CA]['sha256'];
    if (!hash_equals($target['caSha256'], $caSha256) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $files[EDITORIAL_INSPECTION_PASSWORD] = editorial_inspection_read(EDITORIAL_INSPECTION_PASSWORD, 4096, 16);
    $password = rtrim($files[EDITORIAL_INSPECTION_PASSWORD]['bytes'], "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) throw new DomainException();
    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.EDITORIAL_INSPECTION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $result = (new PrivateEditorialInspection($db))->inspect($input, $caSha256);

    // Release no result if an observed input byte or custody change occurred
    // during connection or inspection. This candidate never enables SQL writes.
    $bounds = [EDITORIAL_INSPECTION_MANIFEST => [16384, 1], EDITORIAL_INSPECTION_CA => [65536, 1],
        EDITORIAL_INSPECTION_PASSWORD => [4096, 16]];
    foreach ($bounds as $path => [$maximum, $minimum]) {
        $current = editorial_inspection_read($path, $maximum, $minimum);
        if ($current['identity'] !== $files[$path]['identity'] ||
            !hash_equals($files[$path]['sha256'], $current['sha256']) ||
            !hash_equals($files[$path]['bytes'], $current['bytes'])) throw new DomainException();
    }
    unset($files, $current, $ca);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // No SQL diagnostic, selector, credential, pathname or chained exception.
    fwrite(STDERR, "EDITORIAL_INSPECTION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
