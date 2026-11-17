<?php

declare(strict_types=1);

// Focal input/filesystem tests. No PDO, SQL principal, network or positive
// commercial/registry authority is fabricated by this offline test.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Publication\SiteReleasePreparation;
use Imagine\Sites\Publication\SiteReleasePreparationFiles;
use Imagine\Sites\Publication\SiteReleasePreparationInput;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline preparation failure: '.$label);
    ++$checks;
};
$deny = static function (callable $operation, string $label) use ($ok): void {
    try { $operation(); } catch (DomainException $e) {
        $ok(in_array($e->getMessage(), [
            'Release preparation unavailable.', 'Release preparation input unavailable.',
            'Release preparation file unavailable.', 'Release preparation output unavailable.',
        ], true), $label.' sanitized');
        return;
    }
    $ok(false, $label.' unexpectedly accepted');
};
$artifact = (new StaticSiteRenderer())->render(new SiteBrief('onepage', 'Marca fictícia',
    'Prévia para revisão interna', 'Texto fictício para verificar bytes UTF-8 sem rede.',
    'Conheça os detalhes', ['Conteúdo de teste sem identificação de cliente']));
$manifest = [
    'schemaVersion' => 'sites.release-preparation.input.v1',
    'target' => ['host' => 'db.example.invalid', 'port' => 5432, 'database' => 'sites_staging',
        'databaseOid' => 16384, 'login' => 'restricted_reader', 'serverAddress' => '192.0.2.10',
        'caSha256' => str_repeat('a', 64)],
    'command' => ['schemaVersion' => '1', 'operationId' => 'offline-operation',
        'tenantRef' => 'offline-tenant', 'orderRef' => 'offline-order', 'contractRef' => 'offline-contract',
        'contractRevision' => 1, 'productCode' => 'onepage', 'idempotencyKey' => 'offline-idempotency'],
    'release' => ['siteRef' => '11111111-1111-4111-8111-111111111111',
        'releaseRef' => 'offline-release', 'artifactDigest' => $artifact->digest, 'editorialRevision' => 3],
    'packageRef' => 'offline-package',
];
$json = json_encode($manifest, JSON_THROW_ON_ERROR);
$input = SiteReleasePreparationInput::parse($json);
$ok($input['manifest']->digest === $artifact->digest && $input['editorialRevision'] === 3,
    'typed selectors preserve exact digest/revision');
$deny(static fn () => (new SiteReleasePreparation())->prepare(
    $input['command'], $input['manifest'], $input['editorialRevision'], $artifact),
    'real service denies without a current registry source');

$mutations = [
    static fn (&$m) => $m['schemaVersion'] = 'other',
    static fn (&$m) => $m['paymentVerified'] = true,
    static fn (&$m) => $m['target']['host'] = 'db;sslmode=disable',
    static fn (&$m) => $m['target']['host'] = '/var/run/postgresql',
    static fn (&$m) => $m['target']['port'] = '5432',
    static fn (&$m) => $m['target']['databaseOid'] = 0,
    static fn (&$m) => $m['target']['serverAddress'] = 'another-db.example.invalid',
    static fn (&$m) => $m['target']['password'] = 'not-an-input-field',
    static fn (&$m) => $m['target']['caSha256'] = strtoupper(str_repeat('a', 64)),
    static fn (&$m) => $m['command']['contractRevision'] = '1',
    static fn (&$m) => $m['release']['editorialRevision'] = 0,
    static fn (&$m) => $m['release']['editorialRevision'] = 3.5,
    static fn (&$m) => $m['release']['siteRef'] = 'not-a-site-uuid',
    static fn (&$m) => $m['release']['htmlPath'] = '/tmp/arbitrary',
    static fn (&$m) => $m['packageRef'] = '../escape',
    static fn (&$m) => $m['packageRef'] = str_repeat('x', 65),
];
foreach ($mutations as $i => $mutate) {
    $candidate = $manifest;
    $mutate($candidate);
    $deny(static fn () => SiteReleasePreparationInput::parse(json_encode($candidate, JSON_THROW_ON_ERROR)),
        'invalid typed input '.$i);
}
foreach ([
    '{"schemaVersion":"ignored",'.substr($json, 1),
    str_replace('"port":5432', '"port":5432,"port":5432', $json),
    str_replace('"port":5432', '"port":5432,"\\u0070ort":5432', $json),
    $json.' trailing',
] as $i => $ambiguous) {
    $deny(static fn () => SiteReleasePreparationInput::parse($ambiguous), 'ambiguous JSON '.$i);
}

