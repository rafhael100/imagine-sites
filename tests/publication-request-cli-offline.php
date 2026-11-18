<?php

declare(strict_types=1);

use Imagine\Sites\Publication\PrivatePublicationRequestReceipt;

// CLI help/early refusals have autoload and fixed private paths blocked. Pure
// receipt IO runs separately in our own /tmp tree; it simulates no SQL success,
// principal, approval, request, attempt or publication authority.
$root = dirname(__DIR__);
$cli = $root.'/ops/publication-candidate/request.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Publication request offline failure: '.$label);
    ++$checks;
};
$run = static function (array $arguments, array $environment = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') $environment['LD_LIBRARY_PATH'] = $runtime;
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.dirname($cli), $cli, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Bounded request CLI subprocess unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, array $environment, string $label) use ($run, $ok): void {
    $ok($run($arguments, $environment) === [1, '', "PUBLICATION_REQUEST_DENIED\n"], $label);
};
[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php request.php plan') &&
    str_contains($stdout, '--authorize-request=SITES_PUBLICATION_REQUEST_ONLY_APPROVED') &&
    str_contains($stdout, 'reserves a new private receipt before one request_operation call') &&
    str_contains($stdout, 'A failure can follow a SQL commit') &&
    str_contains($stdout, 'No attempt, lease token, ACK, provider call'),
    'help needs no autoload, environment, private file or database');
$sha = '--plan-sha256='.str_repeat('a', 64);
$approval = '--authorize-request=SITES_PUBLICATION_REQUEST_ONLY_APPROVED';
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_PUBLICATION_REQUEST_CI' => '1'];
foreach ([
    [], ['--help', 'plan'], ['plan', '--help'], ['apply', '--help'],
    ['inspect'], ['request'], ['dispatch'], ['attempt'], ['acknowledge'], ['reconcile'], ['retry'], ['reset'],
    ['bind'], ['revoke'], ['record'], ['verify'], ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'],
    ['plan', 'extra'], ['plan', $sha], ['plan', $approval], ['plan', $sha, $approval],
    ['plan', '--manifest=/tmp/fictional'], ['plan', '--output=/tmp/fictional'],
    ['plan', '--password=FICTIONAL_SECRET_DO_NOT_ECHO'], ['plan', '--receipt-ref=fictional'],
    ['plan', '--operation-ref=FICTIONAL_UUID_DO_NOT_ECHO'], ['plan', '--expected-binding-revision=2'],
    ['apply'], ['apply', $sha], ['apply', $approval], ['apply', $sha, $sha],
    ['apply', $approval, $approval], ['apply', $sha, $approval, $sha],
    ['apply', $sha, $approval, $approval], ['apply', $sha, $approval, 'extra'],
    ['apply', $sha, '--authorize-request'], ['apply', '--plan-sha256', $approval],
    ['apply', $sha, '--authorize-request='], ['apply', '--plan-sha256=', $approval],
    ['apply', $sha, '--authorize-request=SITES_RELEASE_BINDING_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-request=SITES_RELEASE_REVOCATION_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-request=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-request=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-request=SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-request=sites_publication_request_only_approved'],
    ['apply', $sha, $approval."\n"],
    ['apply', '--plan-sha256='.str_repeat('A', 64), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 63), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 65), $approval],
    ['apply', '--plan-sha256='.str_repeat('g', 64), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 64)."\n", $approval],
    ['apply', '--plan-sha='.str_repeat('a', 64), $approval],
    ['apply', $sha, '--authorize-apply=SITES_PUBLICATION_REQUEST_ONLY_APPROVED'],
    ['apply', '--manifest=/tmp/fictional', $approval],
    ['apply', '--password=FICTIONAL_SECRET_DO_NOT_ECHO', $approval],
    ['apply', '--receipt-ref=fictional', $approval],
    ['apply', '--lease-token=FICTIONAL_TOKEN_DO_NOT_ECHO', $approval],
    ['apply', '--expected-binding-revision=2', $approval],
] as $index => $arguments) $deny($arguments, $ci, 'bounded argument refusal '.$index);
foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_PUBLICATION_REQUEST_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_PUBLICATION_REQUEST_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_REQUEST_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_REQUEST_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_RECONCILIATION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_BINDING_CI' => '1'],
] as $index => $environment) {
    $deny(['plan'], $environment, 'plan environment refusal '.$index);
    $deny(['apply', $sha, $approval], $environment, 'apply environment refusal '.$index);
}
$cliChecks = $checks;

