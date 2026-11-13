<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

/**
 * F1-300: cooperative, nonblocking per-product flock for PRIVATE fictional
 * package writer/readback processes. It does not authenticate a Portal
 * release, secure hostile same-UID processes or grant publication rights.
 *
 * The lock file is intentionally retained: unlinking a live flock file
 * creates separate inodes and defeats cross-process mutual exclusion.
 */
final class SyntheticPrivateBundleLockV1
{
    /** @param mixed $opened @param mixed $named */
    private static function sameEmptyLockFile(mixed $opened,mixed $named):bool
    {
        return is_array($opened) && is_array($named) &&
            (($opened['mode'] & 0170000) === 0100000) &&
            (($named['mode'] & 0170000) === 0100000) &&
            (($opened['nlink'] ?? 0) === 1) &&
            (($named['nlink'] ?? 0) === 1) &&
            $opened['dev'] === $named['dev'] &&
            $opened['ino'] === $named['ino'] &&
            $opened['size'] === 0 && $named['size'] === 0;
    }

    /** @param resource $handle */
    private function __construct(private mixed $handle) {}

    public static function acquire(
        string $privateDirectory,
        string $product,
        bool $mayCreateLock = false,
    ): ?self {
        if (!in_array($product, ['onepage', 'landing_page'], true) ||
            str_contains($privateDirectory, chr(0)) ||
            is_link($privateDirectory) || !is_dir($privateDirectory)) {
            return null;
        }

        $path=$privateDirectory.'/.sites-f1-24-'.$product.'.lock';
        clearstatcache(true,$path);
        if (is_link($path) ||
            (file_exists($path) && !is_file($path)) ||
            (!$mayCreateLock && !is_file($path))) {
            return null;
        }

        $handle=@fopen($path,$mayCreateLock?'c':'r');
        if (!is_resource($handle)) return null;

        // Validate opened inode rather than trusting only a prior path
        // check; fail closed on a swapped file, symlink or hardlink.
        $opened=@fstat($handle);
        clearstatcache(true,$path);
        $named=@lstat($path);
        if (!self::sameEmptyLockFile($opened,$named) ||
            is_link($path) ||
            !@flock($handle,LOCK_EX|LOCK_NB)) {
            @fclose($handle);
            return null;
        }
        if ($mayCreateLock && !@chmod($path,0600)) {
            @flock($handle,LOCK_UN);
            @fclose($handle);
            return null;
        }
        // F1-303: recheck the same empty, private inode AFTER exclusive
        // lock acquisition and optional chmod. A replaced path, symlink,
        // hardlink or a nonprivate lock file must never be accepted.
        $locked=@fstat($handle);
        clearstatcache(true,$path);
        $current=@lstat($path);
        if (!self::sameEmptyLockFile($locked,$current) ||
            !self::sameEmptyLockFile($locked,$opened) ||
            is_link($path) || (($locked['mode'] & 0777) !== 0600)) {
            @flock($handle,LOCK_UN);
            @fclose($handle);
            return null;
        }
        return new self($handle);
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) return;
        @flock($this->handle,LOCK_UN);
        @fclose($this->handle);
        $this->handle=null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
