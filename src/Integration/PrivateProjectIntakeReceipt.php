<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use Throwable;

/**
 * Holds one exclusive private receipt FD across the native intake transaction.
 * SQL and this file are not atomic; observed pathname checks are not openat.
 * A failure may leave an empty or partial file. Nothing here removes it.
 */
final class PrivateProjectIntakeReceipt
{
    private const FILE_IDENTITY = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'];
    private const DIRECTORY_IDENTITY = ['dev', 'ino', 'mode', 'uid', 'gid'];
    private bool $writeAttempted = false;

    /** @param resource $stream */
    private function __construct(
        private readonly string $parent,
        private readonly string $path,
        private readonly SiteProjectCommand $command,
        private readonly array $ancestors,
        private readonly array $identity,
        private mixed $stream,
    ) {}

    /** Read-only availability check; it reserves neither a name nor authority. */
    public static function checkAvailable(string $parent, string $receiptRef): void
    {
        try { self::available($parent, $receiptRef); }
        catch (Throwable) { throw new RuntimeException('PROJECT_INTAKE_RECEIVE_DENIED'); }
    }

    /** Reserve an empty 0600 file before the one native SQL call. */
    public static function reserve(string $parent, string $receiptRef, SiteProjectCommand $command): self
    {
        $stream = null;
        try {
            BriefCandidateJson::integer($command->contractRevision, 1, 2147483647);
            [$path, $ancestors] = self::available($parent, $receiptRef);
            $oldMask = umask(0077);
            try { $stream = @fopen($path, 'x+b'); }
            finally { umask($oldMask); }
            if (!is_resource($stream)) throw new RuntimeException();
            $identity = self::opened($path, $stream);
            if ($identity['size'] !== 0 || !@fflush($stream) || !@fsync($stream) ||
                $identity !== self::opened($path, $stream) || $ancestors !== self::directories($parent)) {
                throw new RuntimeException();
            }
            $reservation = new self($parent, $path, $command, $ancestors, $identity, $stream);
            $stream = null;
            return $reservation;
        } catch (Throwable) {
            throw new RuntimeException('PROJECT_INTAKE_RECEIVE_DENIED');
        } finally {
            // Closing an unsuccessfully prepared reservation never unlinks it.
            if (is_resource($stream)) @fclose($stream);
        }
    }

    /** Recheck the retained empty FD, name and ancestors immediately before SQL. */
    public function assertReserved(): void
    {
        try {
            if ($this->writeAttempted || !is_resource($this->stream) ||
                $this->identity !== self::opened($this->path, $this->stream) ||
                $this->ancestors !== self::directories($this->parent)) throw new RuntimeException();
        } catch (Throwable) {
            throw new RuntimeException('PROJECT_INTAKE_RECEIVE_DENIED');
        }
    }

    /**
     * Persist only the exact native fifteen-field receipt for this command.
     * @return array{receiptSha256:string,sizeBytes:int}
     */
    public function write(array $receipt): array
    {
        try {
            $this->assertReserved();
            $this->writeAttempted = true;
            $receipt = $this->validatedReceipt($receipt);
            $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $size = strlen($bytes);
            if ($size > 4096) throw new RuntimeException();
            $offset = 0;
            while ($offset < $size) {
                $written = @fwrite($this->stream, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) throw new RuntimeException();
                $offset += $written;
            }
            if (!@fflush($this->stream) || !@fsync($this->stream)) throw new RuntimeException();
            $completed = self::opened($this->path, $this->stream);
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $field) {
                if ($completed[$field] !== $this->identity[$field]) throw new RuntimeException();
            }
            if ($completed['size'] !== $size || !@rewind($this->stream) ||
                @stream_get_contents($this->stream, $size + 1) !== $bytes ||
                $completed !== self::opened($this->path, $this->stream) ||
                $this->ancestors !== self::directories($this->parent)) throw new RuntimeException();
            return ['receiptSha256' => hash('sha256', $bytes), 'sizeBytes' => $size];
        } catch (Throwable) {
            throw new RuntimeException('PROJECT_INTAKE_RECEIVE_DENIED');
        }
    }

    /** Closing never removes or repairs the reserved pathname. */
    public function close(): void
    {
        try {
            if (is_resource($this->stream) && !@fclose($this->stream)) throw new RuntimeException();
            $this->stream = null;
        } catch (Throwable) {
            throw new RuntimeException('PROJECT_INTAKE_RECEIVE_DENIED');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) @fclose($this->stream);
    }

    private function validatedReceipt(array $receipt): array
    {
        $expected = [
            'schemaVersion' => 'sites.project-intake.receipt.v1',
            'tenantRef' => $this->command->tenantRef,
            'orderRef' => $this->command->orderRef,
            'contractRef' => $this->command->contractRef,
            'contractRevision' => $this->command->contractRevision,
            'productCode' => $this->command->productCode,
            'operationId' => $this->command->operationId,
            'idempotencyKey' => $this->command->idempotencyKey,
            'siteRef' => null,
            'commandDigest' => $this->command->semanticFingerprint(),
            'status' => 'recorded',
            'state' => 'awaiting_official_authority',
            'authorityVerified' => false,
            'clientAccepted' => false,
            'canPublish' => false,
        ];
        if (count($receipt) !== count($expected) || array_diff(array_keys($expected), array_keys($receipt))) {
            throw new RuntimeException();
        }
        BriefCandidateJson::uuid($receipt['siteRef']);
        foreach ($expected as $key => $value) {
            if ($key !== 'siteRef' && $receipt[$key] !== $value) throw new RuntimeException();
        }
        return array_replace($expected, $receipt);
    }

    /** @return array{string,array} */
    private static function available(string $parent, string $receiptRef): array
    {
        BriefCandidateJson::uuid($receiptRef);
        $ancestors = self::directories($parent);
        $identity = $ancestors[$parent];
        if ($identity['uid'] !== posix_geteuid() || ($identity['mode'] & 07777) !== 0700) throw new RuntimeException();
        $path = rtrim($parent, '/').'/'.$receiptRef.'.json';
        clearstatcache(true, $path);
        if (@lstat($path) !== false || file_exists($path) || is_link($path)) throw new RuntimeException();
        return [$path, $ancestors];
    }

    /** Refuse observed symlink components; this is not an atomic traversal. */
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
        $opened = @fstat($stream);
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
