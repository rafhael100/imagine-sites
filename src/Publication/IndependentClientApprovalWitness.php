<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\ReleaseManifest;

/**
 * Independently governed live confirmation of client consent and revocation.
 * A signed Portal assertion alone cannot satisfy this witness.
 */
interface IndependentClientApprovalWitness
{
    public function confirmsCurrent(
        ReleaseManifest $manifest,
        int $editorialRevision,
        string $acceptanceRef,
        int $acceptanceEpoch,
    ): bool;
}
