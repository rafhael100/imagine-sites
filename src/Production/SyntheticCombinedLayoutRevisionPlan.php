<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-187: one atomic-looking, pure domain proposal for section order AND
 * navigation changes, requiring a NEW independent internal review.
 *
 * This is only a fictional, immutable plan. It is NOT a database transaction,
 * an authenticated reviewer, a real Portal grant or an operational publisher.
 */
final readonly class SyntheticCombinedLayoutRevisionPlan
{
    /** @param list<string> $previousOrder @param list<string> $nextOrder */
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public string $previousDigest,
        public array $previousOrder,
        public bool $previousNavigation,
        public array $nextOrder,
        public bool $nextNavigation,
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
        bool $requestedNavigation,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ):self {
        if ($actorRole!=='imagine_reviewer' ||
            $approvedWorkflow->state!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $approvedWorkflow->revision!==$expectedRevision ||
            $approvedWorkflow->artifactDigest===null) {
            throw new DomainException('combined_layout_requires_matching_internal_review');
        }
        if ($previousReleaseRef===$nextReleaseRef ||
            ($approvedBrief->sectionOrder===$requestedOrder &&
             $approvedBrief->sectionNavigation===$requestedNavigation)) {
            throw new DomainException('combined_layout_requires_new_release_and_real_change');
        }

        // Recompute original HTML: never trust an approved digest or a
        // caller-supplied artifact without verifying ALL original bytes.
        $renderer=new StaticSiteRenderer();
        $source=$renderer->render($approvedBrief);
        if (!hash_equals($source->digest,$approvedArtifact->digest) ||
            !hash_equals($source->html,$approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html)) {
            throw new DomainException('combined_layout_original_content_mismatch');
        }

        // Only two typed layout options may differ: all copy, theme, brand,
        // tenant, product, benefits, steps and QA remain fixed.
        $nextBrief=new SiteBrief(
            $approvedBrief->productCode,$approvedBrief->brand,
            $approvedBrief->headline,$approvedBrief->description,
            $approvedBrief->callToAction,$approvedBrief->benefits,
            $approvedBrief->stylePreset,$approvedBrief->faq,
            $approvedBrief->highlights,$approvedBrief->processSteps,
            $requestedOrder,$requestedNavigation,$approvedBrief->brandPalette,
            $approvedBrief->socialPreviewMetadata,
            $approvedBrief->seoTitle,$approvedBrief->seoDescription,
            $approvedBrief->secondaryCallToAction,
            $approvedBrief->faqDisclosure,
            $approvedBrief->skipToContentLink,
        );
        $nextArtifact=$renderer->render($nextBrief);
        if (hash_equals($nextArtifact->digest,$approvedArtifact->digest) ||
            hash_equals($nextArtifact->html,$approvedArtifact->html)) {
            // E.g. rearranging three EMPTY optional sections or enabling
            // nav without any actual destinations must not create fake work.
            throw new DomainException('combined_layout_must_change_actual_html');
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
            throw new DomainException('combined_layout_private_bundle_denied');
        }

        $draft=$approvedWorkflow->advance(
            $actorRole,'return_for_revision',$expectedRevision
        );
        if ($draft->state!==SyntheticProductionWorkflow::DRAFTING ||
            $draft->artifactDigest!==null ||
            $draft->revision!==$expectedRevision+1) {
            throw new DomainException('combined_layout_review_not_reset');
        }

        return new self(
            $oldManifest->releaseRef,$newManifest->releaseRef,
            $approvedArtifact->digest,$approvedBrief->sectionOrder,
            $approvedBrief->sectionNavigation,$requestedOrder,
            $requestedNavigation,$nextBrief,$nextArtifact,$draft
        );
    }

    public function canPublish():bool {return false;}
    public function canApproveForClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
    public function canReusePriorReview():bool {return false;}
}
