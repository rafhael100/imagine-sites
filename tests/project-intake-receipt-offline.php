<?php

declare(strict_types=1);

use Imagine\Sites\Integration\PrivateProjectIntakeReceipt;
use Imagine\Sites\Integration\SiteProjectCommand;

// Real private file IO in an owned temporary tree. The synthetic native-shaped
// arrays are formatting inputs only: this test simulates no SQL commit, Portal
// authority, principal, payment or production approval and never touches /run.
$root = dirname(__DIR__);
require $root.'/apps/sites/vendor/autoload.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Project intake receipt offline failure: '.$label);
    ++$checks;
};
$denied = static function (callable $operation, string $label) use ($ok): void {
    $error = null;
    ob_start();
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error instanceof RuntimeException && $error->getMessage() === 'PROJECT_INTAKE_RECEIVE_DENIED' &&
        $error->getCode() === 0 && $error->getPrevious() === null && $output === '', $label);
};
$snapshot = static function (string $path): array {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
        throw new RuntimeException('Synthetic receipt file unavailable');
    }
    $value = ['bytes' => file_get_contents($path)];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
        $value[$field] = $stat[$field];
    }
    return $value;
};
$pathIdentity = static function (string $path): array {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat)) throw new RuntimeException('Synthetic path unavailable');
    $value = [];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
        $value[$field] = $stat[$field];
    }
    return $value;
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
$temporary = '/tmp/sites-project-intake-receipt-'.bin2hex(random_bytes(16));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Private receipt test root unavailable');
clearstatcache(true, $temporary);
$temporaryIdentity = lstat($temporary);
$ok(is_array($temporaryIdentity) && realpath($temporary) === $temporary &&
    ($temporaryIdentity['mode'] & 07777) === 0700 && $temporaryIdentity['uid'] === posix_geteuid(),
    'receipt IO uses only its own canonical EUID0700 temporary tree');
