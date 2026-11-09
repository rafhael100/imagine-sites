<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\PolicyDecision;

/** Trusted operational adapter must independently verify every invocation. */
interface PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision;
}
