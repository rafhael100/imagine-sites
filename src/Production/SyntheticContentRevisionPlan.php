<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-68: a copy edit creates a new, nonpublishable PRIVATE review draft.
 *
 * Synthetic role labels are not authenticated identities; this is neither
 * actual customer acceptance nor a release approval. The Portal remains
 * authoritative over client acceptance and the final delivery event.
 */
final readonly class SyntheticContentRevisionPlan
{
    /**
     * @param list<string> $changedFields Exact source fields, not editorial heuristics.
     */
    private function __construct(
        public string $previousReleaseRef,
        public string $nextReleaseRef,
        public string $previousDigest,
        public SiteBrief $nextBrief,
        public ReleaseArtifact $nextArtifact,
        public array $changedFields,
        public SyntheticProductionWorkflow $draftWorkflow,
    ) {}

    public static function begin(
        SyntheticProductionWorkflow $approvedWorkflow,
        SiteBrief $approvedBrief,
        ReleaseArtifact $approvedArtifact,
        string $previousReleaseRef,
        SiteBrief $nextBrief,
        string $nextReleaseRef,
        string $actorRole,
        int $expectedRevision,
    ): self {
        if ($approvedWorkflow->state !== SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $approvedWorkflow->revision !== $expectedRevision ||
            $approvedWorkflow->artifactDigest === null ||
            $actorRole !== 'imagine_reviewer') {
            throw new DomainException('content_revision_requires_current_internal_review');
        }

        $renderer = new StaticSiteRenderer();
        $source = $renderer->render($approvedBrief);
        if (!hash_equals($source->digest, $approvedArtifact->digest) ||
            !hash_equals($source->html, $approvedArtifact->html) ||
            !hash_equals($approvedWorkflow->artifactDigest, $approvedArtifact->digest) ||
            !InertPreviewAudit::isSafe($approvedArtifact->html)) {
            throw new DomainException('content_revision_source_bytes_mismatch');
        }

        // Product and theme changes require separate explicit operations.
        if ($approvedBrief->productCode !== $nextBrief->productCode ||
            $approvedBrief->stylePreset !== $nextBrief->stylePreset ||
            $approvedBrief->sectionOrder !== $nextBrief->sectionOrder ||
            $approvedBrief->sectionNavigation !== $nextBrief->sectionNavigation ||
            $approvedBrief->secondaryCallToAction !== $nextBrief->secondaryCallToAction ||
            $approvedBrief->faqDisclosure !== $nextBrief->faqDisclosure ||
            $approvedBrief->skipToContentLink !== $nextBrief->skipToContentLink ||
            $approvedBrief->brandPalette != $nextBrief->brandPalette ||
            $approvedBrief->socialPreviewMetadata !== $nextBrief->socialPreviewMetadata ||
            $approvedBrief->seoTitle !== $nextBrief->seoTitle ||
            $approvedBrief->seoDescription !== $nextBrief->seoDescription) {
            throw new DomainException('content_revision_cannot_switch_product_or_style');
        }

        $fields = [
            'brand' => [$approvedBrief->brand, $nextBrief->brand],
            'headline' => [$approvedBrief->headline, $nextBrief->headline],
            'description' => [$approvedBrief->description, $nextBrief->description],
            'callToAction' => [$approvedBrief->callToAction, $nextBrief->callToAction],
            'benefits' => [$approvedBrief->benefits, $nextBrief->benefits],
            'faq' => [$approvedBrief->faq, $nextBrief->faq],
            'highlights' => [$approvedBrief->highlights, $nextBrief->highlights],
            'processSteps' => [$approvedBrief->processSteps, $nextBrief->processSteps],
        ];
        $changed = [];
        foreach ($fields as $key => [$before, $after]) {
            if ($before !== $after) $changed[] = $key;
        }
        if ($changed === [] || $previousReleaseRef === $nextReleaseRef) {
            throw new DomainException('content_revision_requires_change_and_new_release');
        }

        $previous = new ReleaseManifest(
            $approvedWorkflow->tenantRef, $approvedWorkflow->siteRef,
            $previousReleaseRef, $approvedArtifact->digest
        );
        $nextArtifact = $renderer->render($nextBrief);
        if (hash_equals($nextArtifact->digest, $approvedArtifact->digest)) {
            throw new DomainException('content_revision_must_change_exact_html_bytes');
        }
        $next = new ReleaseManifest(
            $approvedWorkflow->tenantRef, $approvedWorkflow->siteRef,
            $nextReleaseRef, $nextArtifact->digest
        );
        $bundle = new SyntheticReleaseBundle($next, $nextArtifact);
        if (!SyntheticReleaseBundle::verifyFiles($bundle->files, $next)) {
            throw new DomainException('content_revision_bundle_rejected');
        }

        // Returning for revision discards the formerly approved digest on
        // the NEW immutable workflow snapshot. No accept/deliver/publish call.
        $draft = $approvedWorkflow->advance(
            $actorRole, 'return_for_revision', $expectedRevision
        );
        if ($draft->state !== SyntheticProductionWorkflow::DRAFTING ||
            $draft->artifactDigest !== null) {
            throw new DomainException('content_revision_failed_closed');
        }

        return new self(
            $previous->releaseRef, $next->releaseRef, $approvedArtifact->digest,
            $nextBrief, $nextArtifact, $changed, $draft
        );
    }
}
