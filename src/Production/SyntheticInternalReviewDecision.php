<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use InvalidArgumentException;
use JsonException;

/**
 * F1-76: explicit, immutable SYNTHETIC internal review decision and digest.
 *
 * These reviewer labels are NOT authenticated identities or signatures.
 * This is evidence-shaping for a future authorized Portal/Sites workflow,
 * never a client approval, trusted event, or permission to publish.
 */
final readonly class SyntheticInternalReviewDecision
{
    /** @param list<string> $acknowledgedEditorialKeys
     *  @param array<string,mixed> $record
     */
    private function __construct(
        public SyntheticProductionWorkflow $nextWorkflow,
        public string $reviewDigest,
        public array $acknowledgedEditorialKeys,
        public array $record,
    ) {}

    /**
     * @param list<string> $acknowledgedEditorialKeys
     */
    public static function decide(
        SyntheticStructuredDraftAssembly $draft,
        string $actorRole,
        string $reviewerRef,
        string $decisionRef,
        string $action,
        int $expectedRevision,
        string $reviewedArtifactDigest,
        string $reviewedBundleDigest,
        array $acknowledgedEditorialKeys,
        ?string $reworkReason = null,
    ): self {
        if ($actorRole !== 'imagine_reviewer' ||
            $draft->workflow->state !== SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $draft->workflow->revision !== $expectedRevision ||
            $draft->workflow->artifactDigest === null) {
            throw new DomainException('internal_review_requires_current_reviewer_and_revision');
        }
        foreach ([$reviewerRef, $decisionRef] as $ref) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref)) {
                throw new InvalidArgumentException('Invalid fictional review reference');
            }
        }
        if ($action !== 'approve_internal' && $action !== 'request_rework') {
            throw new DomainException('unsupported_internal_review_decision');
        }
        $rerendered = (new StaticSiteRenderer())->render($draft->brief);
        if (!hash_equals($draft->artifact->digest, $reviewedArtifactDigest) ||
            !hash_equals($draft->bundle->bundleDigest, $reviewedBundleDigest) ||
            !hash_equals($draft->artifact->digest, $rerendered->digest) ||
            !hash_equals($draft->artifact->html, $rerendered->html) ||
            !hash_equals($draft->workflow->artifactDigest, $draft->artifact->digest) ||
            !hash_equals($draft->manifest->digest, $draft->artifact->digest) ||
            !SyntheticReleaseBundle::verifyFiles($draft->bundle->files, $draft->manifest) ||
            !hash_equals($draft->bundle->bundleDigest, hash('sha256',
                $draft->bundle->files['index.html']."\0".
                $draft->bundle->files['release-manifest.json']
            ))) {
            throw new DomainException('internal_review_exact_source_bundle_required');
        }

        $freshQa = SyntheticPreviewQaReport::inspect($draft->artifact);
        $freshEditorial = SyntheticEditorialReviewReport::inspect($draft->brief, $draft->artifact);
        if (!$freshQa->passedStructuralChecks ||
            !$draft->structuralReport->passedStructuralChecks ||
            $freshQa->checks !== $draft->structuralReport->checks ||
            $freshEditorial->checks !== $draft->editorialReport->checks ||
            $freshEditorial->suggestions !== $draft->editorialReport->suggestions) {
            throw new DomainException('internal_review_qa_changed_or_failed');
        }
        // All advisory findings have to be acknowledged by exact check keys;
        // a generic "approved" boolean or incomplete acknowledgment is denied.
        $openEditorialKeys = [];
        foreach ($freshEditorial->checks as $key => $passed) {
            if ($passed !== true) $openEditorialKeys[] = $key;
        }
        if ($action === 'approve_internal') {
            if ($reworkReason !== null ||
                !array_is_list($acknowledgedEditorialKeys) ||
                $acknowledgedEditorialKeys !== $openEditorialKeys) {
                throw new DomainException('editorial_findings_require_explicit_acknowledgment');
            }
            $next = $draft->workflow->advance(
                $actorRole, 'approve_internal', $expectedRevision, $reviewedArtifactDigest
            );
            if ($next->state !== SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
                !hash_equals($next->artifactDigest ?? '', $reviewedArtifactDigest)) {
                throw new DomainException('internal_review_did_not_hold_bytes');
            }
        } else {
            if ($acknowledgedEditorialKeys !== [] ||
                !in_array($reworkReason, ['copy_revision', 'style_revision', 'qa_rework'], true)) {
                throw new DomainException('rework_requires_fixed_reason_without_approval');
            }
            $next = $draft->workflow->advance(
                $actorRole, 'request_rework', $expectedRevision
            );
            if ($next->state !== SyntheticProductionWorkflow::DRAFTING ||
                $next->artifactDigest !== null) {
                throw new DomainException('rework_must_invalidate_approved_digest');
            }
        }
        $record = [
            'schemaVersion' => 'sites.synthetic.internal-review-decision.v1',
            'tenantRef' => $draft->workflow->tenantRef,
            'siteRef' => $draft->workflow->siteRef,
            'workflowRef' => $draft->workflow->workflowRef,
            'releaseRef' => $draft->manifest->releaseRef,
            'reviewedWorkflowRevision' => $expectedRevision,
            'reviewedArtifactDigest' => $reviewedArtifactDigest,
            'reviewedBundleDigest' => $reviewedBundleDigest,
            'decisionRef' => $decisionRef,
            'syntheticReviewerRef' => $reviewerRef,
            'action' => $action,
            'reworkReason' => $reworkReason,
            'acknowledgedEditorialKeys' => $acknowledgedEditorialKeys,
            'nextWorkflowRevision' => $next->revision,
            'nextWorkflowState' => $next->state,
            'portalAuthorityRequired' => true,
            'syntheticOnly' => true,
            'officialClientAcceptance' => false,
            'publishable' => false,
        ];
        try {
            $bytes = json_encode($record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new DomainException('internal_review_record_serialization_failed', previous:$e);
        }
        return new self($next, hash('sha256', $bytes), $acknowledgedEditorialKeys, $record);
    }
}
