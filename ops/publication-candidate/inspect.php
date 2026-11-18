<?php

declare(strict_types=1);

use Imagine\Sites\Publication\PrivatePublicationInspection;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;

// Explicit CLI only: no application boot, route, dispatcher or SQL mutation.
const PUBLICATION_INSPECTION_MANIFEST = '/run/secrets/publication_inspection';
const PUBLICATION_INSPECTION_PASSWORD = '/run/secrets/sites_publication_reader';
const PUBLICATION_INSPECTION_CA = '/run/secrets/sites_publication_ca';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
if ($argc === 2 && $argv[1] === '--help') {
    echo "Candidate only: php inspect.php inspect\n";
    echo "Reads one known publication-journal operation through fixed private mounts.\n";
    echo "Requires a restricted PostgreSQL18 reader and pinned verify-full TLS.\n";
    echo "No listing, reconciliation, retry, publication or state change.\n";
    exit(0);
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('PUBLICATION_INSPECTION_DENIED');
});
$exitCode = 0;
try {
    if ($argc !== 2 || $argv[1] !== 'inspect') throw new DomainException();
    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('SITES_PUBLICATION_INSPECTION_CI') === '1';
    $staging = getenv('GITHUB_ACTIONS') !== 'true' && getenv('APP_ENV') === 'staging' &&
        getenv('SITES_PUBLICATION_INSPECTION_CI') !== '1';
    if (!$ci && !$staging) throw new DomainException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $manifestBytes = SiteReleasePreparationFiles::read(PUBLICATION_INSPECTION_MANIFEST, 16384);
    $manifest = PrivatePublicationInspection::parseManifest($manifestBytes);
    $target = $manifest['target'];
    if (($staging && $target['database'] !== 'sites_staging') ||
        ($ci && ($target['database'] !== 'postgres' || $target['host'] !== 'localhost' ||
            $target['port'] !== 55449 || $target['login'] !== 'pj_inspection_a_reader' ||
            $target['serverAddress'] !== '127.0.0.1'))) throw new DomainException();

    $ca = SiteReleasePreparationFiles::read(PUBLICATION_INSPECTION_CA, 65536);
    $caSha256 = hash('sha256', $ca);
    if (!hash_equals($target['caSha256'], $caSha256) ||
        !str_contains($ca, '-----BEGIN CERTIFICATE-----') || str_contains($ca, 'PRIVATE KEY')) {
        throw new DomainException();
    }
    $password = rtrim(SiteReleasePreparationFiles::read(PUBLICATION_INSPECTION_PASSWORD, 4096, 16), "\r\n");
    if (strlen($password) < 16 || str_contains($password, "\0") ||
        str_contains($password, "\n") || str_contains($password, "\r")) throw new DomainException();

    $dsn = 'pgsql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['database'].
        ';sslmode=verify-full;sslrootcert='.PUBLICATION_INSPECTION_CA.';gssencmode=disable;connect_timeout=5';
    $db = new PDO($dsn, $target['login'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    unset($password);
    // Session settings only. The inspector performs no SET, DDL or data write.
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $result = (new PrivatePublicationInspection($db))->inspect($manifest, $caSha256);
    if (!hash_equals($caSha256, hash('sha256', SiteReleasePreparationFiles::read(PUBLICATION_INSPECTION_CA, 65536))) ||
        !hash_equals($manifestBytes, SiteReleasePreparationFiles::read(PUBLICATION_INSPECTION_MANIFEST, 16384))) {
        throw new DomainException();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // No diagnostic, input, path, identity, content or secret is echoed.
    fwrite(STDERR, "PUBLICATION_INSPECTION_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
