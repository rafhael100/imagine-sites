<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/** No external source is configured: permanently DENY. */
final class DenyingCurrentPortalProjectAuthorizationSource implements CurrentPortalProjectAuthorizationSource
{
    public function findCurrentFor(
        string $tenantRef,
        string $orderRef,
    ): ?CurrentPortalProjectAuthorizationSnapshot {
        return null;
    }
}
