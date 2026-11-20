<?php
declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Validates only a read-only Portal pre-head admission PROJECTION.
 * Never treats it as official approval, revocation proof, paid order,
 * briefing content, renderer input or publishing/acceptance authority.
 * No transport or production binding exists.
 */
final class PortalLandingPageAdmissionProjectionCandidate
{
    /**
     * @return array{tenantRef:string,orderRef:string,siteRef:string,contractRef:string,contractRevision:int,productCode:string}
     */
    public static function decode(
        string $bytes, string $expectedTenant, string $expectedOrder, string $expectedSite,
        string $expectedContractRef, int $expectedContractRevision,
    ): array {
        try {
            BriefCandidateJson::reference($expectedTenant);
            BriefCandidateJson::reference($expectedOrder);
            BriefCandidateJson::uuid($expectedSite);
            BriefCandidateJson::reference($expectedContractRef);
            if ($expectedContractRevision < 1 || $expectedContractRevision > 9007199254740991) {
                throw new RuntimeException();
            }
            if ($bytes === '' || strlen($bytes) > 2048 || str_contains($bytes, "\0")) {
                throw new RuntimeException();
            }
            $object = json_decode($bytes, false, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$object instanceof stdClass ||
                array_keys(get_object_vars($object)) !== [
                    'tenantRef', 'orderRef', 'siteRef', 'contractRef',
                    'contractRevision', 'productCode',
                ] ||
                $object->tenantRef !== $expectedTenant ||
                $object->orderRef !== $expectedOrder ||
                $object->siteRef !== $expectedSite ||
                $object->productCode !== 'landing_page' ||
                $object->contractRef !== $expectedContractRef ||
                $object->contractRevision !== $expectedContractRevision ||
                !is_string($object->contractRef) ||
                !is_int($object->contractRevision) ||
                $object->contractRevision < 1 ||
                $object->contractRevision > 9007199254740991) {
                throw new RuntimeException();
            }
            BriefCandidateJson::reference($object->contractRef);
            $flat = [
                'tenantRef' => $expectedTenant,
                'orderRef' => $expectedOrder,
                'siteRef' => $expectedSite,
                'contractRef' => $object->contractRef,
                'contractRevision' => $object->contractRevision,
                'productCode' => 'landing_page',
            ];
            $canonical = json_encode($flat, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($canonical) || !hash_equals($canonical, $bytes)) {
                throw new RuntimeException();
            }
            return $flat;
        } catch (Throwable) {
            throw new RuntimeException('SITES_LANDING_PAGE_ADMISSION_PROJECTION_DENIED');
        }
    }
}
