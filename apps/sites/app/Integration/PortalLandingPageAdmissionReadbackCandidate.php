<?php
declare(strict_types=1);
namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use Throwable;

/**
 * Consistency-only Landing Page admission candidate.
 * Both independent Portal reads must be identical and match a separately
 * established tenant/order/site/contract/revision expectation.
 * A response is NOT official approval, a durable revocation proof, payment
 * authority, an artifact, publishing permission or customer acceptance.
 */
final readonly class PortalLandingPageAdmissionReadbackCandidate
{
    public function __construct(
        private CurrentPortalLandingPageAdmissionSource $source =
            new DenyingCurrentPortalLandingPageAdmissionSource(),
    ) {}

    /** @return array{tenantRef:string,orderRef:string,siteRef:string,contractRef:string,contractRevision:int,productCode:string} */
    public function assertConsistent(
        string $tenantRef, string $orderRef, string $siteRef,
        string $contractRef, int $contractRevision,
    ): array {
        try {
            BriefCandidateJson::reference($tenantRef);
            BriefCandidateJson::reference($orderRef);
            BriefCandidateJson::uuid($siteRef);
            BriefCandidateJson::reference($contractRef);
            if ($contractRevision < 1 || $contractRevision > 9007199254740991) {
                throw new RuntimeException();
            }
            $before = $this->source->readAdmission($tenantRef,$orderRef,$siteRef);
            if (!is_string($before)) throw new RuntimeException();
            $first = PortalLandingPageAdmissionProjectionCandidate::decode(
                $before,$tenantRef,$orderRef,$siteRef,$contractRef,$contractRevision,
            );
            $after = $this->source->readAdmission($tenantRef,$orderRef,$siteRef);
            if (!is_string($after)) throw new RuntimeException();
            $second = PortalLandingPageAdmissionProjectionCandidate::decode(
                $after,$tenantRef,$orderRef,$siteRef,$contractRef,$contractRevision,
            );
            if (!hash_equals($before,$after) || $first !== $second) {
                throw new RuntimeException();
            }
            return $first;
        } catch (Throwable) {
            // Do not leak customer selectors, custody, provider errors or bytes.
            throw new RuntimeException('SITES_LANDING_PAGE_ADMISSION_READBACK_DENIED');
        }
    }
}
