<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\PortalEvidence;
use Imagine\Sites\Domain\ReleaseManifest;

/** Trusted infrastructure adapter contract — NOT implemented in F1-02. */
interface PortalAuthorityGateway
{
    public function verify(ReleaseManifest $manifest, PortalEvidence $evidence): PolicyDecision;
}
