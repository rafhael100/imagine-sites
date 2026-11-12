<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-283: explicit reviewer-owned toggle of the keyboard bypass to main.
 *
 * It must produce an entirely new private draft and invalidate all prior
 * review/acceptance claims. Synthetic role labels are NOT identities.
 * No client self-service, form, HTTP, delivery, or live publication.
 */
final readonly class SyntheticSkipToMainRevisionPlan
{
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public bool $previousSkipToMain,
        public bool $requestedSkipToMain,
        public string $previousDigest,
        public SiteBrief $nextBrief,
        public ReleaseArtifact $nextArtifact,
        public SyntheticProductionWorkflow $draftWorkflow,
    ) {}

    public static function begin(
        SyntheticProductionWorkflow $approvedWorkflow,
        SiteBrief $approvedBrief,
        ReleaseArtifact $approvedArtifact,
        string $previousReleaseRef,
        bool $enableSkipToMain,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ):self {
        if ($approvedWorkflow->state !== SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $approvedWorkflow->revision !== $expectedRevision ||
            $approvedWorkflow->artifactDigest === null ||
            $actorRole !== 'imagine_reviewer') {
            throw new DomainException('skip_to_main_requires_current_internal_reviewer');
        }
        if ($approvedBrief->skipToMain === $enableSkipToMain ||
            $previousReleaseRef === $nextReleaseRef) {
            throw new DomainException('skip_to_main_requires_existing_faq_and_new_release');
        }

        $renderer=new StaticSiteRenderer();
        $recomputed=$renderer->render($approvedBrief);
        if (!hash_equals($approvedArtifact->digest,$recomputed->digest) ||
            !hash_equals($approvedArtifact->html,$recomputed->html) ||
            !hash_equals($approvedWorkflow->artifactDigest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($approvedArtifact)->passedStructuralChecks) {
            throw new DomainException('skip_to_main_previous_release_integrity_failed');
        }

        $old=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $previousReleaseRef,$approvedArtifact->digest
        );
        $nextBrief=new SiteBrief(
            $approvedBrief->productCode,$approvedBrief->brand,
            $approvedBrief->headline,$approvedBrief->description,
            $approvedBrief->callToAction,$approvedBrief->benefits,
            $approvedBrief->stylePreset,$approvedBrief->faq,
            $approvedBrief->highlights,$approvedBrief->processSteps,
            $approvedBrief->sectionOrder,$approvedBrief->sectionNavigation,
            $approvedBrief->brandPalette,$approvedBrief->socialPreviewMetadata,
            $approvedBrief->seoTitle,$approvedBrief->seoDescription,
            $approvedBrief->secondaryCallToAction,
            $approvedBrief->faqDisclosure,$enableSkipToMain
        );
        $nextArtifact=$renderer->render($nextBrief);
        if (hash_equals($nextArtifact->digest,$approvedArtifact->digest) ||
            hash_equals($nextArtifact->html,$approvedArtifact->html) ||
            !InertPreviewAudit::isSafe($nextArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($nextArtifact)->passedStructuralChecks) {
            throw new DomainException('skip_to_main_must_change_safe_exact_html');
        }
        $next=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $nextReleaseRef,$nextArtifact->digest
        );
        $bundle=new SyntheticReleaseBundle($next,$nextArtifact);
        if (!SyntheticReleaseBundle::verifyFiles($bundle->files,$next)) {
            throw new DomainException('skip_to_main_next_release_bundle_denied');
        }

        $draft=$approvedWorkflow->advance(
            $actorRole,'return_for_revision',$expectedRevision
        );
        if ($draft->state !== SyntheticProductionWorkflow::DRAFTING ||
            $draft->artifactDigest !== null) {
            throw new DomainException('skip_to_main_must_reopen_review');
        }
        return new self(
            $old->releaseRef,$next->releaseRef,
            $approvedBrief->skipToMain,$enableSkipToMain,
            $approvedArtifact->digest,$nextBrief,$nextArtifact,$draft
        );
    }

    public function canPublish():bool {return false;}
    public function canDeliverToClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
}
