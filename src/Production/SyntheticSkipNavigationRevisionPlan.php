<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-278: an explicit, editor-owned keyboard skip-link revision.
 *
 * A previously reviewed HTML artifact cannot silently acquire or lose
 * navigation. This class is pure, synthetic and without authenticated role,
 * service authorization, web endpoint or production side effects.
 */
final readonly class SyntheticSkipNavigationRevisionPlan
{
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public string $previousDigest,
        public bool $previousSkipLink,
        public bool $requestedSkipLink,
        public SiteBrief $nextBrief,
        public ReleaseArtifact $nextArtifact,
        public SyntheticProductionWorkflow $draftWorkflow,
    ) {}

    public static function begin(
        SyntheticProductionWorkflow $approvedWorkflow,
        SiteBrief $approvedBrief,
        ReleaseArtifact $approvedArtifact,
        string $previousReleaseRef,
        bool $enableSkipLink,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ): self {
        if ($approvedWorkflow->state !== SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $actorRole !== 'imagine_reviewer' ||
            $approvedWorkflow->revision !== $expectedRevision ||
            $approvedWorkflow->artifactDigest === null) {
            throw new DomainException('skip_navigation_requires_current_reviewer');
        }
        if ($approvedBrief->skipToContentLink === $enableSkipLink ||
            $previousReleaseRef === $nextReleaseRef) {
            throw new DomainException('skip_navigation_requires_new_choice_and_release');
        }
        $renderer=new StaticSiteRenderer();
        $old=$renderer->render($approvedBrief);
        if (!hash_equals($old->digest,$approvedArtifact->digest) ||
            !hash_equals($old->html,$approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($approvedArtifact)->passedStructuralChecks) {
            throw new DomainException('skip_navigation_source_integrity_denied');
        }

        $oldRelease=new ReleaseManifest(
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
            $approvedBrief->secondaryCallToAction,$approvedBrief->faqDisclosure,
            $enableSkipLink
        );
        $artifact=$renderer->render($nextBrief);
        if (hash_equals($artifact->digest,$approvedArtifact->digest) ||
            hash_equals($artifact->html,$approvedArtifact->html) ||
            !InertPreviewAudit::isSafe($artifact->html) ||
            !SyntheticPreviewQaReport::inspect($artifact)->passedStructuralChecks) {
            throw new DomainException('skip_navigation_next_bytes_denied');
        }
        $nextRelease=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $nextReleaseRef,$artifact->digest
        );
        $bundle=new SyntheticReleaseBundle($nextRelease,$artifact);
        if (!SyntheticReleaseBundle::verifyFiles($bundle->files,$nextRelease)) {
            throw new DomainException('skip_navigation_next_bundle_denied');
        }
        $draft=$approvedWorkflow->advance(
            $actorRole,'return_for_revision',$expectedRevision
        );
        if ($draft->state !== SyntheticProductionWorkflow::DRAFTING ||
            $draft->artifactDigest !== null) {
            throw new DomainException('skip_navigation_must_reopen_review');
        }
        return new self(
            $oldRelease->releaseRef,$nextRelease->releaseRef,
            $approvedArtifact->digest,$approvedBrief->skipToContentLink,
            $enableSkipLink,$nextBrief,$artifact,$draft
        );
    }

    public function canPublish():bool {return false;}
    public function canAcceptForClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
}
