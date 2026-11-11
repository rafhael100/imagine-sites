<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Throwable;

/**
 * F1-87: stage and verify an inert, fictional review package before making
 * the four files visible at a fresh private destination.
 *
 * Intended for SINGLE-WRITER, local CLI test fixtures only. A rename on one
 * filesystem is atomic, but this is NOT a distributed lock, hardened secure
 * filesystem service, customer upload endpoint, approval or publish action.
 */
final class SyntheticPrivateDraftWriter
{
    private const FILES = [
        'index.html', 'internal-review-dossier.json',
        'internal-review-draft.json', 'release-manifest.json',
    ];

    /**
     * @param array<string,mixed> $files Canonical bytes of exactly four files.
     * @return array<string,mixed> Read-only verification proof, NOT authority.
     */
    public static function create(string $directory, array $files): array
    {
        if (array_keys($files) !== self::FILES) {
            throw new DomainException('private_draft_writer_requires_exact_inventory');
        }
        // Reject forged or inconsistent bytes BEFORE writing to disk.
        $expectedProof = SyntheticPrivateDraftVerifier::verifyBytes($files);
        if ($expectedProof['publishable'] !== false ||
            $expectedProof['officialClientAcceptance'] !== false ||
            $expectedProof['reviewerDecisionStatus'] !== 'pending') {
            throw new DomainException('private_draft_writer_cannot_approve_release');
        }
        $parent = dirname($directory);
        $base = basename($directory);
        if ($directory === '' || $base === '' || $base === '.' || $base === '..' ||
            !preg_match('/\Asites-(?:f1-72-(?:onepage|landing_page)-(?:classic|modern|warm)-(?:basic|focused)|f1-174-(?:onepage|landing_page)-(?:classic|modern|warm)-edited)\z/D', $base) ||
            is_link($parent) || !is_dir($parent) ||
            is_link($directory) || file_exists($directory)) {
            throw new DomainException('private_draft_writer_requires_fresh_fixed_target');
        }

        try {
            $random = bin2hex(random_bytes(10));
        } catch (Throwable $e) {
            throw new DomainException('private_draft_writer_no_random_stage', previous: $e);
        }
        $stage = $parent.'/.sites-f1-87-stage-'.$random;
        if (!@mkdir($stage, 0700)) {
            throw new DomainException('private_draft_writer_staging_unavailable');
        }

        $moved = false;
        try {
            if (is_link($stage) || !is_dir($stage)) {
                throw new DomainException('private_draft_writer_stage_unsafe');
            }
            foreach (self::FILES as $name) {
                $target = $stage.'/'.$name;
                $bytes = $files[$name];
                $handle = @fopen($target, 'x');
                if ($handle === false) {
                    throw new DomainException('private_draft_writer_create_file_failed');
                }
                try {
                    $remaining = strlen($bytes);
                    $offset = 0;
                    while ($remaining > 0) {
                        $wrote = fwrite($handle, substr($bytes, $offset));
                        if ($wrote === false || $wrote === 0) {
                            throw new DomainException('private_draft_writer_incomplete_file');
                        }
                        $offset += $wrote;
                        $remaining -= $wrote;
                    }
                    if (!fflush($handle)) {
                        throw new DomainException('private_draft_writer_flush_failed');
                    }
                } finally {
                    fclose($handle);
                }
                if (!@chmod($target, 0600)) {
                    throw new DomainException('private_draft_writer_private_mode_required');
                }
            }
            $actualProof = SyntheticPrivateDraftVerifier::verifyDirectory($stage);
            if ($actualProof !== $expectedProof ||
                is_link($directory) || file_exists($directory)) {
                throw new DomainException('private_draft_writer_failed_readback_or_conflict');
            }
            // Stage and target are siblings on the same local filesystem.
            if (!@rename($stage, $directory)) {
                throw new DomainException('private_draft_writer_atomic_rename_failed');
            }
            $moved = true;
            // The target is verified AGAIN independently after the rename.
            $committedProof = SyntheticPrivateDraftVerifier::verifyDirectory($directory);
            if ($committedProof !== $expectedProof) {
                throw new DomainException('private_draft_writer_committed_bytes_changed');
            }
            return $committedProof;
        } catch (Throwable $e) {
            if ($e instanceof DomainException) {
                throw $e;
            }
            throw new DomainException('private_draft_writer_failed_closed', previous: $e);
        } finally {
            // Never remove the committed target, which might have been taken
            // over concurrently; callers fail closed and report the problem.
            if (!$moved && is_dir($stage) && !is_link($stage)) {
                foreach (self::FILES as $name) {
                    if (is_file($stage.'/'.$name) || is_link($stage.'/'.$name)) {
                        @unlink($stage.'/'.$name);
                    }
                }
                @rmdir($stage);
            }
        }
    }
}
