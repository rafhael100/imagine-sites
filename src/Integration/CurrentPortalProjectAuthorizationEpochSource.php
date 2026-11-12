<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-105 candidate revocation/epoch barrier. A REAL implementation must use
 * an independently authenticated, current Portal authority source.
 * Returning null means unknown/unavailable and must DENY, never fall back.
 */
interface CurrentPortalProjectAuthorizationEpochSource
{
    public function currentEpochFor(
        string $tenantRef,
        string $orderRef,
    ): ?int;
}
