<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;

/**
 * F1-213: one exact invariant between a PRIVATE visual reviewer choice
 * and bytes of its export. This is not a signature, external acceptance,
 * a live release or a database consistency guarantee.
 */
final readonly class SyntheticVisualReviewExportParityV1
{
    private function __construct(
        public string $releaseRef,
        public string $artifactDigest,
        public bool $socialEnabled,
        public ?SyntheticSocialCardInspectionV1 $socialCard,
    ) {}

    /** @param array<string,string> $exportedFiles */
    public static function inspect(
        SyntheticCompositeVisualPrivateFixture $review,
        array $exportedFiles,
    ):self {
        $expected=$review->draft;
        $proof=SyntheticPrivateDraftVerifier::verifyBytes($exportedFiles);
        $readback=SyntheticPrivateSocialCardReadbackV1::inspect(
            $exportedFiles,$expected->brief
        );
        if (!hash_equals($proof['artifactDigest'],$expected->artifact->digest) ||
            !hash_equals($proof['artifactDigest'],$readback->artifactDigest) ||
            !hash_equals($expected->artifact->html,$exportedFiles['index.html']) ||
            $proof['releaseRef']!==$expected->manifest->releaseRef ||
            $proof['releaseRef']!==$readback->releaseRef ||
            $proof['publishable']!==false ||
            $proof['officialClientAcceptance']!==false ||
            $proof['reviewerDecisionStatus']!=='pending' ||
            $readback->socialMetadataEnabled!==$expected->brief->socialPreviewMetadata ||
            ($readback->card!==null)!==$expected->brief->socialPreviewMetadata) {
            throw new DomainException('visual_review_export_bytes_or_permissions_mismatch');
        }

        return new self(
            $proof['releaseRef'],$proof['artifactDigest'],
            $readback->socialMetadataEnabled,$readback->card,
        );
    }

    public function canPublish():bool {return false;}
    public function canAcceptForClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
    public function canSkipReview():bool {return false;}
}
