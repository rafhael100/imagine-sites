<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use Throwable;

/**
 * Distinct, unbound canonical request candidate for Landing Page head reads.
 *
 * Landing Page must NEVER be encoded as Onepage. This is an envelope of
 * selectors, not proof of a signed client brief, contract, mTLS peer, tenant
 * authorization or publication permission. No transport or binding exists.
 */
final class PortalLandingPageHeadRequestCandidate
{
    public static function encode(string $tenantRef, string $orderRef, string $siteRef): string
    {
        try {
            BriefCandidateJson::reference($tenantRef);
            BriefCandidateJson::reference($orderRef);
            BriefCandidateJson::uuid($siteRef);
            $bytes = json_encode([
                'schemaVersion' => 'sites.landing-page-current-head.fetch.v1',
                'tenantRef' => $tenantRef,
                'orderRef' => $orderRef,
                'siteRef' => $siteRef,
                'productCode' => 'landing_page',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 1024) {
                throw new RuntimeException();
            }
            return $bytes;
        } catch (Throwable) {
            throw new RuntimeException('LANDING_PAGE_PORTAL_HEAD_REQUEST_DENIED');
        }
    }
}
