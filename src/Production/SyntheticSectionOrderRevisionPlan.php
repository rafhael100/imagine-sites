<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-175 — controlled display-order change, never a publish permission.
 *
 * Only a synthetic internal-review actor label can request an immutable
 * new draft. Real identity must be established independently in Portal.
 */
final readonly class SyntheticSectionOrderRevisionPlan
{
    /** @param list<string> $previousOrder @param list<string> $nextOrder */
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public string $previousDigest,
        public array $previousOrder,
        public array $nextOrder,
        public SiteBrief $nextBrief,
        public ReleaseArtifact $nextArtifact,
        public SyntheticProductionWorkflow $draftWorkflow,
    ) {}

    /** @param list<string> $requestedOrder */
    public static function begin(
        SyntheticProductionWorkflow $approvedWorkflow,
        SiteBrief $approvedBrief,
        ReleaseArtifact $approvedArtifact,
        string $previousReleaseRef,
        array $requestedOrder,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ): self {
        if ($approvedWorkflow->state !== SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $approvedWorkflow->revision !== $expectedRevision ||
            $approvedWorkflow->artifactDigest === null ||
            $actorRole !== 'imagine_reviewer') {
            throw new DomainException('section_reorder_requires_internal_review');
        }

        $renderer=new StaticSiteRenderer();
        $source=$renderer->render($approvedBrief);
        if (!hash_equals($source->digest,$approvedArtifact->digest) ||
            !hash_equals($source->html,$approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html)) {
            throw new DomainException('section_reorder_source_integrity_denied');
        }
        if ($approvedBrief->sectionOrder === $requestedOrder ||
            $previousReleaseRef === $nextReleaseRef) {
            throw new DomainException('section_reorder_requires_changed_order_and_new_release');
        }

        // SiteBrief independently validates all section permutations and
        // preserves the exact original copy, product, brand, theme and steps.
        $nextBrief=new SiteBrief(
            $approvedBrief->productCode,
            $approvedBrief->brand,
            $approvedBrief->headline,
            $approvedBrief->description,
            $approvedBrief->callToAction,
            $approvedBrief->benefits,
            $approvedBrief->stylePreset,
            $approvedBrief->faq,
            $approvedBrief->highlights,
            $approvedBrief->processSteps,
            $requestedOrder,
            $approvedBrief->sectionNavigation,
            $approvedBrief->brandPalette,
            $approvedBrief->socialPreviewMetadata,
            $approvedBrief->seoTitle,$approvedBrief->seoDescription,
            $approvedBrief->secondaryCallToAction,
            $approvedBrief->faqDisclosure,
        );
        $nextArtifact=$renderer->render($nextBrief);
        if (hash_equals($nextArtifact->digest,$approvedArtifact->digest) ||
            hash_equals($nextArtifact->html,$approvedArtifact->html)) {
            throw new DomainException('section_reorder_must_change_present_html_bytes');
        }

        $oldManifest=new ReleaseManifest(
            $approvedWorkflow->tenantRef,
            $approvedWorkflow->siteRef,
            $previousReleaseRef,
            $approvedArtifact->digest,
        );
        $newManifest=new ReleaseManifest(
            $approvedWorkflow->tenantRef,
            $approvedWorkflow->siteRef,
            $nextReleaseRef,
            $nextArtifact->digest,
        );
        $bundle=new SyntheticReleaseBundle($newManifest,$nextArtifact);
        if (!InertPreviewAudit::isSafe($nextArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($nextArtifact)->passedStructuralChecks ||
            !SyntheticReleaseBundle::verifyFiles($bundle->files,$newManifest)) {
            throw new DomainException('section_reorder_review_bundle_denied');
        }

        $draft=$approvedWorkflow->advance(
            $actorRole,'return_for_revision',$expectedRevision
        );
        if ($draft->state !== SyntheticProductionWorkflow::DRAFTING ||
            $draft->artifactDigest !== null) {
            throw new DomainException('section_reorder_must_revoke_old_review');
        }

        return new self(
            $oldManifest->releaseRef,$newManifest->releaseRef,
            $approvedArtifact->digest,$approvedBrief->sectionOrder,
            $requestedOrder,$nextBrief,$nextArtifact,$draft
        );
    }
}