// The following tests only format and persist synthetic pointers. They never
// call PrivatePublicationRequest, PDO, fixed mounts or an application kernel.
require $root.'/apps/sites/vendor/autoload.php';
$ioDenied = static function (callable $operation, string $label) use ($ok): void {
    $error = null;
    ob_start();
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error instanceof RuntimeException && $error->getMessage() === 'PUBLICATION_REQUEST_DENIED' &&
        $error->getCode() === 0 && $error->getPrevious() === null && $output === '', $label);
};
$snapshot = static function (string $path): array {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat)) throw new RuntimeException('Synthetic receipt missing');
    $result = ['bytes' => file_get_contents($path)];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) $result[$field] = $stat[$field];
    return $result;
};
$newFile = static function (string $path, string $bytes): void {
    $mask = umask(0077);
    try { $stream = fopen($path, 'x+b'); } finally { umask($mask); }
    if (!is_resource($stream)) throw new RuntimeException('Exclusive synthetic file unavailable');
    try {
        if (($bytes !== '' && fwrite($stream, $bytes) !== strlen($bytes)) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('Synthetic file write failed');
        }
    } finally { fclose($stream); }
};
$temporary = '/tmp/sites-publication-request-'.bin2hex(random_bytes(16));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Private IO test directory unavailable');
clearstatcache(true, $temporary);
$temporaryIdentity = lstat($temporary);
$ok(realpath($temporary) === $temporary && ($temporaryIdentity['mode'] & 07777) === 0700 &&
    $temporaryIdentity['uid'] === posix_geteuid(), 'receipt IO uses its own canonical EUID0700 /tmp directory');
