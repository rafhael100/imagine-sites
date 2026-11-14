<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * Fail-closed, independent current entitlement/revocation epoch witness.
 * Must not trust the signed proof's epoch without querying this authority.
 */
interface IndependentRevocationWitness
{
    public function isCurrent(
        SiteProjectCommand $command,
        int $revocationEpoch,
    ): bool;
}
