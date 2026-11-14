<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\ReleaseManifest;

/**
 * Trusted, read-only Portal acceptance source. Never an inbound browser body,
 * a cache or an operator assertion. No production adapter is registered.
 */
interface PortalClientApprovalReceiptSource
{
    /** @return array<string,mixed>|null */
    public function fetchCurrentReceipt(
        ReleaseManifest $manifest,
        int $editorialRevision
    ): ?array;
}
