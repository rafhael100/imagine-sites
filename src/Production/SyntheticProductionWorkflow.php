<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use InvalidArgumentException;

/**
 * F1-28: immutable, SYNTHETIC in-memory operator/agent production workflow.
 *
 * Roles passed into this class are untrusted fixture labels, not authenticated
 * identities. It never approves on a client's behalf, executes an AI provider,
 * sends a Portal request, or makes a release publishable.
 */
final readonly class SyntheticProductionWorkflow
{
    public const QUEUED = 'queued';
    public const DRAFTING = 'drafting';
    public const INTERNAL_REVIEW = 'internal_review';
    public const AWAITING_PORTAL_ACCEPTANCE = 'awaiting_portal_acceptance';

    private function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $workflowRef,
        public string $state,
        public int $revision,
        public int $draftAttempts,
        public int $maxDraftAttempts,
        public ?string $artifactDigest,
    ) {}

    public static function create(
        string $tenantRef,
        string $siteRef,
        string $workflowRef,
        int $maxDraftAttempts = 3,
    ): self {
        foreach ([$tenantRef, $siteRef, $workflowRef] as $value) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value)) {
                throw new InvalidArgumentException('Invalid opaque workflow reference.');
            }
        }
        if ($maxDraftAttempts < 1 || $maxDraftAttempts > 10) {
            throw new InvalidArgumentException('Synthetic drafting budget must be 1..10.');
        }
        return new self($tenantRef,$siteRef,$workflowRef,self::QUEUED,0,0,
            $maxDraftAttempts,null);
    }

    /**
     * @throws DomainException if action/actor/version/bytes are not permitted
     * @return self NEW value object; never mutates an existing revision.
     */
    public function advance(
        string $actorRole,
        string $action,
        int $expectedRevision,
        ?string $artifactDigest = null,
    ): self {
        if ($expectedRevision !== $this->revision) {
            throw new DomainException('stale_production_revision');
        }

        $nextState = null;
        $nextAttempts = $this->draftAttempts;
        $nextDigest = $this->artifactDigest;

        if ($this->state === self::QUEUED &&
            $action === 'start_draft' && in_array($actorRole, ['imagine_editor','agent'], true)) {
            $nextState = self::DRAFTING;
            ++$nextAttempts;
            $nextDigest = null;
        } elseif ($this->state === self::DRAFTING &&
            $action === 'submit_draft' && in_array($actorRole, ['imagine_editor','agent'], true)) {
            if ($artifactDigest === null || !preg_match('/\A[a-f0-9]{64}\z/D', $artifactDigest)) {
                throw new DomainException('invalid_candidate_digest');
            }
            $nextState = self::INTERNAL_REVIEW;
            $nextDigest = $artifactDigest;
        } elseif ($this->state === self::INTERNAL_REVIEW &&
            $action === 'request_rework' && $actorRole === 'imagine_reviewer') {
            $nextState = self::DRAFTING;
            ++$nextAttempts;
            $nextDigest = null;
        } elseif ($this->state === self::INTERNAL_REVIEW &&
            $action === 'approve_internal' && $actorRole === 'imagine_reviewer') {
            if ($this->artifactDigest === null || $artifactDigest === null ||
                !hash_equals($this->artifactDigest, $artifactDigest)) {
                throw new DomainException('candidate_digest_mismatch');
            }
            $nextState = self::AWAITING_PORTAL_ACCEPTANCE;
        } elseif ($this->state === self::AWAITING_PORTAL_ACCEPTANCE &&
            $action === 'return_for_revision' && $actorRole === 'imagine_reviewer') {
            $nextState = self::DRAFTING;
            ++$nextAttempts;
            $nextDigest = null;
        } else {
            throw new DomainException('forbidden_workflow_transition');
        }

        if ($nextAttempts > $this->maxDraftAttempts) {
            throw new DomainException('synthetic_draft_budget_exhausted');
        }
        if ($action !== 'submit_draft' && $action !== 'approve_internal' &&
            $artifactDigest !== null) {
            throw new DomainException('unexpected_artifact_digest');
        }

        return new self($this->tenantRef,$this->siteRef,$this->workflowRef,
            $nextState,$this->revision+1,$nextAttempts,$this->maxDraftAttempts,$nextDigest);
    }

    /**
     * An informational request candidate, NOT a trusted event or approval.
     * The Portal independently verifies owner, contract, entitlement, version,
     * release bytes and express acceptance before any official state change.
     *
     * @return array<string,string|int|bool>
     */
    public function portalReviewCandidate(
        string $releaseRef,
        string $expectedDigest,
        int $expectedRevision,
    ): array {
        if ($this->state !== self::AWAITING_PORTAL_ACCEPTANCE ||
            $expectedRevision !== $this->revision ||
            $this->artifactDigest === null ||
            !hash_equals($this->artifactDigest, $expectedDigest) ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $releaseRef)) {
            throw new DomainException('not_eligible_for_portal_review_candidate');
        }
        return [
            'schemaVersion'=>'sites.synthetic.review-candidate.v1',
            'tenantRef'=>$this->tenantRef,
            'siteRef'=>$this->siteRef,
            'workflowRef'=>$this->workflowRef,
            'workflowRevision'=>$this->revision,
            'releaseRef'=>$releaseRef,
            'artifactDigest'=>$this->artifactDigest,
            'portalAuthorityRequired'=>true,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
        ];
    }
}
