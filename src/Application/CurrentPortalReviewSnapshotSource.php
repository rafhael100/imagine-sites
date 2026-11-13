<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

/**
 * Authoritative infrastructure adapter proposal: the record must originate
 * from Portal's current resource/tenant authorization, not a request parameter.
 * This interface does NOT confer identity by itself.
 */
interface CurrentPortalReviewSnapshotSource
{
    public function findCurrentFor(
        string $tenantRef,
        string $siteRef,
    ): ?CurrentPortalReviewSnapshot;
}