$removeOwnTree = static function (string $path) use (&$removeOwnTree): void {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat) || $stat['uid'] !== posix_geteuid()) {
        throw new RuntimeException('Receipt test cleanup custody changed');
    }
    if (($stat['mode'] & 0170000) === 0040000) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) $removeOwnTree($path.'/'.$name);
        if (!rmdir($path)) throw new RuntimeException('Receipt test directory cleanup failed');
    } elseif (!unlink($path)) {
        throw new RuntimeException('Receipt test file cleanup failed');
    }
};
$counter = 0;
$newParent = static function () use ($temporary, &$counter): string {
    $parent = $temporary.'/case-'.++$counter;
    if (!mkdir($parent, 0700)) throw new RuntimeException('Synthetic output parent unavailable');
    return $parent;
};
$makeCommand = static fn (string $product): SiteProjectCommand => SiteProjectCommand::fromArray([
    'schemaVersion' => '1', 'operationId' => 'SYNTHETIC-OP-'.$product,
    'tenantRef' => 'SYNTHETIC-ORG', 'orderRef' => 'SYNTHETIC-ORDER-'.$product,
    'contractRef' => 'SYNTHETIC-CONTRACT', 'contractRevision' => 3,
    'productCode' => $product, 'idempotencyKey' => 'SYNTHETIC-KEY-'.$product,
]);
$nativeReceipt = static fn (SiteProjectCommand $command): array => [
    'schemaVersion' => 'sites.project-intake.receipt.v1',
    'tenantRef' => $command->tenantRef, 'orderRef' => $command->orderRef,
    'contractRef' => $command->contractRef, 'contractRevision' => $command->contractRevision,
    'productCode' => $command->productCode, 'operationId' => $command->operationId,
    'idempotencyKey' => $command->idempotencyKey,
    'siteRef' => '70000000-0000-4000-8000-000000000530',
    'commandDigest' => $command->semanticFingerprint(), 'status' => 'recorded',
    'state' => 'awaiting_official_authority',
    'authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false,
];
$reference = '80000000-0000-4000-8000-000000000530';
$opened = [];
try {
    $sentinel = $temporary.'/unrelated-sentinel';
    $newFile($sentinel, 'SYNTHETIC-UNRELATED-SENTINEL');
    $sentinelBefore = $snapshot($sentinel);
    foreach (['onepage', 'landing_page'] as $product) {
        $parent = $newParent();
        $command = $makeCommand($product);
        $payload = $nativeReceipt($command);
        $expectedBytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $beforeNames = scandir($parent);
        PrivateProjectIntakeReceipt::checkAvailable($parent, $reference);
        $ok(scandir($parent) === $beforeNames, 'availability creates and reserves nothing');
        $oldMask = umask(0000);
        try {
            $receipt = PrivateProjectIntakeReceipt::reserve($parent, $reference, $command);
            $restoredMask = umask();
        } finally { umask($oldMask); }
        $opened[] = $receipt;
        $path = $parent.'/'.$reference.'.json';
        $empty = $snapshot($path);
        $receipt->assertReserved();
        $ok($restoredMask === 0000 && $empty['bytes'] === '' && $empty['size'] === 0 &&
            ($empty['mode'] & 07777) === 0600 && $empty['nlink'] === 1 && $empty['uid'] === posix_geteuid(),
            'reservation is empty EUID0600 even under a permissive caller umask, then restores that umask');
        $denied(static fn () => PrivateProjectIntakeReceipt::reserve($parent, $reference, $command),
            'second reservation refuses the same UUID without overwrite');
        $denied(static fn () => PrivateProjectIntakeReceipt::checkAvailable($parent, $reference),
            'availability refuses an already reserved UUID');
        $ok($snapshot($path) === $empty, 'failed duplicate reservation leaves held file intact');
        $result = $receipt->write(array_reverse($payload, true));
        $written = $snapshot($path);
        $ok($result === ['receiptSha256' => hash('sha256', $expectedBytes), 'sizeBytes' => strlen($expectedBytes)] &&
            $written['bytes'] === $expectedBytes && $written['size'] === strlen($expectedBytes) &&
            json_decode($written['bytes'], true, 32, JSON_THROW_ON_ERROR) === $payload && count($payload) === 15,
            'write returns hash and size of the exact canonical native fifteen-field receipt plus LF');
        $ok($written['dev'] === $empty['dev'] && $written['ino'] === $empty['ino'] &&
            $written['uid'] === $empty['uid'] && $written['gid'] === $empty['gid'] &&
            $written['nlink'] === 1 && ($written['mode'] & 07777) === 0600,
            'completed receipt remains the original exclusive private inode');
        $denied(static fn () => $receipt->write($payload), 'a reservation permits only one successful write');
        $receipt->close();
        $ok($snapshot($path) === $written, 'refused second write and close preserve all completed bytes and metadata');
    }

    $command = $makeCommand('onepage');
    $payload = $nativeReceipt($command);
    foreach (['', '../outside', '/absolute', $reference.'.json', strtoupper(str_replace('80000000', 'abcdefab', $reference)),
        $reference."\n", $reference."\0", '80000000-0000-4000-8000-00000000053', 'not-a-uuid'] as $unsafe) {
        $beforeNames = scandir($temporary);
        $denied(static fn () => PrivateProjectIntakeReceipt::reserve($temporary, $unsafe, $command),
            'unsafe receipt selector is refused');
        $ok(scandir($temporary) === $beforeNames, 'unsafe selector creates no pathname');
    }

    foreach (['file', 'directory', 'symlink', 'dangling symlink', 'hardlink'] as $kind) {
        $parent = $newParent();
        $path = $parent.'/'.$reference.'.json';
        if ($kind === 'file') $newFile($path, 'EXISTING-PRIVATE-RECEIPT');
        elseif ($kind === 'directory') { if (!mkdir($path, 0700)) throw new RuntimeException('Synthetic directory unavailable'); }
        elseif ($kind === 'hardlink') {
            $newFile($path, 'EXISTING-LINKED-RECEIPT');
            if (!link($path, $parent.'/linked')) throw new RuntimeException('Synthetic hard link unavailable');
        } elseif (!symlink($kind === 'symlink' ? $sentinel : $parent.'/missing', $path)) {
            throw new RuntimeException('Synthetic symlink unavailable');
        }
        $before = $pathIdentity($path);
        $bytes = in_array($kind, ['file', 'hardlink'], true) ? file_get_contents($path) : null;
        $denied(static fn () => PrivateProjectIntakeReceipt::reserve($parent, $reference, $command),
            'existing '.$kind.' destination is refused');
        $after = $pathIdentity($path);
        $ok($before === $after && ($bytes === null || file_get_contents($path) === $bytes) &&
            $snapshot($sentinel) === $sentinelBefore, 'existing '.$kind.' and sentinel remain unchanged');
    }

    foreach ([0755, 0770, 01700] as $mode) {
        $parent = $newParent();
        if (!chmod($parent, $mode)) throw new RuntimeException('Synthetic parent mode change failed');
        $denied(static fn () => PrivateProjectIntakeReceipt::reserve($parent, $reference, $command),
            'nonprivate parent permissions are refused');
        clearstatcache(true, $parent);
        $ok(scandir($parent) === ['.', '..'] && (lstat($parent)['mode'] & 07777) === $mode,
            'parent refusal creates no receipt and does not repair permissions');
    }
    $canonical = $newParent();
    $alias = $temporary.'/parent-alias';
    if (!symlink($canonical, $alias)) throw new RuntimeException('Synthetic parent alias unavailable');
    foreach ([$canonical.'/', $canonical.'/.', $temporary.'//'.basename($canonical), $alias,
        $temporary.'/missing', $sentinel, 'relative'] as $parent) {
        $denied(static fn () => PrivateProjectIntakeReceipt::reserve($parent, $reference, $command),
            'noncanonical, missing, file or symlink parent is refused');
    }
    $unsafeParent = $newParent();
    if (!chmod($unsafeParent, 0777) || !mkdir($unsafeParent.'/private', 0700)) {
        throw new RuntimeException('Synthetic writable ancestor unavailable');
    }
    $denied(static fn () => PrivateProjectIntakeReceipt::reserve($unsafeParent.'/private', $reference, $command),
        'writable nonsticky ancestor is refused');
    $ok(scandir($canonical) === ['.', '..'] && scandir($unsafeParent.'/private') === ['.', '..'],
        'unsafe ancestry leaves its private children empty');

    foreach (['bytes', 'mode', 'hardlink', 'file replacement', 'symlink replacement', 'parent replacement', 'parent mode'] as $change) {
        $parent = $newParent();
        $path = $parent.'/'.$reference.'.json';
        $receipt = PrivateProjectIntakeReceipt::reserve($parent, $reference, $command);
        $opened[] = $receipt;
        $heldPath = null;
        if ($change === 'bytes') {
            if (file_put_contents($path, 'EXTERNAL-BYTES') !== strlen('EXTERNAL-BYTES')) {
                throw new RuntimeException('Synthetic byte change failed');
            }
        } elseif ($change === 'mode') {
            if (!chmod($path, 0400)) throw new RuntimeException('Synthetic file mode change failed');
        } elseif ($change === 'hardlink') {
            if (!link($path, $parent.'/linked')) throw new RuntimeException('Synthetic hard link failed');
        } elseif (in_array($change, ['file replacement', 'symlink replacement'], true)) {
            $heldPath = $parent.'/held-reservation';
            if (!rename($path, $heldPath)) throw new RuntimeException('Synthetic file move failed');
            if ($change === 'file replacement') $newFile($path, 'REPLACEMENT-SENTINEL');
            elseif (!symlink($sentinel, $path)) throw new RuntimeException('Synthetic replacement symlink failed');
        } elseif ($change === 'parent replacement') {
            if (!rename($parent, $parent.'-held') || !mkdir($parent, 0700)) {
                throw new RuntimeException('Synthetic parent move failed');
            }
            $heldPath = $parent.'-held/'.$reference.'.json';
            $newFile($path, 'REPLACEMENT-PARENT-SENTINEL');
        } elseif ($change === 'parent mode' && !chmod($parent, 0755)) {
            throw new RuntimeException('Synthetic parent mode change failed');
        }
        $beforeLink = $change === 'symlink replacement' ? $pathIdentity($path) : null;
        $before = $beforeLink === null ? $snapshot($path) : null;
        $held = $heldPath === null ? null : $snapshot($heldPath);
        $denied(static fn () => $receipt->assertReserved(), 'observed '.$change.' fails before SQL');
        $denied(static fn () => $receipt->write($payload), 'observed '.$change.' also refuses receipt persistence');
        $receipt->close();
        $ok(($before === null ? $pathIdentity($path) === $beforeLink : $snapshot($path) === $before) &&
            ($held === null || $snapshot($heldPath) === $held) && $snapshot($sentinel) === $sentinelBefore,
            'refusal preserves the replacement, original held inode and unrelated sentinel');
    }

    $badReceipts = [
        [], $payload + ['canAcceptRealOrders' => false],
        array_diff_key($payload, ['orderRef' => true]),
        array_replace($payload, ['schemaVersion' => '1']),
        array_replace($payload, ['tenantRef' => 'ANOTHER-ORG']),
        array_replace($payload, ['orderRef' => 'ANOTHER-ORDER']),
        array_replace($payload, ['contractRef' => 'ANOTHER-CONTRACT']),
        array_replace($payload, ['contractRevision' => '3']),
        array_replace($payload, ['productCode' => 'landing_page']),
        // The semantic command fingerprint deliberately excludes these two
        // identities. Equality of its digest alone cannot validate the receipt.
        array_replace($payload, ['operationId' => 'ANOTHER-OPERATION']),
        array_replace($payload, ['idempotencyKey' => 'ANOTHER-KEY']),
        array_replace($payload, ['siteRef' => 'not-a-uuid']),
        array_replace($payload, ['commandDigest' => str_repeat('a', 64)]),
        array_replace($payload, ['status' => 'created']),
        array_replace($payload, ['state' => 'approved']),
        array_replace($payload, ['authorityVerified' => true]),
        array_replace($payload, ['clientAccepted' => 0]),
        array_replace($payload, ['canPublish' => true]),
    ];
    foreach ($badReceipts as $bad) {
        $parent = $newParent();
        $receipt = PrivateProjectIntakeReceipt::reserve($parent, $reference, $command);
        $opened[] = $receipt;
        $path = $parent.'/'.$reference.'.json';
        $before = $snapshot($path);
        $denied(static fn () => $receipt->write($bad), 'non-native or command-mismatched receipt is refused');
        $receipt->close();
        $ok($snapshot($path) === $before, 'invalid receipt preserves the empty reserved file');
    }

    $parent = $newParent();
    $receipt = PrivateProjectIntakeReceipt::reserve($parent, $reference, $command);
    $before = $snapshot($parent.'/'.$reference.'.json');
    $receipt->close();
    $denied(static fn () => $receipt->assertReserved(), 'closed reservation cannot be reused');
    $ok($snapshot($parent.'/'.$reference.'.json') === $before, 'closing an unused reservation does not remove it');
    $parent = $newParent();
    $receipt = PrivateProjectIntakeReceipt::reserve($parent, $reference, $command);
    $before = $snapshot($parent.'/'.$reference.'.json');
    unset($receipt);
    $ok($snapshot($parent.'/'.$reference.'.json') === $before, 'destruction closes the FD without deleting the reservation');
    $ok($snapshot($sentinel) === $sentinelBefore, 'the complete IO suite preserves its unrelated sentinel');
} finally {
    foreach ($opened as $receipt) $receipt->close();
    clearstatcache(true, $temporary);
    $current = lstat($temporary);
    if (!is_array($current) || $current['dev'] !== $temporaryIdentity['dev'] ||
        $current['ino'] !== $temporaryIdentity['ino'] || $current['uid'] !== posix_geteuid() ||
        ($current['mode'] & 07777) !== 0700) {
        throw new RuntimeException('Receipt test root identity changed');
    }
    $removeOwnTree($temporary);
}

echo "PROJECT INTAKE RECEIPT OFFLINE PASS: {$checks} checks; private temporary IO only, no SQL or authority simulated.\n";
