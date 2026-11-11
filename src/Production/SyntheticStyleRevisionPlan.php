<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-54 — synthetic change of visual preset is an entirely NEW draft.
 *
 * No AI, HTTP, Portal event, identity, self-service edit, automatic internal
 * approval or publishing. The renderer's exact HTML bytes identify each style.
 */
final readonly class SyntheticStyleRevisionPlan
{
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
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
        string $nextStylePreset,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ): self {
        if ($approvedWorkflow->state !== SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $actorRole !== 'imagine_reviewer' ||
            $approvedWorkflow->revision !== $expectedRevision ||
            $approvedWorkflow->artifactDigest === null) {
            throw new DomainException('style_revision_requires_current_internal_review');
        }

        // Never infer an approved source version from the caller's style
        // label. Check its exact renderer bytes and the approved workflow.
        $renderer = new StaticSiteRenderer();
        $renderedBefore = $renderer->render($approvedBrief);
        if (!hash_equals($renderedBefore->digest, $approvedArtifact->digest) ||
            !hash_equals($renderedBefore->html, $approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest, $approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html)) {
            throw new DomainException('style_revision_source_bytes_mismatch');
        }

        if ($approvedBrief->stylePreset === $nextStylePreset ||
            $previousReleaseRef === $nextReleaseRef) {
            throw new DomainException('style_revision_must_change_preset_and_release');
        }

        // Validate release identifiers before constructing a candidate.
        $oldRelease = new ReleaseManifest(
            $approvedWorkflow->tenantRef, $approvedWorkflow->siteRef,
            $previousReleaseRef, $approvedArtifact->digest
        );
        $newBrief = new SiteBrief(
            $approvedBrief->productCode,
            $approvedBrief->brand,
            $approvedBrief->headline,
            $approvedBrief->description,
            $approvedBrief->callToAction,
            $approvedBrief->benefits,
            $nextStylePreset,
            $approvedBrief->faq,
        );
        $nextArtifact = $renderer->render($newBrief);
        if (hash_equals($approvedArtifact->digest, $nextArtifact->digest)) {
            throw new DomainException('style_revision_did_not_change_exact_bytes');
        }
        $newRelease = new ReleaseManifest(
            $approvedWorkflow->tenantRef, $approvedWorkflow->siteRef,
            $nextReleaseRef, $nextArtifact->digest
        );

        // Must remain safe and unpublished even after a complete SHA rehash.
        $bundle = new SyntheticReleaseBundle($newRelease, $nextArtifact);
        if (!SyntheticReleaseBundle::verifyFiles($bundle->files, $newRelease)) {
            throw new DomainException('style_revision_bundle_rejected');
        }

        // This action INVALIDATES the earlier digest on the new snapshot.
        // It does not call approve_internal or emit a Portal review proposal.
        $draft = $approvedWorkflow->advance(
            $actorRole, 'return_for_revision', $expectedRevision
        );
        if ($draft->state !== SyntheticProductionWorkflow::DRAFTING ||
            $draft->artifactDigest !== null) {
            throw new DomainException('style_revision_failed_closed');
        }

        return new self(
            $oldRelease->releaseRef,
            $newRelease->releaseRef,
            $approvedArtifact->digest,
            $newBrief,
            $nextArtifact,
            $draft,
        );
    }
}
