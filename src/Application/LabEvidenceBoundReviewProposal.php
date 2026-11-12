<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use DomainException;
use Imagine\Sites\Persistence\LabPersistedReviewEvidence;
use Imagine\Sites\Production\SyntheticInternalReviewDecision;
use Imagine\Sites\Production\SyntheticInternalReviewDossier;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Production\SyntheticReviewCandidateGuard;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly;
use PDO;

/**
 * F1-81: an INFORMATIVE, OFFLINE candidate is possible only AFTER a synthetic
 * reviewer decision is bound to its exact dossier AND verified on PG read-back.
 *
 * This method does not issue an authenticated request, sign anything, deliver,
 * obtain Portal authority or record official acceptance. Its output explicitly
 * denies publish/acceptance; real identity/authority remains external.
 */
final class LabEvidenceBoundReviewProposal
{
    /** @return array<string,mixed> */
    public static function prepare(
        PDO $db,
        SyntheticStructuredDraftAssembly $draft,
        SyntheticInternalReviewDecision $decision,
    ):array {
        $record=$decision->record;
        if (($record['action']??null)!=='approve_internal' ||
            $decision->nextWorkflow->state !== SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            ($record['nextWorkflowState']??null)!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            ($record['nextWorkflowRevision']??null)!==$decision->nextWorkflow->revision ||
            ($record['reviewedWorkflowRevision']??null)!==$draft->workflow->revision ||
            ($record['tenantRef']??null)!==$draft->workflow->tenantRef ||
            ($record['siteRef']??null)!==$draft->workflow->siteRef ||
            ($record['workflowRef']??null)!==$draft->workflow->workflowRef ||
            ($record['releaseRef']??null)!==$draft->manifest->releaseRef ||
            ($record['reviewedArtifactDigest']??null)!==$draft->artifact->digest ||
            ($record['reviewedBundleDigest']??null)!==$draft->bundle->bundleDigest ||
            ($record['reviewedDossierDigest']??null)!==SyntheticInternalReviewDossier::fromDraft($draft)->digest ||
            ($record['officialClientAcceptance']??null)!==false ||
            ($record['publishable']??null)!==false) {
            throw new DomainException('lab_persisted_review_candidate_source_mismatch');
        }

        // Reject until a live reader confirms this decision is actually
        // persisted under the CORRECT restricted tenant role.
        $evidence=LabPersistedReviewEvidence::verify($db,$decision);
        if (($evidence['action']??null)!=='approve_internal' ||
            ($evidence['persistedInDisposableLab']??null)!==true ||
            ($evidence['portalAuthorityRequired']??null)!==true ||
            ($evidence['publishable']??null)!==false ||
            ($evidence['officialClientAcceptance']??null)!==false ||
            ($evidence['decisionRecordDigest']??null)!==$decision->reviewDigest) {
            throw new DomainException('lab_persisted_review_candidate_evidence_denied');
        }

        $candidate=$decision->nextWorkflow->portalReviewCandidate(
            $draft->manifest->releaseRef,
            $draft->artifact->digest,
            $decision->nextWorkflow->revision,
        );
        if (!SyntheticReviewCandidateGuard::matchesLocalSnapshot(
            $candidate,$decision->nextWorkflow,$draft->manifest->releaseRef
        ) ||
            $candidate['publishable']!==false ||
            $candidate['officialClientAcceptance']!==false ||
            $candidate['portalAuthorityRequired']!==true) {
            throw new DomainException('lab_persisted_review_candidate_not_informational');
        }

        return [
            'schemaVersion'=>'sites.synthetic.evidence-bound-review-proposal.v1',
            'candidate'=>$candidate,
            'decisionRecordDigest'=>$decision->reviewDigest,
            'dossierDigest'=>$evidence['dossierDigest'],
            'bundleDigest'=>$evidence['bundleDigest'],
            'verifiedInDisposableLab'=>true,
            'externalPortalAuthorityStillRequired'=>true,
            'syntheticOnly'=>true,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
        ];
    }
}
