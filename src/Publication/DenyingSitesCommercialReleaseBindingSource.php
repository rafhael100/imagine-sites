<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Domain\ReleaseManifest;

/** No backend = no release evidence approval. */
final class DenyingSitesCommercialReleaseBindingSource
    implements SiteCurrentCommercialReleaseBindingSource
{
    public function readCurrent(
        SiteProjectCommand $command,
        ReleaseManifest $manifest,
        int $editorialRevision,
    ): ?SiteCommercialReleaseBinding {
        return null;
    }
}
