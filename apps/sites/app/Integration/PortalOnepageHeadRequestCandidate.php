<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use Throwable;

/**
 * Exact canonical body of the proposed read-only Portal Onepage head request.
 *
 * This is DATA, not an identity token. The future Portal endpoint must verify
 * the actual mTLS peer separately from every selector supplied in this body.
 * No public HTTP endpoint or operational service binding is introduced here.
 */
final class PortalOnepageHeadRequestCandidate
{
    public static function encode(string $tenantRef, string $orderRef, string $siteRef): string
    {
        try {
            BriefCandidateJson::reference($tenantRef);
            BriefCandidateJson::reference($orderRef);
            BriefCandidateJson::uuid($siteRef);
            $json = json_encode([
                'schemaVersion' => 'sites.onepage-current-head.fetch.v1',
                'tenantRef' => $tenantRef,
                'orderRef' => $orderRef,
                'siteRef' => $siteRef,
                'productCode' => 'onepage',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            if (!is_string($json) || $json === '' || strlen($json) > 1024) {
                throw new RuntimeException();
            }
            return $json;
        } catch (Throwable) {
            throw new RuntimeException('ONEPAGE_PORTAL_HEAD_REQUEST_DENIED');
        }
    }
}
