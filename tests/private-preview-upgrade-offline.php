<?php
declare(strict_types=1);

require __DIR__.'/../src/Production/PrivatePreviewProvisioning.php';

use Imagine\Sites\Production\PrivatePreviewProvisioning;

// Envelope and pre-connection guards only. No Laravel bootstrap, SQL, identity
// provisioning or operational destination is available to this test.
$now = 1800000000;
$manifest = [
    'schema' => 'SITES_PREVIEW_UPGRADE_V1',
    'source_contract_sha256' => PrivatePreviewProvisioning::legacyContractSha(),
    'target_contract_sha256' => PrivatePreviewProvisioning::contractSha(),
    'target' => ['database' => 'sites_staging', 'bootstrap_login' => 'sites_bootstrap',
        'host' => 'db', 'port' => 5432, 'server_address' => '127.0.0.1',
        'database_oid' => 12345, 'system_identifier' => '1234567890123456789'],
    'principals' => [
        ['login' => 'sites_preview_author', 'company_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'profile' => 'author', 'expires_at' => $now + 3600],
        ['login' => 'sites_preview_reviewer', 'company_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'profile' => 'reviewer', 'expires_at' => $now + 3600],
    ],
];
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
};
$denied = static function (callable $operation, string $reason, string $label) use ($ok): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        $ok($error->getMessage() === 'PROVISION_DENIED:'.$reason, $label.' bounded reason');
        return;
    }
    $ok(false, $label.' accepted unexpectedly');
};

$ok($manifest['source_contract_sha256'] === '5ecfa4e626c622b387047d51e3a672b733d5830283b4e34d847f7469df5279eb',
    'the only source contract is pinned to the original V1 SQL');
$ok($manifest['source_contract_sha256'] !== $manifest['target_contract_sha256'], 'source and target contracts are distinct');
$ok(PrivatePreviewProvisioning::validateUpgradeManifest($manifest, $now) === $manifest,
    'complete explicit upgrade envelope valid');
$reversed = $manifest;
$reversed['principals'] = array_reverse($reversed['principals']);
$ok(PrivatePreviewProvisioning::validateUpgradeManifest($reversed, $now) === $manifest,
    'upgrade principal order is canonical');

$fresh = ['schema' => 'SITES_PREVIEW_PROVISION_V1', 'contract_sha256' => $manifest['target_contract_sha256'],
    'target' => $manifest['target'], 'principals' => $manifest['principals']];
$ok(PrivatePreviewProvisioning::validateManifest($fresh, $now) === $fresh, 'fresh manifest remains valid separately');
$denied(fn() => PrivatePreviewProvisioning::validateUpgradeManifest($fresh, $now), 'manifest_fields',
    'fresh manifest cannot authorize upgrade');
$denied(fn() => PrivatePreviewProvisioning::validateManifest($manifest, $now), 'manifest_fields',
    'upgrade envelope is not a fresh installation manifest');

$mutations = [
    ['upgrade_manifest', static fn(&$m) => $m['schema'] = 'SITES_PREVIEW_PROVISION_V1'],
    ['upgrade_manifest', static fn(&$m) => $m['source_contract_sha256'] = str_repeat('0', 64)],
    ['upgrade_manifest', static fn(&$m) => $m['source_contract_sha256'] = $m['target_contract_sha256']],
    ['upgrade_manifest', static fn(&$m) => $m['target_contract_sha256'] = $m['source_contract_sha256']],
    ['upgrade_manifest', static fn(&$m) => $m['target_contract_sha256'] = strtoupper($m['target_contract_sha256'])],
    ['upgrade_manifest', static fn(&$m) => $m['source_contract_sha256'] = [$m['source_contract_sha256']]],
    ['upgrade_manifest', static fn(&$m) => $m['target_contract_sha256'] = null],
    ['manifest_fields', static fn(&$m) => $m['contract_sha256'] = $m['target_contract_sha256']],
    ['manifest_fields', static fn(&$m) => $m['authorized'] = true],
    ['manifest_fields', static fn(&$m) => $m['allow_revoked'] = true],
    ['manifest_fields', static fn(&$m) => $m['target']['password'] = 'forbidden-fixture-field'],
    ['manifest_fields', static fn(&$m) => $m['principals'][0]['sql_password'] = 'forbidden-fixture-field'],
    ['target', static fn(&$m) => $m['target']['database'] = 'sites_staging; DROP SCHEMA sites_preview'],
    ['target', static fn(&$m) => $m['target']['host'] = 'db;sslmode=disable'],
    ['target', static fn(&$m) => $m['target']['port'] = '5432'],
    ['target', static fn(&$m) => $m['target']['database_oid'] = 0],
    ['target', static fn(&$m) => $m['target']['system_identifier'] = '0'],
    ['manifest', static fn(&$m) => $m['principals'] = []],
    ['manifest', static fn(&$m) => $m['principals'] = array_fill(0, 33, $m['principals'][0])],
    ['principal', static fn(&$m) => $m['principals'][1] = $m['principals'][0]],
    ['principal', static fn(&$m) => $m['principals'][0]['login'] = 'sites_preview_owner'],
    ['principal', static fn(&$m) => $m['principals'][0]['profile'] = 'bootstrap'],
    ['principal', static fn(&$m) => $m['principals'][0]['expires_at'] = $now],
    ['principal', static fn(&$m) => $m['principals'][0]['expires_at'] = (string) ($now + 3600)],
];
foreach ($mutations as $index => [$reason, $mutate]) {
    $candidate = $manifest;
    $mutate($candidate);
    $denied(fn() => PrivatePreviewProvisioning::validateUpgradeManifest($candidate, $now), $reason,
        'invalid upgrade envelope '.$index);
}
$missing = $manifest;
unset($missing['source_contract_sha256']);
$denied(fn() => PrivatePreviewProvisioning::validateUpgradeManifest($missing, $now), 'manifest_fields', 'source hash is mandatory');

// No connection property is initialized. These public methods must reject the
// request before observing any database when the envelope or write guard fails.
$service = (new ReflectionClass(PrivatePreviewProvisioning::class))->newInstanceWithoutConstructor();
$denied(fn() => $service->applyUpgrade($manifest, str_repeat('a', 64)), 'explicit_upgrade_required',
    'upgrade requires separate explicit authorization');
foreach (['', str_repeat('g', 64), str_repeat('a', 63), str_repeat('A', 64)] as $hash) {
    $denied(fn() => $service->applyUpgrade($manifest, $hash, true), 'explicit_upgrade_required',
        'malformed reviewed hash is refused before connection');
}
$invalid = $manifest;
$invalid['schema'] = 'SITES_PREVIEW_PROVISION_V1';
$denied(fn() => $service->planUpgrade($invalid), 'upgrade_manifest', 'plan validates the explicit upgrade envelope first');
$denied(fn() => $service->checkUpgrade($invalid), 'upgrade_manifest', 'check validates the explicit upgrade envelope first');
$denied(fn() => $service->applyUpgrade($invalid, str_repeat('a', 64), true), 'upgrade_manifest',
    'apply validates the explicit upgrade envelope before connection');

echo "UPGRADE OFFLINE PASS: $checks envelope and pre-connection checks; pinned V1 source, separate V2 destination and explicit upgrade authorization; no SQL or operational changes.\n";
