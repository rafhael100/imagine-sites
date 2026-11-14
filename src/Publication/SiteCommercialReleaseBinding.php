<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use InvalidArgumentException;

/**
 * Independent Sites-side order->release association from a trusted registry.
 * NOT a Portal proof, browser payload, customer-entered URL or issuer claim.
 */
final readonly class SiteCommercialReleaseBinding
{
    public function __construct(
        public string $tenantRef,
        public string $orderRef,
        public string $contractRef,
        public int $contractRevision,
        public string $productCode,
        public string $siteRef,
        public string $releaseRef,
        public string $artifactDigest,
        public int $editorialRevision,
        public int $bindingRevision,
    ) {
        foreach ([$tenantRef,$orderRef,$contractRef,$siteRef,$releaseRef] as $ref) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)!==1) {
                throw new InvalidArgumentException('Invalid trusted Sites release association.');
            }
        }
        if ($contractRevision<1 || $editorialRevision<1 || $bindingRevision<1 ||
            !in_array($productCode,['onepage','landing_page'],true) ||
            preg_match('/\A[a-f0-9]{64}\z/D',$artifactDigest)!==1) {
            throw new InvalidArgumentException('Invalid trusted Sites release association.');
        }
    }
}
