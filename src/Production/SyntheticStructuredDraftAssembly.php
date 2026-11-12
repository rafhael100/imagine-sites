<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-71: assemble one structured, untrusted copy candidate into a private
 * content-addressed review draft. Product/brand/preset/tenant/site/release
 * originate outside the AI copy. This is not an authenticated service.
 */
final readonly class SyntheticStructuredDraftAssembly
{
    private function __construct(
        public SiteBrief $brief,
        public ReleaseArtifact $artifact,
        public ReleaseManifest $manifest,
        public SyntheticReleaseBundle $bundle,
        public SyntheticProductionWorkflow $workflow,
        public SyntheticPreviewQaReport $structuralReport,
        public SyntheticEditorialReviewReport $editorialReport,
    ) {}

    /**
     * @param array<string,mixed> $untrustedCopy Only sites.ai-copy.v1 keys.
     * @throws DomainException for unsafe, unreviewed or structurally invalid content
     */
    public static function build(
        string $officialProduct,
        string $officialBrand,
        string $officialPreset,
        array $untrustedCopy,
        string $tenantRef,
        string $siteRef,
        string $workflowRef,
        string $releaseRef,
        array $officialSectionOrder = ['highlights','process','faq'],
        bool $officialSectionNavigation = false,
        ?SyntheticBrandPaletteV1 $officialBrandPalette = null,
        bool $officialSocialPreviewMetadata = false,
        ?string $officialSeoTitle = null,
        ?string $officialSeoDescription = null,
        bool $officialSecondaryCallToAction = false,
        bool $officialFaqDisclosure = false,
        bool $officialSkipToContentLink = false,
    ): self {
        // Content provider does NOT determine brand, product, style or tenant.
        $candidate=StructuredCopyCandidate::assemble(
            $officialProduct,$officialBrand,$untrustedCopy
        );
        $brief=new SiteBrief(
            $candidate->productCode,
            $candidate->brand,
            $candidate->headline,
            $candidate->description,
            $candidate->callToAction,
            $candidate->benefits,
            $officialPreset,
            $candidate->faq,
            $candidate->highlights,
            $candidate->processSteps,
            $officialSectionOrder,
            $officialSectionNavigation,
            $officialBrandPalette,
            $officialSocialPreviewMetadata,
            $officialSeoTitle,$officialSeoDescription,
            $officialSecondaryCallToAction,
            $officialFaqDisclosure,
            $officialSkipToContentLink,
        );
        $artifact=(new StaticSiteRenderer())->render($brief);
        $qa=SyntheticPreviewQaReport::inspect($artifact);
        if (!InertPreviewAudit::isSafe($artifact->html) ||
            !$qa->passedStructuralChecks) {
            throw new DomainException('synthetic_draft_failed_structural_review');
        }
        $manifest=new ReleaseManifest(
            $tenantRef,$siteRef,$releaseRef,$artifact->digest
        );
        $bundle=new SyntheticReleaseBundle($manifest,$artifact);
        if (!SyntheticReleaseBundle::verifyFiles($bundle->files,$manifest) ||
            !hash_equals($manifest->digest,$artifact->digest)) {
            throw new DomainException('synthetic_draft_integrity_failed');
        }
        // An agent MAY submit a draft; only an independently authorized human
        // reviewer (not this method) can move to the next synthetic state.
        $workflow=SyntheticProductionWorkflow::create(
            $tenantRef,$siteRef,$workflowRef
        )->advance('agent','start_draft',0)
          ->advance('agent','submit_draft',1,$artifact->digest);

        if ($workflow->state !== SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $workflow->revision !== 2 ||
            $workflow->artifactDigest === null ||
            !hash_equals($workflow->artifactDigest,$manifest->digest)) {
            throw new DomainException('synthetic_draft_must_await_internal_review');
        }
        // Editor feedback is advisory and never silently grants approval.
        $editorial=SyntheticEditorialReviewReport::inspect($brief,$artifact);
        return new self($brief,$artifact,$manifest,$bundle,$workflow,$qa,$editorial);
    }

    /** @return array<string,string|int|bool> */
    public function reviewSummary(): array
    {
        return [
            'schemaVersion'=>'sites.synthetic.draft-assembly.v1',
            'tenantRef'=>$this->workflow->tenantRef,
            'siteRef'=>$this->workflow->siteRef,
            'releaseRef'=>$this->manifest->releaseRef,
            'artifactDigest'=>$this->artifact->digest,
            'workflowRevision'=>$this->workflow->revision,
            'workflowState'=>$this->workflow->state,
            'internalReviewRequired'=>true,
            'portalAuthorityRequired'=>true,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
        ];
    }
}
