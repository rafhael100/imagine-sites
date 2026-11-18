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
    private const PROVENANCE_INPUT_LIMITS = [
        'plan' => 32768, 'snapshot' => 32768, 'source' => 150000, 'editorial' => 32768,
    ];

    /** @return array{bytes:string,sha256:string,identity:array,ancestors:array} */
    public static function readBrief(string $path): array
    {
        return self::readInput($path, LocalSiteRendering::MAX_INPUT_BYTES);
    }

    /**
     * @param array{plan:string,snapshot:string,source:string,editorial:string} $paths
     * @return array<string,array{bytes:string,sha256:string,identity:array,ancestors:array}>
     */
    public static function readProvenanceInputs(array $paths): array
    {
        try {
            self::provenancePaths($paths);
            $inputs = [];
            foreach (self::PROVENANCE_INPUT_LIMITS as $name => $maximum) {
                $inputs[$name] = self::readInput($paths[$name], $maximum);
            }
            return $inputs;
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }

    /** @return array{bytes:string,sha256:string,identity:array,ancestors:array} */
    private static function readInput(string $path, int $maximum): array
    {
        try {
            self::path($path);
            $ancestors = self::directories(dirname($path));
            $before = self::file($path);
            $bytes = SiteReleasePreparationFiles::read($path, $maximum);
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
        self::writeFiles($output, ['index.html' => $artifact->html], [
            ['path' => $briefPath, 'maximum' => LocalSiteRendering::MAX_INPUT_BYTES, 'snapshot' => $brief],
        ]);
    }

    /**
     * Create index.html and render-provenance.json in the same private parent.
     * Both descriptors remain open until all bytes and identities are checked.
     * The pair is not atomic: a refusal can leave empty, partial or complete
     * newly created files. No output is repaired, replaced or removed.
     *
     * @param array{plan:string,snapshot:string,source:string,editorial:string} $paths
     * @param array<string,array{bytes:string,sha256:string,identity:array,ancestors:array}> $inputs
     */
    public static function writeWithProvenance(
        string $output,
        ReleaseArtifact $artifact,
        string $provenance,
        array $paths,
        array $inputs,
    ): void {
        try {
            self::provenancePaths($paths);
            if (count($inputs) !== count(self::PROVENANCE_INPUT_LIMITS) ||
                array_diff(array_keys(self::PROVENANCE_INPUT_LIMITS), array_keys($inputs)) ||
                $provenance === '' || strlen($provenance) > 32768) {
                throw new RuntimeException();
            }
            $pinned = [];
            foreach (self::PROVENANCE_INPUT_LIMITS as $name => $maximum) {
                if (!is_array($inputs[$name])) throw new RuntimeException();
                $pinned[] = ['path' => $paths[$name], 'maximum' => $maximum, 'snapshot' => $inputs[$name]];
            }
            self::writeFiles($output, [
                'index.html' => $artifact->html, 'render-provenance.json' => $provenance,
            ], $pinned);
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }

    /** @param array{plan:string,snapshot:string,source:string,editorial:string} $paths */
    private static function provenancePaths(array $paths): void
    {
        if (count($paths) !== count(self::PROVENANCE_INPUT_LIMITS) ||
            array_diff(array_keys(self::PROVENANCE_INPUT_LIMITS), array_keys($paths))) {
            throw new RuntimeException();
        }
        $seen = [];
        foreach (self::PROVENANCE_INPUT_LIMITS as $name => $_maximum) {
            if (!is_string($paths[$name]) || isset($seen[$paths[$name]])) throw new RuntimeException();
            self::path($paths[$name]);
            $seen[$paths[$name]] = true;
        }
    }

    /**
     * @param array<string,string> $files Internal fixed output names and bytes.
     * @param list<array{path:string,maximum:int,snapshot:array}> $inputs
     */
    private static function writeFiles(string $output, array $files, array $inputs): void
    {
        $streams = [];
        try {
            self::path($output);
            $parent = dirname($output);
            if ($output !== rtrim($parent, '/').'/index.html') throw new RuntimeException();
            $ancestors = self::directories($parent);
            $parentIdentity = $ancestors[$parent];
            if ($parentIdentity['uid'] !== posix_geteuid() || ($parentIdentity['mode'] & 07777) !== 0700) {
                throw new RuntimeException();
            }
            $paths = [];
            foreach ($files as $name => $_bytes) {
                $path = $parent.'/'.$name;
                clearstatcache(true, $path);
                if (@lstat($path) !== false || file_exists($path) || is_link($path)) throw new RuntimeException();
                $paths[$name] = $path;
            }

            // The renderer uses pinned bytes. Recheck every selected input,
            // including its ancestry, before reserving any output pathname.
            self::assertInputs($inputs);
            if ($ancestors !== self::directories($parent)) throw new RuntimeException();
            $created = [];
            foreach ($paths as $name => $path) {
                $oldMask = umask(0077);
                try {
                    $stream = @fopen($path, 'x+b');
                } finally {
                    umask($oldMask);
                }
                if (!is_resource($stream)) throw new RuntimeException();
                $streams[$name] = $stream;
                $created[$name] = self::openedOutput($path, $stream);
                if ($created[$name]['size'] !== 0 || $ancestors !== self::directories($parent)) {
                    throw new RuntimeException();
                }
            }

            // Reserve the entire output set before writing either file. Keep
            // both descriptors, and detect observed replacements without ever
            // opening a destination again for writing.
            self::assertInputs($inputs);
            if ($ancestors !== self::directories($parent)) throw new RuntimeException();
            foreach ($paths as $name => $path) {
                if ($created[$name] !== self::openedOutput($path, $streams[$name])) throw new RuntimeException();
            }
            foreach ($files as $name => $bytes) {
                $stream = $streams[$name];
                if ($created[$name] !== self::openedOutput($paths[$name], $stream)) throw new RuntimeException();
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($stream, substr($bytes, $offset));
                    if (!is_int($written) || $written < 1) throw new RuntimeException();
                    $offset += $written;
                }
                if (!fflush($stream) || !fsync($stream)) throw new RuntimeException();
                self::verifyWritten($paths[$name], $stream, $bytes, $created[$name]);
            }

            self::assertInputs($inputs);
            foreach ($files as $name => $bytes) {
                self::verifyWritten($paths[$name], $streams[$name], $bytes, $created[$name]);
            }
            if ($ancestors !== self::directories($parent)) throw new RuntimeException();
            foreach ($streams as $name => $stream) {
                if (!fclose($stream)) throw new RuntimeException();
                unset($streams[$name]);
            }
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        } finally {
            foreach ($streams as $stream) if (is_resource($stream)) @fclose($stream);
        }
    }

    /** @param list<array{path:string,maximum:int,snapshot:array}> $inputs */
    private static function assertInputs(array $inputs): void
    {
        foreach ($inputs as $input) {
            if (self::readInput($input['path'], $input['maximum']) !== $input['snapshot']) {
                throw new RuntimeException();
            }
        }
    }

    /** @param resource $stream */
    private static function verifyWritten(string $path, $stream, string $bytes, array $created): void
    {
        $completed = self::openedOutput($path, $stream);
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $field) {
            if ($created[$field] !== $completed[$field]) throw new RuntimeException();
        }
        if ($completed['size'] !== strlen($bytes) || !rewind($stream) ||
            stream_get_contents($stream, strlen($bytes) + 1) !== $bytes ||
            $completed !== self::openedOutput($path, $stream)) {
            throw new RuntimeException();
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
