<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/** Fail-closed default: no operational Portal epoch integration exists. */
final class DenyingCurrentPortalProjectAuthorizationEpochSource implements CurrentPortalProjectAuthorizationEpochSource
{
    public function currentEpochFor(string $tenantRef, string $orderRef): ?int
    {
        return null;
    }
}
