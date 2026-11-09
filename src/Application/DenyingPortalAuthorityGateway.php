<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\PortalEvidence;
use Imagine\Sites\Domain\ReleaseManifest;

/** Default for all non-integrated environments: no authority, no publication. */
final class DenyingPortalAuthorityGateway implements PortalAuthorityGateway
{
    public function verify(ReleaseManifest $manifest, PortalEvidence $evidence): PolicyDecision
    {
        return new PolicyDecision(false, ['trusted_portal_authority_unavailable']);
    }
}
