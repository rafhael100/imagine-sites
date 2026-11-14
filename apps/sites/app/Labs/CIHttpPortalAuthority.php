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
        $f377 = $command->orderRef==='order-f377' &&
            $command->contractRef==='contract-f377';
        // F1-380 compatibility only: the *sender* consults a real RLS
        // protected fictional Portal purchase table. Receiver still does
        // NOT independently verify payment. No public environment uses it.
        $f380 = getenv('SITES_F380_PORTAL_DB_LAB')==='1' &&
            preg_match('/\\ATEST-[a-f0-9]{32}\\z/D',$command->orderRef)===1 &&
            $command->contractRef ===
                'contract-f380-'.substr($command->orderRef,5) &&
            str_starts_with($command->operationId,'operation-f380-') &&
            str_starts_with($command->idempotencyKey,'idem-f380-');
        $allowed = in_array(
            $command->tenantRef,['tenant-f360-a','tenant-f360-b'],true
        ) && ($f377 || $f380) && $command->contractRevision===1;
        return new PolicyDecision($allowed,
            $allowed ? [] : ['fictional_ci_contract_denied']);
    }
}
