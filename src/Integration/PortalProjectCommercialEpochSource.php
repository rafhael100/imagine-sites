<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * Future independent current commercial authorization generation, bound
 * to the authenticated CreateProject command. No live Portal IAM.
 */
interface PortalProjectCommercialEpochSource
{
    public function currentEpochFor(SiteProjectCommand $command): ?int;
}
