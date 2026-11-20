<?php

declare(strict_types=1);

/**
 * Local-only Onepage release A -> B -> explicit reversal to last-approved A.
 * Verifies bytes actually returned over ephemeral 127.0.0.1 HTTP and compares
 * with the historical F1-09/F1-16 origin and ACK state machine.
 *
 * No Portal, client, authentication, provider, public DNS or deployment.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_ONEPAGE_RELEASE_LOOPBACK_LAB') !== '1') {
    fwrite(STDERR, "Local origin swap test is restricted to isolated CI.\n");
    exit(1);
}

use Imagine\Sites\Domain\{PolicyDecision, ReleaseManifest};
use Imagine\Sites\Production\LocalSiteRendering;
use Imagine\Sites\Publication\{Observation, SyntheticOriginRegistry, SyntheticPublishCoordinator};

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Domain', 'Production', 'Publication'] as $area) {
        $prefix = 'Imagine\\Sites\\'.$area.'\\';
        if (!str_starts_with($class, $prefix)) continue;
        $short = substr($class, strlen($prefix));
        if (preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $short) !== 1) {
            throw new RuntimeException('Unsafe test-only class name');
        }
        require_once $root.'/src/'.$area.'/'.$short.'.php';
        return;
    }
});

$checks = 0;
$check = static function (bool $condition, string $title) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Local release swap failed: '.$title);
    ++$checks;
};
$inputFile = $root.'/contracts/portal-sites/candidates/brief-production-v1/examples/render-input.synthetic.json';
$input = file_get_contents($inputFile);
if (!is_string($input)) throw new RuntimeException('Missing synthetic Onepage editorial fixture');
$json = static fn(array $v): string => json_encode($v,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
$first = LocalSiteRendering::render($input);
$edited = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
$edited['description'] = 'Release B — apenas revisão local fictícia, sem endereço público ou aceite.';
$second = LocalSiteRendering::render($json($edited));
$check($first->digest !== $second->digest && $first->sizeBytes !== 0 && $second->sizeBytes !== 0,
    'deterministic renderer produced two different Onepage HTML releases');

$tenant = 'tenant-onepage-loopback';
$site = 'site-onepage-loopback';
$a = new ReleaseManifest($tenant, $site, 'onepage-release-A', $first->digest);
$b = new ReleaseManifest($tenant, $site, 'onepage-release-B', $second->digest);
$previousApproved = $a; // Only this synthetic release is authorized as rollback target.
$origin = new SyntheticOriginRegistry();
$coordinator = new SyntheticPublishCoordinator();
$syntheticPolicy = new PolicyDecision(true, []); // CI fixture only: NOT a real publication gate.

$folder = sys_get_temp_dir().'/imagine-onepage-origin-'.bin2hex(random_bytes(12));
if (!mkdir($folder, 0700)) throw new RuntimeException('Cannot allocate isolated origin');
$live = $folder.'/current.html';
$server = null;
try {
    if (file_put_contents($live, $first->html, LOCK_EX) !== strlen($first->html) ||
        !chmod($live, 0600)) throw new RuntimeException('Cannot create first local release');

    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($s === false) throw new RuntimeException('Cannot reserve ephemeral loopback port');
    $addr = stream_socket_get_name($s, false);
    if (!is_string($addr) || preg_match('/:(\d+)\z/D', $addr, $match) !== 1) {
        fclose($s);
        throw new RuntimeException('Invalid ephemeral port');
    }
    $port = (int)$match[1];
    fclose($s);
    $url = 'http://127.0.0.1:'.$port.'/current.html';
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $folder],
        [0 => ['pipe', 'r'], 1 => ['file', $folder.'/origin.log', 'w'],
            2 => ['file', $folder.'/error.log', 'w']],
        $pipes, $folder
    );
    if (!is_resource($server)) throw new RuntimeException('Cannot start disposable loopback server');
    fclose($pipes[0]);
    $nonce = 0;
    $httpRead = static function () use ($url, &$nonce): ?string {
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 1,
            'header' => "Cache-Control: no-store\r\n",
        ]]);
        $bytes = @file_get_contents($url.'?ci='.++$nonce, false, $context);
        return is_string($bytes) ? $bytes : null;
    };
    $ready = false;
    for ($try = 0; $try < 30; ++$try) {
        if ($httpRead() === $first->html) { $ready = true; break; }
        usleep(100000);
    }
    $check($ready, 'loopback HTTP origin serves A from actual disk bytes');
    $check(hash_equals($first->digest, hash('sha256', (string)$httpRead())),
        'independent loopback reader hashes exact A response bytes');

    $origin->seed($a, $first);
    $aObservation = $origin->probe($a, true);
    $check($aObservation->observedDigest === $first->digest &&
        $aObservation->releaseRef === $a->releaseRef,
        'F1-16 in-memory origin agrees with loopback A observation');

    // The B ACK happens BEFORE anything swaps. HTTP still serves A; the
    // historical state machine must not treat the ACK as published.
    $premature = $coordinator->acknowledge($b, $syntheticPolicy, 'mock-ack-before-swap',
        $a->digest);
    $check($premature->status() === 'acknowledged' && $premature->observedDigest() === null &&
        hash_equals($a->digest, hash('sha256', (string)$httpRead())),
        'B ACK alone does not change actual origin bytes');
    $coordinator->verify($premature, $origin, true);
    $check($premature->status() === 'inconclusive' &&
        $premature->previouslyObservedDigest === $a->digest,
        'F1-09 rejects stale origin for B and retains prior A digest');

    $stage = $folder.'/staged-B.html';
    if (file_put_contents($stage, $second->html, LOCK_EX) !== strlen($second->html) ||
        !chmod($stage, 0600) || !rename($stage, $live)) {
        throw new RuntimeException('Cannot atomically switch disposable local release');
    }
    $origin->seed($b, $second);
    $liveB = $httpRead();
    $check(is_string($liveB) && hash_equals($b->digest, hash('sha256', $liveB)) &&
        !hash_equals($a->digest, hash('sha256', $liveB)),
        'after explicit switch, loopback HTTP really serves release B');
    $bProbe = $origin->probe($b, true);
    $check($bProbe->releaseRef === $b->releaseRef &&
        hash_equals($bProbe->observedDigest, hash('sha256', $liveB)),
        'F1-16 origin and independent loopback bytes agree on B');

    $bOperation = $coordinator->acknowledge($b, $syntheticPolicy, 'mock-ack-B',
        $a->digest);
    $bOperation->observe(new Observation(
        $bProbe->releaseRef, hash('sha256', $liveB),
        'loopback-B-'.substr($b->digest, 0, 24),
        $bProbe->verifiedByIndependentObserver &&
            hash_equals($bProbe->observedDigest, hash('sha256', $liveB)),
        true
    ));
    $check($bOperation->status() === 'verified' &&
        $bOperation->observedDigest() === $b->digest &&
        $bOperation->previouslyObservedDigest === $a->digest,
        'synthetic technical verification requires observed exact B bytes, not ACK');

    // If the disk bytes become corrupt while a registry still says B, the
    // independent HTTP read must defeat that stale registry assertion.
    $corrupt = $folder.'/stage-corrupt.html';
    file_put_contents($corrupt, $second->html."\nnot-approved");
    chmod($corrupt, 0600);
    if (!rename($corrupt, $live)) throw new RuntimeException('Cannot set local corruption fixture');
    $tamperedBytes = $httpRead();
    $check(is_string($tamperedBytes) && hash('sha256', $tamperedBytes) !== $b->digest,
        'HTTP finds changed bytes despite unchanged synthetic origin registry');
    $mismatch = $coordinator->acknowledge($b, $syntheticPolicy, 'mock-ack-corrupt');
    $probeClaim = $origin->probe($b, true);
    $mismatch->observe(new Observation(
        $probeClaim->releaseRef, hash('sha256', $tamperedBytes),
        'loopback-corrupt-'.substr(hash('sha256', $tamperedBytes), 0, 24),
        hash_equals($probeClaim->observedDigest, hash('sha256', $tamperedBytes)), true
    ));
    $check($mismatch->status() === 'inconclusive',
        'false-positive registry assertion cannot override independent HTTP mismatch');

    // Reestablish B locally, then reverse ONLY by an explicit simulated
    // authorized action to the recorded previously approved A bytes.
    $stage = $folder.'/restage-B.html';
    file_put_contents($stage, $second->html);
    chmod($stage, 0600);
    if (!rename($stage, $live)) throw new RuntimeException('Cannot restage B for reversal');
    $check(hash_equals($b->digest, hash('sha256', (string)$httpRead())),
        'before explicit reversal, B remains the served release');

    $reversalApprovedByFixture = false;
    $check(!$reversalApprovedByFixture &&
        hash_equals($b->digest, hash('sha256', (string)$httpRead())),
        'no automatic rollback on ACK, mismatch, or restore-like event');
    $reversalApprovedByFixture = true;
    if (!$reversalApprovedByFixture || $previousApproved->digest !== $a->digest) {
        throw new RuntimeException('Explicit CI rollback not authorized for A');
    }
    $stage = $folder.'/staged-rollback-A.html';
    file_put_contents($stage, $first->html);
    chmod($stage, 0600);
    if (!rename($stage, $live)) throw new RuntimeException('Cannot explicitly reverse to A');
    $origin->seed($previousApproved, $first);
    $rollbackBytes = $httpRead();
    $check(is_string($rollbackBytes) && hash_equals($previousApproved->digest,
        hash('sha256', $rollbackBytes)), 'explicit reversal really serves last-approved A bytes');
    $rollbackProbe = $origin->probe($a, true);
    $rollbackOp = $coordinator->acknowledge($a, $syntheticPolicy, 'mock-rollback-ack', $b->digest);
    $rollbackOp->observe(new Observation(
        $rollbackProbe->releaseRef, hash('sha256', $rollbackBytes),
        'loopback-rollback-'.substr($a->digest, 0, 24),
        hash_equals($rollbackProbe->observedDigest, hash('sha256', $rollbackBytes)), true
    ));
    $check($rollbackOp->status() === 'verified' &&
        $rollbackOp->previouslyObservedDigest === $b->digest &&
        $rollbackOp->observedDigest() === $a->digest,
        'rollback success requires independent SHA of actually served A');
    $check($origin->probe($b, true)->releaseRef === $a->releaseRef,
        'stale B is no longer selected by mock origin after reversal');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (['current.html', 'staged-B.html', 'restage-B.html',
        'stage-corrupt.html', 'staged-rollback-A.html', 'origin.log', 'error.log'] as $name) {
        $path = $folder.'/'.$name;
        if (is_file($path)) unlink($path);
    }
    rmdir($folder);
}
$check(!is_dir($folder), 'no local origin or temporary HTML retained after test');
echo 'ONEPAGE LOOPBACK RELEASE SWITCHBACK PASS: '.$checks." synthetic assertions; NO-GO.\n";
