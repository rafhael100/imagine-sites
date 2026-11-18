<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use RuntimeException;
use Throwable;

/**
 * One private receipt reservation, held across the separate journal call.
 * There is no atomic DB/file transaction, pathname guarantee or cleanup here.
 */
final class PrivatePublicationRequestReceipt
{
    private const FILE_IDENTITY = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'];
    private const DIRECTORY_IDENTITY = ['dev', 'ino', 'mode', 'uid', 'gid'];
    private bool $writeAttempted = false;

    /** @param resource $stream */
    private function __construct(
        private readonly string $parent,
        private readonly string $path,
        private readonly string $planSha256,
        private readonly array $ancestors,
        private readonly array $identity,
        private mixed $stream,
    ) {}

    /** Read-only availability check; it creates and reserves nothing. */
    public static function checkAvailable(string $parent, string $receiptRef): void
    {
        try { self::available($parent, $receiptRef); }
        catch (Throwable) { throw new RuntimeException('PUBLICATION_REQUEST_DENIED'); }
    }

    /** Reserve a new empty 0600 file before any mutating journal API call. */
    public static function reserve(string $parent, string $receiptRef, string $planSha256): self
    {
        $stream = null;
        try {
            if (!PgPublicationJournalGuard::digest($planSha256)) throw new RuntimeException();
            [$path, $ancestors] = self::available($parent, $receiptRef);
            $oldMask = umask(0077);
            try { $stream = @fopen($path, 'x+b'); }
            finally { umask($oldMask); }
            if (!is_resource($stream)) throw new RuntimeException();
            $identity = self::opened($path, $stream);
            if ($identity['size'] !== 0 || !fflush($stream) || !fsync($stream) ||
                $identity !== self::opened($path, $stream) || $ancestors !== self::directories($parent)) {
                throw new RuntimeException();
            }
            $reservation = new self($parent, $path, $planSha256, $ancestors, $identity, $stream);
            $stream = null;
            return $reservation;
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_REQUEST_DENIED');
        } finally {
            // Never unlink a reservation, even when setup has already failed.
            if (is_resource($stream)) @fclose($stream);
        }
    }

    /** Recheck the retained empty FD and path immediately before SQL. */
    public function assertReserved(): void
    {
        try {
            if ($this->writeAttempted || !is_resource($this->stream) ||
                $this->identity !== self::opened($this->path, $this->stream) ||
                $this->ancestors !== self::directories($this->parent)) throw new RuntimeException();
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_REQUEST_DENIED');
        }
    }

    /** Write once to the retained reservation; failure may leave partial bytes. */
    public function write(array $receipt): void
    {
        try {
            $this->assertReserved();
            $this->writeAttempted = true;
            $receipt = $this->validatedReceipt($receipt);
            $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            if (strlen($bytes) > 4096) throw new RuntimeException();
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($this->stream, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) throw new RuntimeException();
                $offset += $written;
            }
            if (!fflush($this->stream) || !fsync($this->stream)) throw new RuntimeException();
            $completed = self::opened($this->path, $this->stream);
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $field) {
                if ($completed[$field] !== $this->identity[$field]) throw new RuntimeException();
            }
            if ($completed['size'] !== strlen($bytes) || !rewind($this->stream) ||
                stream_get_contents($this->stream, strlen($bytes) + 1) !== $bytes ||
                $completed !== self::opened($this->path, $this->stream) ||
                $this->ancestors !== self::directories($this->parent)) throw new RuntimeException();
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_REQUEST_DENIED');
        }
    }

    /** Closing the FD never removes or repairs the reserved pathname. */
    public function close(): void
    {
        try {
            if (is_resource($this->stream) && !fclose($this->stream)) throw new RuntimeException();
            $this->stream = null;
        } catch (Throwable) {
            throw new RuntimeException('PUBLICATION_REQUEST_DENIED');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) @fclose($this->stream);
    }

    private function validatedReceipt(array $receipt): array
    {
        $keys = ['schema', 'planSha256', 'operationRef'];
        if (count($receipt) !== count($keys) || array_diff($keys, array_keys($receipt)) ||
            $receipt['schema'] !== 'SITES_PUBLICATION_REQUEST_RECEIPT_V1' ||
            !is_string($receipt['operationRef']) || !PgPublicationJournalGuard::uuid($receipt['operationRef']) ||
            !is_string($receipt['planSha256']) || !hash_equals($this->planSha256, $receipt['planSha256'])) {
            throw new RuntimeException();
        }
        return array_replace(array_fill_keys($keys, null), $receipt);
    }

    /** @return array{string,array} */
    private static function available(string $parent, string $receiptRef): array
    {
        if (!SiteReleasePreparationInput::packageRef($receiptRef)) throw new RuntimeException();
        $ancestors = self::directories($parent);
        $identity = $ancestors[$parent];
        if ($identity['uid'] !== posix_geteuid() || ($identity['mode'] & 07777) !== 0700) throw new RuntimeException();
        $path = rtrim($parent, '/').'/'.$receiptRef.'.json';
        clearstatcache(true, $path);
        if (@lstat($path) !== false || file_exists($path) || is_link($path)) throw new RuntimeException();
        return [$path, $ancestors];
    }

    /** Refuse observed symlink components; these checks are not atomic traversal. */
    private static function directories(string $parent): array
    {
        if (!function_exists('posix_geteuid') || !str_starts_with($parent, '/') || strlen($parent) > 4096 ||
            preg_match('/[\x00-\x1f\x7f]/', $parent) !== 0) throw new RuntimeException();
        clearstatcache(true);
        if (realpath($parent) !== $parent) throw new RuntimeException();
        $paths = ['/'];
        $current = '';
        foreach ($parent === '/' ? [] : explode('/', substr($parent, 1)) as $part) {
            $current .= '/'.$part;
            $paths[] = $current;
        }
        $identities = [];
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 ||
                !in_array($stat['uid'], [0, posix_geteuid()], true) || realpath($path) !== $path ||
                (($stat['mode'] & 0022) !== 0 && !($stat['uid'] === 0 && ($stat['mode'] & 01000) !== 0))) {
                throw new RuntimeException();
            }
            $identities[$path] = self::identity($stat, self::DIRECTORY_IDENTITY);
        }
        return $identities;
    }

    /** @param resource $stream */
    private static function opened(string $path, $stream): array
    {
        clearstatcache(true);
        $stat = @lstat($path);
        $opened = fstat($stream);
        if (!is_array($stat) || !is_array($opened) || ($stat['mode'] & 0170000) !== 0100000 ||
            ($stat['mode'] & 07777) !== 0600 || $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid() ||
            realpath($path) !== $path || self::identity($stat, self::FILE_IDENTITY) !== self::identity($opened, self::FILE_IDENTITY)) {
            throw new RuntimeException();
        }
        return self::identity($opened, self::FILE_IDENTITY);
    }

    private static function identity(array $stat, array $fields): array
    {
        $identity = [];
        foreach ($fields as $field) $identity[$field] = $stat[$field];
        return $identity;
    }
}
