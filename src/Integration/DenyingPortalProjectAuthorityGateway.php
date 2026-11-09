<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\PolicyDecision;

/** Fail-closed default. No external HTTP, credentials, access or side effects. */
final class DenyingPortalProjectAuthorityGateway implements PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        return new PolicyDecision(false, ['official_portal_project_authority_unavailable']);
    }
}
