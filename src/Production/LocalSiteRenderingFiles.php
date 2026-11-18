<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use Imagine\Sites\Publication\SiteReleasePreparationFiles;
use RuntimeException;
use Throwable;

/** Observed local custody; PHP pathname checks are not an atomic openat walk. */
final class LocalSiteRenderingFiles
{
    private const FILE_IDENTITY = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'];
    private const DIRECTORY_IDENTITY = ['dev', 'ino', 'mode', 'uid', 'gid'];

    /** @return array{bytes:string,sha256:string,identity:array,ancestors:array} */
    public static function readBrief(string $path): array
    {
        try {
            self::path($path);
            $ancestors = self::directories(dirname($path));
            $before = self::file($path);
            $bytes = SiteReleasePreparationFiles::read($path, LocalSiteRendering::MAX_INPUT_BYTES);
            if ($before !== self::file($path) || $ancestors !== self::directories(dirname($path))) {
                throw new RuntimeException();
            }
            return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes),
                'identity' => $before, 'ancestors' => $ancestors];
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }

    /**
     * Create one new index.html. Existing destinations are never changed or
     * removed. Failure after exclusive creation can leave our partial file.
     *
     * @param array{bytes:string,sha256:string,identity:array,ancestors:array} $brief
     */
    public static function write(string $output, ReleaseArtifact $artifact, string $briefPath, array $brief): void
    {
        $stream = null;
        try {
            self::path($output);
            $parent = dirname($output);
            if ($output !== rtrim($parent, '/').'/index.html') throw new RuntimeException();
            $ancestors = self::directories($parent);
            $parentIdentity = $ancestors[$parent];
            if ($parentIdentity['uid'] !== posix_geteuid() || ($parentIdentity['mode'] & 07777) !== 0700) {
                throw new RuntimeException();
            }
            clearstatcache(true, $output);
            if (@lstat($output) !== false || file_exists($output) || is_link($output)) throw new RuntimeException();

            // Render may take time. Pin both exact bytes and observed custody
            // again before creating the output; never repair either input.
            if (self::readBrief($briefPath) !== $brief || $ancestors !== self::directories($parent)) {
                throw new RuntimeException();
            }
            $oldMask = umask(0077);
            try {
                $stream = @fopen($output, 'x+b');
            } finally {
                umask($oldMask);
            }
            if (!is_resource($stream)) throw new RuntimeException();
            $created = self::openedOutput($output, $stream);
            if ($created['size'] !== 0 || $ancestors !== self::directories($parent)) throw new RuntimeException();

            $offset = 0;
            while ($offset < $artifact->sizeBytes) {
                $written = fwrite($stream, substr($artifact->html, $offset));
                if (!is_int($written) || $written < 1) throw new RuntimeException();
                $offset += $written;
            }
            if (!fflush($stream) || !fsync($stream)) throw new RuntimeException();
            $completed = self::openedOutput($output, $stream);
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $field) {
                if ($created[$field] !== $completed[$field]) throw new RuntimeException();
            }
            if ($completed['size'] !== $artifact->sizeBytes || !rewind($stream) ||
                stream_get_contents($stream, $artifact->sizeBytes + 1) !== $artifact->html ||
                $completed !== self::openedOutput($output, $stream) ||
                $ancestors !== self::directories($parent)) {
                throw new RuntimeException();
            }
            if (!fclose($stream)) throw new RuntimeException();
            $stream = null;
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        } finally {
            if (is_resource($stream)) @fclose($stream);
        }
    }

    private static function path(string $path): void
    {
        if (!function_exists('posix_geteuid') || !str_starts_with($path, '/') || strlen($path) > 4096 ||
            preg_match('/[\x00-\x1f\x7f]/', $path) !== 0) throw new RuntimeException();
    }

    /** Check every existing ancestor without following a symlink component. */
    private static function directories(string $path): array
    {
        self::path($path);
        clearstatcache(true);
        if (realpath($path) !== $path) throw new RuntimeException();
        $paths = ['/'];
        $current = '';
        foreach ($path === '/' ? [] : explode('/', substr($path, 1)) as $part) {
            $current .= '/'.$part;
            $paths[] = $current;
        }
        $identities = [];
        foreach ($paths as $directory) {
            clearstatcache(true, $directory);
            $stat = @lstat($directory);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 ||
                !in_array($stat['uid'], [0, posix_geteuid()], true) || realpath($directory) !== $directory ||
                (($stat['mode'] & 0022) !== 0 && !($stat['uid'] === 0 && ($stat['mode'] & 01000) !== 0))) {
                throw new RuntimeException();
            }
            $identities[$directory] = self::identity($stat, self::DIRECTORY_IDENTITY);
        }
        return $identities;
    }

    private static function file(string $path, bool $output = false): array
    {
        clearstatcache(true);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 ||
            !in_array($stat['mode'] & 07777, $output ? [0600] : [0400, 0600], true) ||
            !in_array($stat['uid'], $output ? [posix_geteuid()] : [0, posix_geteuid()], true) ||
            realpath($path) !== $path) throw new RuntimeException();
        return self::identity($stat, self::FILE_IDENTITY);
    }

    /** @param resource $stream */
    private static function openedOutput(string $path, $stream): array
    {
        $stat = fstat($stream);
        if (!is_array($stat)) throw new RuntimeException();
        $identity = self::identity($stat, self::FILE_IDENTITY);
        if ($identity !== self::file($path, true)) throw new RuntimeException();
        return $identity;
    }

    private static function identity(array $stat, array $fields): array
    {
        $identity = [];
        foreach ($fields as $field) $identity[$field] = $stat[$field];
        return $identity;
    }
}
