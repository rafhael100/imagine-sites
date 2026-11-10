<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use JsonException;

/**
 * F1-77: a private, read-only editorial dossier for a synthetic internal draft.
 *
 * The dossier is neither a reviewer decision nor a trusted signature.
 * It reveals only synthetic identifiers, digests and generic QA check names;
 * the preview HTML remains a separate inert, content-addressed file.
 */
final readonly class SyntheticInternalReviewDossier
{
    /** @param array<string,mixed> $record */
    private function __construct(
        public array $record,
        public string $json,
        public string $digest,
    ) {}

    public static function fromDraft(SyntheticStructuredDraftAssembly $draft): self
    {
        if ($draft->workflow->state !== SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $draft->workflow->revision !== 2 ||
            $draft->workflow->artifactDigest === null ||
            !hash_equals($draft->workflow->artifactDigest, $draft->artifact->digest) ||
            !hash_equals($draft->manifest->digest, $draft->artifact->digest) ||
            !SyntheticReleaseBundle::verifyFiles($draft->bundle->files, $draft->manifest)) {
            throw new DomainException('review_dossier_requires_integral_pending_draft');
        }

        $recomputed = (new StaticSiteRenderer())->render($draft->brief);
        $qa = SyntheticPreviewQaReport::inspect($draft->artifact);
        $editorial = SyntheticEditorialReviewReport::inspect($draft->brief, $draft->artifact);
        if (!hash_equals($recomputed->digest, $draft->artifact->digest) ||
            !hash_equals($recomputed->html, $draft->artifact->html) ||
            !$qa->passedStructuralChecks ||
            $qa->checks !== $draft->structuralReport->checks ||
            $editorial->checks !== $draft->editorialReport->checks ||
            $editorial->suggestions !== $draft->editorialReport->suggestions) {
            throw new DomainException('review_dossier_source_or_qa_mismatch');
        }
        $openKeys = [];
        foreach ($editorial->checks as $key => $passed) {
            if ($passed !== true) $openKeys[] = $key;
        }
        $record = [
            'schemaVersion' => 'sites.synthetic.internal-review-dossier.v1',
            'tenantRef' => $draft->workflow->tenantRef,
            'siteRef' => $draft->workflow->siteRef,
            'workflowRef' => $draft->workflow->workflowRef,
            'workflowRevision' => $draft->workflow->revision,
            'releaseRef' => $draft->manifest->releaseRef,
            'artifactDigest' => $draft->artifact->digest,
            'bundleDigest' => $draft->bundle->bundleDigest,
            'qaChecks' => $qa->checks,
            'editorialChecks' => $editorial->checks,
            'editorialSuggestions' => $editorial->suggestions,
            'requiredReviewerAcknowledgments' => $openKeys,
            'reviewerDecisionStatus' => 'pending',
            'explicitInternalReviewRequired' => true,
            'portalAuthorityRequired' => true,
            'syntheticOnly' => true,
            'officialClientAcceptance' => false,
            'publishable' => false,
        ];
        try {
            $json = json_encode(
                $record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )."\n";
        } catch (JsonException $e) {
            throw new DomainException('review_dossier_serialization_failed', previous:$e);
        }
        return new self($record, $json, hash('sha256', $json));
    }
}
