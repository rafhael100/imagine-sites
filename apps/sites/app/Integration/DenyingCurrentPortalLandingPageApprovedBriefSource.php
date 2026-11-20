<?php
declare(strict_types=1);
namespace App\Integration;

/** Default-deny: no authenticated official Portal approved-brief service yet. */
final readonly class DenyingCurrentPortalLandingPageApprovedBriefSource
    implements CurrentPortalLandingPageApprovedBriefSource
{
    public function readCurrent(string $tenantRef, string $orderRef, string $siteRef): ?string
    {
        return null;
    }
}
