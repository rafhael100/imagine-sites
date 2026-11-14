<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * Independent, current Sites authority. Operational source must enforce
 * immutable order/tenant ownership, contract/release version and revocation.
 * A signed Portal proof or webhook must NEVER be used as this source.
 */
interface SiteCurrentCommercialReleaseBindingSource
{
    public function readCurrent(
        SiteProjectCommand $command,
        ReleaseManifest $manifest,
        int $editorialRevision,
    ): ?SiteCommercialReleaseBinding;
}
