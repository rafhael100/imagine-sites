<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use DomainException;
use Throwable;

/** Local private files only. No URLs, archive names or caller-selected filenames. */
final class SiteReleasePreparationFiles
{
    private const NAMES = ['index.html', 'release-manifest.json', 'readiness.json'];

    public static function read(string $path, int $maximum, int $minimum = 1): string
    {
        $stream = null;
        try {
            if ($minimum < 1 || $maximum < $minimum || $maximum > 150000) throw new DomainException();
            $before = self::regularFile($path);
            if ($before['size'] < $minimum || $before['size'] > $maximum) throw new DomainException();
            $stream = @fopen($path, 'rb');
            if ($stream === false || !self::same($before, fstat($stream))) throw new DomainException();
            $bytes = stream_get_contents($stream, $maximum + 1);
            $after = fstat($stream);
            if (!is_string($bytes) || strlen($bytes) !== $before['size'] ||
                !self::same($before, $after) || !self::same($before, self::regularFile($path))) {
                throw new DomainException();
            }
            return $bytes;
        } catch (Throwable) {
            throw new DomainException('Release preparation file unavailable.');
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
    }

    /** Read an existing complete package; never create, repair or normalize it. */
    public static function readPackage(string $parent, string $reference): array
    {
        try {
            $parentStat = self::privateDirectory($parent);
            if (!SiteReleasePreparationInput::packageRef($reference)) throw new DomainException();
            $target = $parent.'/'.$reference;
            $targetStat = self::privateDirectory($target);
            $expected = self::NAMES;
            sort($expected);
            $names = array_values(array_diff(scandir($target), ['.', '..']));
            sort($names);
            if ($names !== $expected) throw new DomainException();
            $files = [];
            foreach (self::NAMES as $name) $files[$name] = self::read($target.'/'.$name, 150000);
            $afterNames = array_values(array_diff(scandir($target), ['.', '..']));
            sort($afterNames);
            if ($afterNames !== $expected ||
                !self::sameDirectory($parentStat, self::privateDirectory($parent)) ||
                !self::sameDirectory($targetStat, self::privateDirectory($target))) throw new DomainException();
            return $files;
        } catch (Throwable) {
            throw new DomainException('Release preparation file unavailable.');
        }
    }

    /**
     * Stage all three files, reserve a new directory exclusively, then rename
     * the complete tree over that own empty reservation. Existing output is
     * never replaced. A crash can leave an empty reservation, never a package
     * that the caller should regard as complete without its three files.
     *
     * @param array{files:array<string,string>,artifactDigest:string,packageDigest:string,bindingRevision:int,editorialRevision:int} $package
     */
    public static function write(string $parent, string $reference, array $package): string
    {
        $stage = null;
        $reservation = null;
        $stageStat = null;
        $reservationStat = null;
        $oldMask = umask(0077);
        try {
            $parentStat = self::privateDirectory($parent);
            if (!SiteReleasePreparationInput::packageRef($reference) ||
                !is_array($package['files'] ?? null) || array_keys($package['files']) !== self::NAMES) {
                throw new DomainException();
            }
            foreach ($package['files'] as $bytes) {
                if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 150000) throw new DomainException();
            }
            if (($package['artifactDigest'] ?? null) !== hash('sha256', $package['files']['index.html']) ||
                ($package['packageDigest'] ?? null) !== hash('sha256', implode("\0", $package['files']))) {
                throw new DomainException();
            }
            $target = $parent.'/'.$reference;
            if (file_exists($target) || is_link($target)) throw new DomainException();
            $stage = $parent.'/.preparing-'.bin2hex(random_bytes(16));
            if (!@mkdir($stage, 0700)) throw new DomainException();
            $stageStat = self::privateDirectory($stage);
            foreach ($package['files'] as $name => $bytes) {
                $file = $stage.'/'.$name;
                $stream = @fopen($file, 'x+b');
                if ($stream === false) throw new DomainException();
                try {
                    if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream) ||
                        !rewind($stream) || stream_get_contents($stream) !== $bytes) throw new DomainException();
                } finally {
                    fclose($stream);
                }
                self::regularFile($file);
            }
            if (!self::sameDirectory($parentStat, self::privateDirectory($parent)) ||
                !@mkdir($target, 0700)) throw new DomainException();
            $reservation = $target;
            $reservationStat = self::privateDirectory($target);
            if (!self::sameDirectory($stageStat, self::privateDirectory($stage)) ||
                !self::sameDirectory($reservationStat, self::privateDirectory($target)) ||
                !@rename($stage, $target)) throw new DomainException();
            $stage = null;
            $reservation = null;
            return $target;
        } catch (Throwable) {
            throw new DomainException('Release preparation output unavailable.');
        } finally {
            if ($stage !== null && $stageStat !== null) self::removeOwnStage($stage, $stageStat);
            if ($reservation !== null && $reservationStat !== null) {
                clearstatcache(true, $reservation);
                $current = @lstat($reservation);
                if (is_array($current) && self::sameDirectory($reservationStat, $current)) @rmdir($reservation);
            }
            umask($oldMask);
        }
    }

    private static function regularFile(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!function_exists('posix_geteuid') || !str_starts_with($path, '/') || realpath($path) !== $path ||
            !is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 ||
            !in_array($stat['mode'] & 0777, [0400, 0600], true) ||
            !in_array($stat['uid'], [0, posix_geteuid()], true)) throw new DomainException();
        return $stat;
    }

    private static function privateDirectory(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!function_exists('posix_geteuid') || !str_starts_with($path, '/') || realpath($path) !== $path ||
            !is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 ||
            ($stat['mode'] & 0777) !== 0700 || $stat['uid'] !== posix_geteuid()) throw new DomainException();
        return $stat;
    }

    private static function same(array $before, mixed $after): bool
    {
        if (!is_array($after)) return false;
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
            if ($before[$key] !== $after[$key]) return false;
        }
        return true;
    }

    private static function sameDirectory(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'gid'] as $key) {
            if ($before[$key] !== $after[$key]) return false;
        }
        return true;
    }

    private static function removeOwnStage(string $path, array $before): void
    {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (!is_array($current) || !self::sameDirectory($before, $current)) return;
        foreach (self::NAMES as $name) {
            $file = $path.'/'.$name;
            $stat = @lstat($file);
            if (is_array($stat) && ($stat['mode'] & 0170000) === 0100000 &&
                $stat['uid'] === $before['uid'] && $stat['nlink'] === 1) @unlink($file);
        }
        @rmdir($path);
    }
}
