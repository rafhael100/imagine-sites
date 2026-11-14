<?php

declare(strict_types=1);

namespace App\Labs;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;

/**
 * CI-only permissive fake authority for real PostgreSQL purchase rehearsal.
 * This checks the *shape* of a test order, not its payment, client identity,
 * official contract or current revocation state. The REAL purchase source
 * for these fixtures runs in the separate pinned Portal process and must
 * reject ineligible rows before signing. Never wire this into production.
 */
final class CIHttpPortalPurchaseAuthority implements PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        $order=$command->orderRef;
        $match=is_string($order) &&
            preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)===1;
        $allowed=$match &&
            in_array($command->tenantRef,['tenant-f360-a','tenant-f360-b'],true) &&
            $command->contractRef==='contract-p0-'.substr($order,5) &&
            $command->contractRevision===1 &&
            in_array($command->productCode,['onepage','landing_page'],true);
        return new PolicyDecision($allowed,
            $allowed?[]:['synthetic_purchase_shape_denied']);
    }
}
