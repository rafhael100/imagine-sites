<?php
declare(strict_types=1);
namespace App\Integration;

/**
 * Candidate private, independently authenticated Portal current head reader.
 * Bytes must come from an approved, unrevoked, versioned Portal ledger under
 * independently authorized tenant/order/site custody.
 * A caller-supplied selector is never authorization. No operational binding.
 */
interface CurrentPortalLandingPageApprovedBriefSource
{
    public function readCurrent(string $tenantRef, string $orderRef, string $siteRef): ?string;
}
