<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-263: status-only, never a durable lead record or authorization.
 * No caller-supplied metadata, customer fields or raw consent is retained.
 */
final readonly class SyntheticLeadIntakePrivacyAssessmentV1
{
    public function __construct(public string $reasonCode) {}

    public function readyForIndependentS2SReview():bool
    {
        return $this->reasonCode==='S2S_REVIEW_CANDIDATE_UNVERIFIED';
    }
    public function canStoreContact():bool {return false;}
    public function canRecordLegalConsent():bool {return false;}
    public function canReserveSubmission():bool {return false;}
    public function canSendToCrm():bool {return false;}
    public function canAcknowledgeVisitor():bool {return false;}
}
