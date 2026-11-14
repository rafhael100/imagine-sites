<?php

declare(strict_types=1);

namespace App\Labs;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;

/**
 * F1-377: deliberately fictional authority restricted to the isolated
 * F1-377 GitHub CI web fixture. NEVER register it in an application provider
 * or use it to authorize actual contracts, payments or customer projects.
 *
 * Both fictional tenants pass here, deliberately forcing the SQL login to
 * enforce cross-tenant isolation rather than silently trusting the test stub.
 */
final class CIHttpPortalAuthority implements PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        $allowed = in_array(
            $command->tenantRef,['tenant-f360-a','tenant-f360-b'],true
        ) && $command->orderRef==='order-f377' &&
            $command->contractRef==='contract-f377' &&
            $command->contractRevision===1;
        return new PolicyDecision($allowed,
            $allowed ? [] : ['fictional_ci_contract_denied']);
    }
}