$root = sys_get_temp_dir().'/sites-release-preparation-offline-'.bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('Own offline directory unavailable');
$cleanup = static function (string $path) use (&$cleanup): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (new FilesystemIterator($path) as $file) $cleanup($file->getPathname());
    @rmdir($path);
};
try {
    $private = $root.'/input.html';
    file_put_contents($private, $artifact->html);
    chmod($private, 0600);
    $ok(SiteReleasePreparationFiles::read($private, 150000) === $artifact->html,
        'private input preserves all UTF-8 bytes');
    $deny(static fn () => SiteReleasePreparationFiles::read($private, 10), 'oversized input');
    chmod($private, 0644);
    $deny(static fn () => SiteReleasePreparationFiles::read($private, 150000), 'public input mode');
    chmod($private, 0600);
    symlink($private, $root.'/linked.html');
    $deny(static fn () => SiteReleasePreparationFiles::read($root.'/linked.html', 150000), 'symlinked input');
    link($private, $root.'/hardlink.html');
    $deny(static fn () => SiteReleasePreparationFiles::read($private, 150000), 'multiply linked input');
    unlink($root.'/hardlink.html');
    symlink($root, $root.'/linked-directory');
    $deny(static fn () => SiteReleasePreparationFiles::read($root.'/linked-directory/input.html', 150000),
        'symlinked ancestor');

    // This is a filesystem fixture, deliberately NOT a successful preparation
    // or an asserted registry binding. Positive preparation is tested in PG18.
    $files = ['index.html' => $artifact->html,
        'release-manifest.json' => "{\"purpose\":\"offline_filesystem_test\",\"publishable\":false}\n",
        'readiness.json' => "{\"canPublish\":false,\"independentEvidence\":\"not_evaluated\"}\n"];
    $package = ['files' => $files, 'artifactDigest' => $artifact->digest,
        'packageDigest' => hash('sha256', implode("\0", $files)),
        'bindingRevision' => 1, 'editorialRevision' => 3];
    $output = SiteReleasePreparationFiles::write($root, 'new-package', $package);
    $ok($output === $root.'/new-package' && (fileperms($output) & 0777) === 0700,
        'new output private directory');
    foreach ($files as $name => $bytes) {
        $ok(file_get_contents($output.'/'.$name) === $bytes &&
            (fileperms($output.'/'.$name) & 0777) === 0600, 'exact private file '.$name);
    }
    $deny(static fn () => SiteReleasePreparationFiles::write($root, 'new-package', $package),
        'existing package never replaced');
    mkdir($root.'/empty-existing', 0700);
    $deny(static fn () => SiteReleasePreparationFiles::write($root, 'empty-existing', $package),
        'existing empty directory never replaced');
    symlink($root.'/absent', $root.'/linked-output');
    $deny(static fn () => SiteReleasePreparationFiles::write($root, 'linked-output', $package),
        'dangling output symlink');
    $bad = $package;
    $bad['files']['../escape'] = 'not allowed';
    $deny(static fn () => SiteReleasePreparationFiles::write($root, 'extra-file', $bad), 'fixed file list');
    $bad = $package;
    $bad['packageDigest'] = str_repeat('0', 64);
    $deny(static fn () => SiteReleasePreparationFiles::write($root, 'wrong-hash', $bad), 'package hash mismatch');
    $deny(static fn () => SiteReleasePreparationFiles::write($root, '../escape', $package), 'output traversal');
    chmod($root, 0755);
    $deny(static fn () => SiteReleasePreparationFiles::write($root, 'public-parent', $package), 'public output parent');
    chmod($root, 0700);
    $ok(glob($root.'/.preparing-*') === [], 'no incomplete stage after successful writes or refusals');
} finally {
    $cleanup($root);
}
echo "RELEASE PREPARATION OFFLINE PASS: $checks input, custody, exclusive-output and default-deny assertions; no SQL authority claimed.\n";
