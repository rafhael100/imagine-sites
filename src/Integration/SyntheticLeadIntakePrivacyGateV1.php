<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-263: connect form-safety diagnostics, claimed CRM metadata scope and
 * the (still untrusted) binding check without crossing any authority boundary.
 *
 * Identity/consent/revocation must be checked by future authenticated
 * independent Portal/CRM services before any durable action; no side effects.
 */
final readonly class SyntheticLeadIntakePrivacyGateV1
{
    public function __construct(
        private ?SyntheticLeadBindingAuthoritySource $bindingSource=null,
    ) {}

    public function inspect(
        SyntheticCrmLeadEnvelopeV1Draft $candidate,
        SyntheticContactPrivacyPreflightV1 $privacy,
        ?SyntheticCrmLeadEnvelopeV1Draft $originalSubmission=null,
    ):SyntheticLeadIntakePrivacyAssessmentV1 {
        // A repeat of the same submission must not move to a different
        // tenant, binding, site, form or CRM destination. This checks caller
        // claims only, not a durable idempotency ledger.
        if ($originalSubmission!==null) {
            $current=new SyntheticCrmLeadLedgerIntentV1($candidate);
            $original=new SyntheticCrmLeadLedgerIntentV1($originalSubmission);
            if (!$current->sameLogicalScopeAs($original)) {
                return new SyntheticLeadIntakePrivacyAssessmentV1(
                    'RETRY_SUBMISSION_SCOPE_MISMATCH'
                );
            }
            if (!$current->samePinnedContextAs($original)) {
                return new SyntheticLeadIntakePrivacyAssessmentV1(
                    'RETRY_BINDING_CONTEXT_CHANGED'
                );
            }
        }
        if (!$privacy->readyForIndependentReview() ||
            !$privacy->structuralMinimumMet ||
            !$privacy->consentIntentObserved ||
            !$privacy->honeypotClear) {
            return new SyntheticLeadIntakePrivacyAssessmentV1(
                'CONTACT_PRIVACY_REVIEW_INCOMPLETE'
            );
        }
        $binding=(new SyntheticLeadBindingAuthorityPreflight(
            $this->bindingSource
        ))->inspect($candidate);
        if ($binding->reasonCode!=='AUTHORITY_MATCH_UNVERIFIED') {
            return new SyntheticLeadIntakePrivacyAssessmentV1(
                'BINDING_'. $binding->reasonCode
            );
        }
        // Not an authenticated service proof, consent receipt or delivery
        // permit. A future flow requires source trust, T0 durable lock,
        // retention controls, channel contracts and CRM acknowledgement.
        return new SyntheticLeadIntakePrivacyAssessmentV1(
            'S2S_REVIEW_CANDIDATE_UNVERIFIED'
        );
    }
}
