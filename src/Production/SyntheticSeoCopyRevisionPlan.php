<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-221: deliberate SEO-only copy changes reopen the internal review.
 *
 * This is a synthetic, immutable proposal. A role STRING is not a verified
 * actor, and no publication, client approval or SEO Engine write occurs.
 */
final readonly class SyntheticSeoCopyRevisionPlan
{
    /** @param list<string> $changedFields */
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public string $previousDigest,
        public array $changedFields,
        public SiteBrief $nextBrief,
        public ReleaseArtifact $nextArtifact,
        public SyntheticProductionWorkflow $draftWorkflow,
    ) {}

    public static function begin(
        SyntheticProductionWorkflow $approvedWorkflow,
        SiteBrief $approvedBrief,
        ReleaseArtifact $approvedArtifact,
        string $previousReleaseRef,
        ?string $requestedSeoTitle,
        ?string $requestedSeoDescription,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ):self {
        if ($actorRole!=='imagine_reviewer' ||
            $approvedWorkflow->state!==
                SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $approvedWorkflow->artifactDigest===null ||
            $approvedWorkflow->revision!==$expectedRevision ||
            $previousReleaseRef===$nextReleaseRef ||
            ($approvedBrief->seoTitle===$requestedSeoTitle &&
             $approvedBrief->seoDescription===$requestedSeoDescription)) {
            throw new DomainException('seo_copy_revision_requires_changed_reviewed_source');
        }

        $renderer=new StaticSiteRenderer();
        $original=$renderer->render($approvedBrief);
        if (!hash_equals($original->digest,$approvedArtifact->digest) ||
            !hash_equals($original->html,$approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest,$approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html)) {
            throw new DomainException('seo_copy_revision_original_bytes_denied');
        }

        // Every non-SEO field remains immutable, including the HTML hero,
        // social opt-in, brand identity, palette and current tenant context.
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
            $approvedBrief->socialPreviewMetadata,
            $requestedSeoTitle,
            $requestedSeoDescription,
            $approvedBrief->secondaryCallToAction,
            $approvedBrief->faqDisclosure,
            $approvedBrief->skipToMain,
        );
        $nextArtifact=$renderer->render($nextBrief);
        if (hash_equals($nextArtifact->digest,$approvedArtifact->digest) ||
            hash_equals($nextArtifact->html,$approvedArtifact->html) ||
            !InertPreviewAudit::isSafe($nextArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($nextArtifact)->passedStructuralChecks) {
            throw new DomainException('seo_copy_revision_must_change_safe_html_bytes');
        }

        $seo=SyntheticSeoSnippetInspectionV1::inspect($nextBrief,$nextArtifact);
        if ($seo->canPublish() || $seo->canIndexOnSearchEngines() ||
            $seo->canWriteSeoEngine() ||
            !hash_equals($seo->artifactDigest,$nextArtifact->digest)) {
            throw new DomainException('seo_copy_revision_inspection_not_denied');
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
        if (!SyntheticReleaseBundle::verifyFiles($bundle->files,$newManifest) ||
            !hash_equals($oldManifest->digest,$approvedArtifact->digest)) {
            throw new DomainException('seo_copy_revision_private_bundle_denied');
        }

        $draft=$approvedWorkflow->advance(
            $actorRole,'return_for_revision',$expectedRevision
        );
        if ($draft->state!==SyntheticProductionWorkflow::DRAFTING ||
            $draft->revision!==$expectedRevision+1 ||
            $draft->artifactDigest!==null) {
            throw new DomainException('seo_copy_revision_must_revoke_prior_review');
        }

        $changed=[];
        if ($approvedBrief->seoTitle!==$requestedSeoTitle) {
            $changed[]='seoTitle';
        }
        if ($approvedBrief->seoDescription!==$requestedSeoDescription) {
            $changed[]='seoDescription';
        }

        return new self(
            $oldManifest->releaseRef,
            $newManifest->releaseRef,
            $approvedArtifact->digest,
            $changed,
            $nextBrief,
            $nextArtifact,
            $draft
        );
    }

    public function canPublish():bool {return false;}
    public function canIndex():bool {return false;}
    public function canWriteSeoEngine():bool {return false;}
    public function canApproveForClient():bool {return false;}
    public function canReusePriorReview():bool {return false;}
}