$removeOwnTree = static function (string $path) use (&$removeOwnTree): void {
    $stat = lstat($path);
    if (!is_array($stat) || $stat['uid'] !== posix_geteuid()) throw new RuntimeException('IO test cleanup custody changed');
    if (($stat['mode'] & 0170000) === 0040000) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) $removeOwnTree($path.'/'.$name);
        if (!rmdir($path)) throw new RuntimeException('IO test directory cleanup failed');
    } elseif (!unlink($path)) throw new RuntimeException('IO test file cleanup failed');
};
$opened = [];
try {
    $planHash = str_repeat('a', 64);
    $payload = ['schema' => 'SITES_PUBLICATION_REQUEST_RECEIPT_V1', 'planSha256' => $planHash,
        'operationRef' => '70000000-0000-4000-8000-000000005050'];
    $expectedBytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    $sentinel = $temporary.'/sentinel';
    $newFile($sentinel, 'SYNTHETIC-UNRELATED-SENTINEL');
    $sentinelBefore = $snapshot($sentinel);
    foreach (['R', 'request_505-A', str_repeat('a', 64)] as $reference) {
        $beforeNames = scandir($temporary);
        PrivatePublicationRequestReceipt::checkAvailable($temporary, $reference);
        $ok(scandir($temporary) === $beforeNames, 'availability check creates no reservation');
        $receipt = PrivatePublicationRequestReceipt::reserve($temporary, $reference, $planHash);
        $opened[] = $receipt;
        $path = $temporary.'/'.$reference.'.json';
        $empty = $snapshot($path);
        $receipt->assertReserved();
        $ok($empty['bytes'] === '' && $empty['size'] === 0 && ($empty['mode'] & 07777) === 0600 &&
            $empty['uid'] === posix_geteuid() && $empty['nlink'] === 1,
            'reservation exists exclusively as an empty private single-link file');
        $ioDenied(static fn () => PrivatePublicationRequestReceipt::reserve($temporary, $reference, $planHash),
            'a second reservation refuses the existing leaf');
        $ioDenied(static fn () => PrivatePublicationRequestReceipt::checkAvailable($temporary, $reference),
            'plan availability also refuses the existing leaf');
        $ok($snapshot($path) === $empty, 'failed reservation preserves the held file');
        $receipt->write(array_reverse($payload, true));
        $completed = $snapshot($path);
        $ok($completed['bytes'] === $expectedBytes && $completed['ino'] === $empty['ino'] &&
            $completed['dev'] === $empty['dev'] && ($completed['mode'] & 07777) === 0600,
            'write retains the original FD identity and serializes exactly three canonical fields');
        $ioDenied(static fn () => $receipt->write($payload), 'a receipt cannot be written twice');
        $ok($snapshot($path) === $completed, 'refused repeat does not change completed bytes or metadata');
        $receipt->close();
        $ok($snapshot($path) === $completed, 'close preserves the completed receipt');
    }

    foreach (['', '.', '..', '../escape', '/absolute', 'name.json', 'slash/name', 'back\\slash',
        'has space', 'café', "name\n", "name\0", str_repeat('a', 65)] as $reference) {
        $beforeNames = scandir($temporary);
        $ioDenied(static fn () => PrivatePublicationRequestReceipt::reserve($temporary, $reference, $planHash),
            'unsafe receipt name is refused');
        $ok(scandir($temporary) === $beforeNames, 'unsafe name creates no file');
    }
    foreach (['', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65), str_repeat('g', 64)] as $hash) {
        $ioDenied(static fn () => PrivatePublicationRequestReceipt::reserve($temporary, 'invalid-plan', $hash),
            'invalid plan commitment is refused before reservation');
    }
    $ok(!file_exists($temporary.'/invalid-plan.json'), 'invalid plan hashes leave no reservation');

    $counter = 0;
    $newParent = static function () use ($temporary, &$counter): string {
        $parent = $temporary.'/case-'.++$counter;
        if (!mkdir($parent, 0700)) throw new RuntimeException('Synthetic parent unavailable');
        return $parent;
    };
    foreach (['file', 'directory', 'symlink', 'dangling', 'hardlink'] as $kind) {
        $parent = $newParent();
        $path = $parent.'/receipt.json';
        if ($kind === 'file') $newFile($path, 'EXISTING-RECEIPT');
        elseif ($kind === 'directory') { if (!mkdir($path, 0700)) throw new RuntimeException('Synthetic directory unavailable'); }
        elseif ($kind === 'hardlink') {
            $newFile($path, 'EXISTING-LINKED-RECEIPT');
            if (!link($path, $parent.'/linked')) throw new RuntimeException('Synthetic hard link unavailable');
        } elseif (!symlink($kind === 'symlink' ? $sentinel : $parent.'/missing', $path)) {
            throw new RuntimeException('Synthetic symlink unavailable');
        }
        clearstatcache(true, $path);
        $before = lstat($path);
        $bytes = in_array($kind, ['file', 'hardlink'], true) ? file_get_contents($path) : null;
        $ioDenied(static fn () => PrivatePublicationRequestReceipt::reserve($parent, 'receipt', $planHash),
            'existing '.$kind.' target is refused');
        clearstatcache(true, $path);
        $after = lstat($path);
        $ok($before['dev'] === $after['dev'] && $before['ino'] === $after['ino'] && $before['mode'] === $after['mode'] &&
            ($bytes === null || file_get_contents($path) === $bytes) && $snapshot($sentinel) === $sentinelBefore,
            'existing '.$kind.' target and unrelated sentinel remain untouched');
    }
    foreach ([0755, 0770, 01700] as $mode) {
        $parent = $newParent();
        if (!chmod($parent, $mode)) throw new RuntimeException('Synthetic parent mode unavailable');
        $ioDenied(static fn () => PrivatePublicationRequestReceipt::reserve($parent, 'receipt', $planHash),
            'nonprivate output parent is refused');
        clearstatcache(true, $parent);
        $ok(scandir($parent) === ['.', '..'] && (lstat($parent)['mode'] & 07777) === $mode,
            'the helper neither reserves nor repairs parent permissions');
    }
    $canonical = $newParent();
    $alias = $temporary.'/parent-alias';
    if (!symlink($canonical, $alias)) throw new RuntimeException('Synthetic parent alias unavailable');
    foreach ([$canonical.'/', $canonical.'/.', $temporary.'/../'.basename($temporary).'/'.basename($canonical),
        $temporary.'//'.basename($canonical), $alias, $temporary.'/missing', 'relative'] as $parent) {
        $ioDenied(static fn () => PrivatePublicationRequestReceipt::reserve($parent, 'receipt', $planHash),
            'noncanonical, missing or symlink parent is refused');
    }
    $unsafe = $newParent();
    if (!chmod($unsafe, 0777) || !mkdir($unsafe.'/private', 0700)) throw new RuntimeException('Synthetic ancestor unavailable');
    $ioDenied(static fn () => PrivatePublicationRequestReceipt::reserve($unsafe.'/private', 'receipt', $planHash),
        'writable nonsticky ancestor is refused');
    $ok(scandir($canonical) === ['.', '..'] && scandir($unsafe.'/private') === ['.', '..'],
        'unsafe parent paths create no receipts');

    foreach (['bytes', 'mode', 'hardlink', 'replacement', 'parent replacement'] as $change) {
        $parent = $newParent();
        $path = $parent.'/receipt.json';
        $receipt = PrivatePublicationRequestReceipt::reserve($parent, 'receipt', $planHash);
        $opened[] = $receipt;
        if ($change === 'bytes') file_put_contents($path, 'EXTERNAL-SYNTHETIC-BYTES');
        elseif ($change === 'mode') { if (!chmod($path, 0400)) throw new RuntimeException('Synthetic mode change failed'); }
        elseif ($change === 'hardlink') { if (!link($path, $parent.'/linked')) throw new RuntimeException('Synthetic hard link failed'); }
        elseif ($change === 'replacement') {
            if (!rename($path, $parent.'/held-reservation')) throw new RuntimeException('Synthetic replacement failed');
            $newFile($path, 'REPLACEMENT-SENTINEL');
        } else {
            if (!rename($parent, $parent.'-held') || !mkdir($parent, 0700)) {
                throw new RuntimeException('Synthetic parent replacement failed');
            }
            $newFile($path, 'REPLACEMENT-PARENT-SENTINEL');
        }
        $before = $snapshot($path);
        $heldPath = match ($change) {
            'replacement' => $parent.'/held-reservation',
            'parent replacement' => $parent.'-held/receipt.json',
            default => null,
        };
        $held = $heldPath === null ? null : $snapshot($heldPath);
        $ioDenied(static fn () => $receipt->assertReserved(), 'observed reservation '.$change.' fails before SQL');
        $ioDenied(static fn () => $receipt->write($payload), 'observed reservation '.$change.' also refuses the later write');
        $receipt->close();
        $ok($snapshot($path) === $before && ($held === null || $snapshot($heldPath) === $held),
            'reservation '.$change.' refusal preserves all observed files and never cleans up');
    }

    $badPayloads = [
        [], array_replace($payload, ['schema' => 'WRONG_SCHEMA']),
        array_replace($payload, ['planSha256' => str_repeat('b', 64)]),
        array_replace($payload, ['planSha256' => [$planHash]]),
        array_replace($payload, ['operationRef' => 'not-a-uuid']),
        array_replace($payload, ['operationRef' => 123]),
        $payload + ['leaseToken' => 'FICTIONAL_TOKEN_DO_NOT_STORE'],
        $payload + ['technicalStatus' => 'requested'], $payload + ['canPublish' => false],
    ];
    foreach ($badPayloads as $value) {
        $parent = $newParent();
        $receipt = PrivatePublicationRequestReceipt::reserve($parent, 'receipt', $planHash);
        $opened[] = $receipt;
        $before = $snapshot($parent.'/receipt.json');
        $ioDenied(static fn () => $receipt->write($value), 'nonminimal or mismatched private receipt is refused');
        $receipt->close();
        $ok($snapshot($parent.'/receipt.json') === $before,
            'invalid receipt leaves its empty exclusive reservation intact');
    }
    $receipt = PrivatePublicationRequestReceipt::reserve($temporary, 'closed-empty', $planHash);
    $empty = $snapshot($temporary.'/closed-empty.json');
    $receipt->close();
    $ioDenied(static fn () => $receipt->assertReserved(), 'closed FD cannot be reused');
    $ok($snapshot($temporary.'/closed-empty.json') === $empty, 'close does not remove the empty reservation');
    $receipt = PrivatePublicationRequestReceipt::reserve($temporary, 'destructed-empty', $planHash);
    $empty = $snapshot($temporary.'/destructed-empty.json');
    unset($receipt);
    $ok($snapshot($temporary.'/destructed-empty.json') === $empty, 'destruction closes only the FD and retains the empty file');
    $ok($snapshot($sentinel) === $sentinelBefore, 'the complete IO suite preserves its unrelated sentinel');
} finally {
    foreach ($opened as $receipt) $receipt->close();
    clearstatcache(true, $temporary);
    $current = lstat($temporary);
    if (!is_array($current) || $current['dev'] !== $temporaryIdentity['dev'] ||
        $current['ino'] !== $temporaryIdentity['ino'] || $current['uid'] !== posix_geteuid() ||
        ($current['mode'] & 07777) !== 0700) throw new RuntimeException('Private IO test root identity changed');
    $removeOwnTree($temporary);
}
echo 'PUBLICATION REQUEST CLI OFFLINE PASS: '.$checks.' checks ('.$cliChecks.' CLI refusals/help, '.
    ($checks - $cliChecks)." pure receipt IO); no SQL, credentials or publication authority\n";
