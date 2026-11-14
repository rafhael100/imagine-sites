<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * Explicit operator-integrated, read-only Portal evidence source.
 * NOT an inbound JSON body, client header, fake payment field or cache.
 * Production implementation must authenticate Portal and obtain current
 * contract revision from an independently governed system of record.
 */
interface PortalEligibilityProofSource
{
    /** @return array<string,mixed>|null */
    public function fetchCurrentProof(SiteProjectCommand $command): ?array;
}
