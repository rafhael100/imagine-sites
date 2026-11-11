<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-193 — changing reviewed brand colors requires a NEW inert release.
 *
 * This is a pure, unauthenticated synthetic proposal. The reviewer role is
 * only a fixture label, NOT a Portal identity, client acceptance or signed
 * permission to publish/receive leads.
 */
final readonly class SyntheticBrandPaletteRevisionPlan
{
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public string $previousDigest,
        public ?SyntheticBrandPaletteV1 $previousPalette,
        public ?SyntheticBrandPaletteV1 $nextPalette,
        public SiteBrief $nextBrief,
        public ReleaseArtifact $nextArtifact,
        public SyntheticProductionWorkflow $draftWorkflow,
    ) {}

    public static function begin(
        SyntheticProductionWorkflow $approvedWorkflow,
        SiteBrief $approvedBrief,
        ReleaseArtifact $approvedArtifact,
        string $previousReleaseRef,
        ?SyntheticBrandPaletteV1 $requestedPalette,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ):self {
        if ($actorRole!=='imagine_reviewer' ||
            $approvedWorkflow->state!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $approvedWorkflow->revision!==$expectedRevision ||
            $approvedWorkflow->artifactDigest===null) {
            throw new DomainException('brand_palette_requires_current_internal_review');
        }
        if ($approvedBrief->brandPalette==$requestedPalette ||
            $previousReleaseRef===$nextReleaseRef) {
            // Equality by canonical value, not object identity.
            throw new DomainException('brand_palette_requires_changed_colors_and_new_release');
        }
        $render=new StaticSiteRenderer();
        $source=$render->render($approvedBrief);
        if (!hash_equals($source->digest,$approvedArtifact->digest) ||
            !hash_equals($source->html,$approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html)) {
            throw new DomainException('brand_palette_original_bytes_unverified');
        }

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
            $approvedBrief->sectionOrder,
            $approvedBrief->sectionNavigation,
            $requestedPalette,
        );
        $nextArtifact=$render->render($nextBrief);
        if (hash_equals($nextArtifact->digest,$approvedArtifact->digest) ||
            hash_equals($nextArtifact->html,$approvedArtifact->html)) {
            throw new DomainException('brand_palette_did_not_change_rendered_bytes');
        }
        $oldManifest=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $previousReleaseRef,$approvedArtifact->digest
        );
        $newManifest=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $nextReleaseRef,$nextArtifact->digest
        );
        $bundle=new SyntheticReleaseBundle($newManifest,$nextArtifact);
        if (!InertPreviewAudit::isSafe($nextArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($nextArtifact)->passedStructuralChecks ||
            !SyntheticReleaseBundle::verifyFiles($bundle->files,$newManifest)) {
            throw new DomainException('brand_palette_synthetic_review_qa_denied');
        }
        $draft=$approvedWorkflow->advance(
            $actorRole,'return_for_revision',$expectedRevision
        );
        if ($draft->state!==SyntheticProductionWorkflow::DRAFTING ||
            $draft->revision!==$expectedRevision+1 ||
            $draft->artifactDigest!==null) {
            throw new DomainException('brand_palette_previous_approval_not_revoked');
        }
        return new self(
            $oldManifest->releaseRef,$newManifest->releaseRef,
            $approvedArtifact->digest,$approvedBrief->brandPalette,
            $requestedPalette,$nextBrief,$nextArtifact,$draft
        );
    }

    public function canPublish():bool {return false;}
    public function canAcceptForClient():bool {return false;}
    public function canDeliverToCrm():bool {return false;}
    public function canReusePreviousReview():bool {return false;}
}
