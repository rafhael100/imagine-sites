<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-207: metadata toggle is a new immutable internal-review draft.
 * This is a synthetic domain plan, not a real authenticated Portal action.
 */
final readonly class SyntheticSocialPreviewRevisionPlan
{
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public string $previousDigest,
        public bool $previousEnabled,
        public bool $nextEnabled,
        public SiteBrief $nextBrief,
        public ReleaseArtifact $nextArtifact,
        public SyntheticProductionWorkflow $draftWorkflow,
    ) {}

    public static function begin(
        SyntheticProductionWorkflow $approvedWorkflow,
        SiteBrief $approvedBrief,
        ReleaseArtifact $approvedArtifact,
        string $previousReleaseRef,
        bool $enableSocialPreview,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ):self {
        if ($actorRole!=='imagine_reviewer' ||
            $approvedWorkflow->state!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $approvedWorkflow->revision!==$expectedRevision ||
            $approvedWorkflow->artifactDigest===null ||
            $previousReleaseRef===$nextReleaseRef ||
            $approvedBrief->socialPreviewMetadata===$enableSocialPreview) {
            throw new DomainException('social_preview_requires_changed_approved_version');
        }

        // Cryptographically pin the source BYTES, not only metadata fields.
        $renderer=new StaticSiteRenderer();
        $source=$renderer->render($approvedBrief);
        if (!hash_equals($source->digest,$approvedArtifact->digest) ||
            !hash_equals($source->html,$approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html)) {
            throw new DomainException('social_preview_source_integrity_denied');
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
            $approvedBrief->brandPalette,
            $enableSocialPreview,
        );
        $nextArtifact=$renderer->render($nextBrief);
        if (hash_equals($nextArtifact->digest,$approvedArtifact->digest) ||
            hash_equals($nextArtifact->html,$approvedArtifact->html) ||
            !InertPreviewAudit::isSafe($nextArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($nextArtifact)->passedStructuralChecks) {
            throw new DomainException('social_preview_html_must_change_safely');
        }
        $previousManifest=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $previousReleaseRef,$approvedArtifact->digest
        );
        $newManifest=new ReleaseManifest(
            $approvedWorkflow->tenantRef,$approvedWorkflow->siteRef,
            $nextReleaseRef,$nextArtifact->digest
        );
        $bundle=new SyntheticReleaseBundle($newManifest,$nextArtifact);
        if (!hash_equals($previousManifest->digest,$approvedArtifact->digest) ||
            !SyntheticReleaseBundle::verifyFiles($bundle->files,$newManifest)) {
            throw new DomainException('social_preview_bundle_or_manifest_mismatch');
        }

        $draft=$approvedWorkflow->advance(
            $actorRole,'return_for_revision',$expectedRevision
        );
        if ($draft->state!==SyntheticProductionWorkflow::DRAFTING ||
            $draft->artifactDigest!==null ||
            $draft->revision!==$expectedRevision+1) {
            throw new DomainException('social_preview_must_reopen_independent_review');
        }

        return new self(
            $previousReleaseRef,$nextReleaseRef,$approvedArtifact->digest,
            $approvedBrief->socialPreviewMetadata,$enableSocialPreview,
            $nextBrief,$nextArtifact,$draft
        );
    }

    public function canPublish():bool {return false;}
    public function canAcceptForClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
    public function canReusePreviousApproval():bool {return false;}
}
