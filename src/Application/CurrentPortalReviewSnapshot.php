<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use InvalidArgumentException;

/**
 * F1-48: read-only snapshot SHAPE for a future independent, trusted Portal source.
 * This is not cryptographic proof, a bearer token or a client approval.
 */
final readonly class CurrentPortalReviewSnapshot
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $workflowRef,
        public int $workflowRevision,
        public string $releaseRef,
        public string $artifactDigest,
        public int $authorizationRevision,
        public string $status,
        public int $checkedAt,
        public int $validUntil,
    ) {
        foreach ([$tenantRef,$siteRef,$workflowRef,$releaseRef] as $ref) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)) {
                throw new InvalidArgumentException('Invalid Portal review snapshot reference.');
            }
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$artifactDigest) ||
            $workflowRevision<1 || $authorizationRevision<1 ||
            !in_array($status,['reviewable','suspended','revoked'],true) ||
            $checkedAt<1 || $validUntil<=$checkedAt ||
            $validUntil-$checkedAt>30) {
            throw new InvalidArgumentException('Invalid or excessive Portal review snapshot lifetime.');
        }
    }
}
