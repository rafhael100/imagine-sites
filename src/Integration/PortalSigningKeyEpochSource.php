<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * Candidate monotonic signing-key generation source.
 * This generation must come from a trusted authority outside any Sites
 * backup/restore domain; a laboratory fixture does not provide that.
 */
interface PortalSigningKeyEpochSource
{
    public function currentEpochFor(string $issuer, string $audience): ?int;
}
