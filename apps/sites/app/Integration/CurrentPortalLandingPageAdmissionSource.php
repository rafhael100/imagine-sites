<?php
declare(strict_types=1);
namespace App\Integration;

/**
 * Private Portal-side admission reader candidate. It MUST obtain independent
 * tenant/site/order custody; caller selectors and cached JSON never authorize.
 * Unbound in production until Portal has an official authenticated provider.
 */
interface CurrentPortalLandingPageAdmissionSource
{
    public function readAdmission(string $tenantRef, string $orderRef, string $siteRef): ?string;
}
