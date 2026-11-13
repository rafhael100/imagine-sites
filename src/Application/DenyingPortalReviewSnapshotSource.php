<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

/** Default DENY: no trusted external Portal resource source is installed. */
final class DenyingPortalReviewSnapshotSource implements CurrentPortalReviewSnapshotSource
{
    public function findCurrentFor(string $tenantRef,string $siteRef):?CurrentPortalReviewSnapshot
    {
        return null;
    }
}
