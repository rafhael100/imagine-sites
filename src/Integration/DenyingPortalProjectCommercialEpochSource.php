<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/** Default-deny commercial grant generation provider. */
final class DenyingPortalProjectCommercialEpochSource implements PortalProjectCommercialEpochSource
{
    public function currentEpochFor(SiteProjectCommand $command): ?int
    {
        return null;
    }
}
