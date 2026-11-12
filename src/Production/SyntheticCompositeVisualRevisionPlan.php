<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-202: change theme, palette, section order and nav in ONE new draft.
 *
 * All four dimensions are editor-owned. Plain text, source product/brand and
 * all source workflow identifiers are immutable. This has no authenticated
 * user, transaction, Portal acceptance or CRM side effects.
 */
final readonly class SyntheticCompositeVisualRevisionPlan
{
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public string $previousDigest,
        public bool $styleChanged,
        public bool $paletteChanged,
        public bool $sectionOrderChanged,
        public bool $navigationChanged,
        public bool $socialMetadataChanged,
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
        string $requestedStyle,
        ?SyntheticBrandPaletteV1 $requestedPalette,
        array $requestedOrder,
        bool $requestedNavigation,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
        ?bool $requestedSocialPreviewMetadata = null,
    ):self {
        if ($actorRole!=='imagine_reviewer' ||
            $approvedWorkflow->state!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $approvedWorkflow->revision!==$expectedRevision ||
            $approvedWorkflow->artifactDigest===null ||
            $previousReleaseRef===$nextReleaseRef) {
            throw new DomainException('composite_visual_requires_new_approved_source_version');
        }
        $renderer=new StaticSiteRenderer();
        $source=$renderer->render($approvedBrief);
        if (!hash_equals($source->digest,$approvedArtifact->digest) ||
            !hash_equals($source->html,$approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html)) {
            throw new DomainException('composite_visual_original_html_mismatch');
        }
        // SiteBrief validates the exact style, section permutation, and the
        // Onepage-only navigation contract; palette has already been validated
        // by its private-constructor, contrast-enforcing type.
        $nextBrief=new SiteBrief(
            $approvedBrief->productCode,
            $approvedBrief->brand,
            $approvedBrief->headline,
            $approvedBrief->description,
            $approvedBrief->callToAction,
            $approvedBrief->benefits,
            $requestedStyle,
            $approvedBrief->faq,
            $approvedBrief->highlights,
            $approvedBrief->processSteps,
            $requestedOrder,
            $requestedNavigation,
            $requestedPalette,
            $requestedSocialPreviewMetadata??$approvedBrief->socialPreviewMetadata,
            $approvedBrief->seoTitle,$approvedBrief->seoDescription,
            $approvedBrief->secondaryCallToAction,
            $approvedBrief->faqDisclosure,
            $approvedBrief->skipToContentLink,
        );

        $styleChanged=$nextBrief->stylePreset!==$approvedBrief->stylePreset;
        $oldPalette=$approvedBrief->brandPalette?->cssRules();
        $newPalette=$nextBrief->brandPalette?->cssRules();
        $paletteChanged=$oldPalette!==$newPalette;
        $orderChanged=$nextBrief->sectionOrder!==$approvedBrief->sectionOrder;
        $navChanged=$nextBrief->sectionNavigation!==$approvedBrief->sectionNavigation;
        $socialChanged=$nextBrief->socialPreviewMetadata!==$approvedBrief->socialPreviewMetadata;
        if (!$styleChanged && !$paletteChanged && !$orderChanged && !$navChanged &&
            !$socialChanged) {
            throw new DomainException('composite_visual_requires_real_change');
        }
        $newArtifact=$renderer->render($nextBrief);
        if (hash_equals($newArtifact->digest,$approvedArtifact->digest) ||
            hash_equals($newArtifact->html,$approvedArtifact->html)) {
            throw new DomainException('composite_visual_cannot_create_invisible_revision');
        }
        $previousManifest=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $previousReleaseRef,$approvedArtifact->digest
        );
        $nextManifest=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $nextReleaseRef,$newArtifact->digest
        );
        $bundle=new SyntheticReleaseBundle($nextManifest,$newArtifact);
        if (!hash_equals($previousManifest->digest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($newArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($newArtifact)->passedStructuralChecks ||
            !SyntheticReleaseBundle::verifyFiles($bundle->files,$nextManifest)) {
            throw new DomainException('composite_visual_preview_or_release_denied');
        }
        $reopened=$approvedWorkflow->advance(
            $actorRole,'return_for_revision',$expectedRevision
        );
        if ($reopened->state!==SyntheticProductionWorkflow::DRAFTING ||
            $reopened->revision!==$expectedRevision+1 ||
            $reopened->artifactDigest!==null) {
            throw new DomainException('composite_visual_must_reset_review');
        }
        return new self(
            $previousReleaseRef,$nextReleaseRef,$approvedArtifact->digest,
            $styleChanged,$paletteChanged,$orderChanged,$navChanged,$socialChanged,
            $nextBrief,$newArtifact,$reopened
        );
    }

    public function canPublish():bool {return false;}
    public function canAcceptForClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
    public function canReusePreviousApproval():bool {return false;}
}
