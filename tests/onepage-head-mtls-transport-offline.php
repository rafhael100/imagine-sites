<?php

declare(strict_types=1);

// Offline contract for an UNBOUND private transport candidate. All positive
// assertions below validate DATA SHAPE, never actual approval or network TLS.
require_once __DIR__.'/../src/Production/BriefCandidateJson.php';
require_once __DIR__.'/../apps/sites/app/Integration/CurrentPortalOnepageHeadSource.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalOnepageHeadMtlsHttpSource.php';

use App\Integration\CurrentPortalOnepageHeadSource;
use App\Integration\PortalOnepageHeadMtlsHttpSource;

$checks = 0;
$ok = static function (bool $success, string $case) use (&$checks): void {
    if (!$success) throw new RuntimeException('ONEPAGE MTLS CANDIDATE FAILURE: '.$case);
    ++$checks;
};
$site = '11111111-1111-4111-8111-111111111111';
$snapshot = '22222222-2222-4222-8222-222222222222';
$head = [
    'tenantRef' => 'tenant-A',
    'orderRef' => 'order-A',
    'siteRef' => $site,
    'contractRef' => 'contract-A',
    'contractRevision' => 2,
    'productCode' => 'onepage',
    'snapshotRef' => $snapshot,
    'briefRevision' => 3,
    'sourceVersion' => 7,
    'manifestSha256' => str_repeat('a',64),
    'payloadSha256' => str_repeat('b',64),
];
$json = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$parse = static fn (string $body, string $tenant='tenant-A', string $order='order-A', ?string $siteRef=null): ?array =>
    PortalOnepageHeadMtlsHttpSource::parseCandidateResponse($body, $tenant, $order, $siteRef ?? $site);
$ok($parse($json($head)) === $head, 'valid candidate shape');
$ok($parse($json(array_reverse($head, true))) === $head, 'canonical key projection');
$ok($parse($json($head), 'tenant-B') === null, 'tenant mismatch');
$ok($parse($json($head), 'tenant-A', 'order-B') === null, 'order mismatch');
$ok($parse($json($head), 'tenant-A', 'order-A', $snapshot) === null, 'site mismatch');
$ok($parse('') === null && $parse('[]') === null, 'empty or array denied');
$ok($parse(substr($json($head), 0, -1).',"tenantRef":"tenant-B"}') === null, 'duplicate identity key denied');
$ok($parse($json($head)."\n".str_repeat(' ',4096)) === null, 'oversized response denied');
$ok($parse($json(array_merge($head, ['canPublish'=>true]))) === null, 'authority injection denied');
$ok($parse($json(array_diff_key($head, ['sourceVersion'=>true]))) === null, 'missing field denied');
foreach ([
    'tenantRef' => 'tenant-B',
    'contractRef' => '',
    'contractRevision' => '2',
    'productCode' => 'landing_page',
    'siteRef' => 'invalid',
    'snapshotRef' => 'invalid',
    'briefRevision' => 0,
    'sourceVersion' => -1,
    'manifestSha256' => str_repeat('z',64),
    'payloadSha256' => strtoupper($head['payloadSha256']),
] as $name => $invalid) {
    $tampered = $head;
    $tampered[$name] = $invalid;
    $ok($parse($json($tampered)) === null, 'reject invalid '.$name);
}
$ok($parse(str_replace('"sourceVersion":7','"sourceVersion":7.0',$json($head))) === null,
    'float revision denied');
$ok($parse(str_replace('"sourceVersion":7','"sourceVersion":7e0',$json($head))) === null,
    'exponent revision denied');
$ok($parse($json($head), " tenant-A") === null, 'invalid caller selector denied');

// Constructor checks destination and cert paths BEFORE any network operation.
$files = [];
try {
    foreach (['ca','cert','key'] as $label) {
        $path = tempnam(sys_get_temp_dir(), 'head-'.$label.'-');
        if ($path === false || file_put_contents($path, 'fake-test-only-pem') === false) {
            throw new RuntimeException('Cannot prepare lab-only dummy files');
        }
        $files[] = $path;
    }
    [$ca,$cert,$key] = $files;
    $host='portal.example.test';
    $url='https://portal.example.test/internal/sites/v1/brief-head/current';
    $construct=static fn(string $endpoint,string $expectedHost='portal.example.test',?string $caPath=null,int $timeout=3000):
        PortalOnepageHeadMtlsHttpSource =>
        new PortalOnepageHeadMtlsHttpSource($endpoint,$expectedHost,$caPath ?? $ca,$cert,$key,$timeout);
    $source=$construct($url);
    $ok($source instanceof CurrentPortalOnepageHeadSource, 'candidate implements current-head source');
    $ok($source->readCurrent('bad value', 'order-A', $site) === null,
        'invalid selector never starts a request');
    foreach ([
        ['http://portal.example.test/internal/sites/v1/brief-head/current', $host, $ca, 3000],
        ['https://elsewhere.example.test/internal/sites/v1/brief-head/current', $host, $ca, 3000],
        ['https://localhost/internal/sites/v1/brief-head/current', 'localhost', $ca, 3000],
        ['https://127.0.0.1/internal/sites/v1/brief-head/current', '127.0.0.1', $ca, 3000],
        ['https://portal.example.test:8443/internal/sites/v1/brief-head/current', $host, $ca, 3000],
        ['https://portal.example.test/other', $host, $ca, 3000],
        ['https://portal.example.test/internal/sites/v1/brief-head/current?tenant=A', $host, $ca, 3000],
        ['https://user:pass@portal.example.test/internal/sites/v1/brief-head/current', $host, $ca, 3000],
        [$url, $host, '/nonexistent/path-for-imagine-head', 3000],
        [$url, $host, $ca, 100],
        [$url, $host, $ca, 5001],
    ] as [$candidateUrl,$candidateHost,$candidateCa,$candidateTimeout]) {
        $denied=false;
        try {
            $construct($candidateUrl,$candidateHost,$candidateCa,$candidateTimeout);
        } catch (RuntimeException $error) {
            $denied=$error->getMessage()==='ONEPAGE_PORTAL_HEAD_SOURCE_UNAVAILABLE';
        }
        $ok($denied,'reject untrusted endpoint/identity configuration');
    }
} finally {
    foreach ($files as $file) @unlink($file);
}
echo 'ONEPAGE HEAD MTLS TRANSPORT OFFLINE PASS: '.$checks.
    " checks; no network requests, no Portal endpoint, no approval or publication.\n";
