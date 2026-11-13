<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * Candidate read-only source. In production, a separately authenticated
 * Portal IAM adapter must verify the current resource/contract/epoch and
 * revocation. This interface alone does not grant service identity.
 */
interface CurrentPortalProjectAuthorizationSource
{
    public function findCurrentFor(
        string $tenantRef,
        string $orderRef,
    ): ?CurrentPortalProjectAuthorizationSnapshot;
}
