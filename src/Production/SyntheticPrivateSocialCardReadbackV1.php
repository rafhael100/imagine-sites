<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;

/**
 * F1-211: exact, fail-closed text-card readback from the canonical FOUR
 * synthetic private review files. This report cannot confer any permission.
 *
 * No URL/image/network, source file paths, tenant claims or real credentials.
 */
final readonly class SyntheticPrivateSocialCardReadbackV1
{
    private function __construct(
        public string $releaseRef,
        public string $artifactDigest,
        public bool $socialMetadataEnabled,
        public ?SyntheticSocialCardInspectionV1 $card,
    ) {}

    /** @param array<string,string> $files */
    public static function inspect(array $files,SiteBrief $expectedBrief):self
    {
        $proof=SyntheticPrivateDraftVerifier::verifyBytes($files);
        $render=(new StaticSiteRenderer())->render($expectedBrief);
        if (!hash_equals($render->digest,$proof['artifactDigest']) ||
            !hash_equals($render->html,$files['index.html']) ||
            $proof['reviewerDecisionStatus']!=='pending' ||
            $proof['officialClientAcceptance']!==false ||
            $proof['publishable']!==false) {
            throw new DomainException('private_social_readback_snapshot_mismatch');
        }

        $card=$expectedBrief->socialPreviewMetadata
            ? SyntheticSocialCardInspectionV1::inspect(
                $expectedBrief,new ReleaseArtifact($files['index.html'])
            )
            : null;
        if ($card!==null &&
            !hash_equals($card->artifactDigest,$proof['artifactDigest'])) {
            throw new DomainException('private_social_readback_card_digest_mismatch');
        }
        // The baseline has no OG/Twitter tags as guaranteed by canonical
        // re-render comparison. Do not invent any social card in this case.
        return new self(
            $proof['releaseRef'],$proof['artifactDigest'],
            $expectedBrief->socialPreviewMetadata,$card
        );
    }

    public function canPublish():bool {return false;}
    public function canMarkClientAccepted():bool {return false;}
    public function canSendToCrm():bool {return false;}
    public function canFetchRemoteMedia():bool {return false;}
    public function canSharePublicly():bool {return false;}
}
