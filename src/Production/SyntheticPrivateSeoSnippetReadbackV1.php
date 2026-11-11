<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;

/**
 * F1-217: read-back of textual SEO snippet from the exact four private,
 * review-pending files. Never a deploy artifact, SEO service or an ACK.
 */
final readonly class SyntheticPrivateSeoSnippetReadbackV1
{
    private function __construct(
        public string $releaseRef,
        public string $artifactDigest,
        public SyntheticSeoSnippetInspectionV1 $snippet,
    ) {}

    /** @param array<string,string> $exportedFiles */
    public static function inspect(array $exportedFiles,SiteBrief $expectedBrief):self
    {
        $proof=SyntheticPrivateDraftVerifier::verifyBytes($exportedFiles);
        if ($proof['reviewerDecisionStatus']!=='pending' ||
            $proof['publishable']!==false ||
            $proof['officialClientAcceptance']!==false) {
            throw new DomainException('private_seo_readback_requires_pending_noindex');
        }

        $renderer=new StaticSiteRenderer();
        $expected=$renderer->render($expectedBrief);
        if (!hash_equals($expected->digest,$proof['artifactDigest']) ||
            !hash_equals($expected->html,$exportedFiles['index.html'])) {
            throw new DomainException('private_seo_readback_edited_or_untrusted_html');
        }
        $snippet=SyntheticSeoSnippetInspectionV1::inspect(
            $expectedBrief,new ReleaseArtifact($exportedFiles['index.html'])
        );
        if (!hash_equals($snippet->artifactDigest,$proof['artifactDigest']) ||
            $snippet->canPublish() || $snippet->canIndexOnSearchEngines() ||
            $snippet->canWriteSeoEngine() || $snippet->canApproveForClient()) {
            throw new DomainException('private_seo_readback_implies_untrusted_authority');
        }
        return new self($proof['releaseRef'],$proof['artifactDigest'],$snippet);
    }

    public function canIndex():bool {return false;}
    public function canPublish():bool {return false;}
    public function canSendToSeoEngine():bool {return false;}
    public function canSharePublicly():bool {return false;}
    public function canApproveForClient():bool {return false;}
}
