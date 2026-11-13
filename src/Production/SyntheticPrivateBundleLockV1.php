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
    /** @param resource $handle */
    private function __construct(private mixed $handle) {}

    public static function acquire(
        string $privateDirectory,
        string $product,
        bool $mayCreateLock = false,
    ): ?self {
        if (!in_array($product, ['onepage', 'landing_page'], true) ||
            is_link($privateDirectory) || !is_dir($privateDirectory)) {
            return null;
        }

        $path=$privateDirectory.'/.sites-f1-24-'.$product.'.lock';
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
        $named=@lstat($path);
        if (!is_array($opened) || !is_array($named) ||
            (($opened['mode'] & 0170000) !== 0100000) ||
            ($opened['nlink'] ?? 0) !== 1 ||
            $opened['ino'] !== $named['ino'] ||
            $opened['dev'] !== $named['dev'] ||
            is_link($path) ||
            !@flock($handle,LOCK_EX|LOCK_NB)) {
            @fclose($handle);
            return null;
        }
        if ($mayCreateLock) {
            // Empty fixed-name local file only, never a secret or client ID.
            @chmod($path,0600);
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
