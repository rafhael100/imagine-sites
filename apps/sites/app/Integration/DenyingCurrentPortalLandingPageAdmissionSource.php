<?php
declare(strict_types=1);
namespace App\Integration;

/** No operational Portal transport, approval proof or publication permission. */
final readonly class DenyingCurrentPortalLandingPageAdmissionSource implements CurrentPortalLandingPageAdmissionSource
{
    public function readAdmission(string $tenantRef, string $orderRef, string $siteRef): ?string
    {
        return null;
    }
}
