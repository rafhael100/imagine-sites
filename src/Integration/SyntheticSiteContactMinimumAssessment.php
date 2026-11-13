<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-153: aggregate-only form shape result, deliberately carries no PII.
 * "Valid" only means syntactically complete, NOT lawful to collect or deliver.
 */
final readonly class SyntheticSiteContactMinimumAssessment
{
    public function __construct(
        public bool $namePresent,
        public bool $emailCandidateValid,
        public bool $phoneCandidateValid,
        public bool $invalidSuppliedContact,
    ) {}

    public function meetsStructuralMinimum(): bool
    {
        return $this->namePresent
            && ($this->emailCandidateValid || $this->phoneCandidateValid)
            && !$this->invalidSuppliedContact;
    }

    public function canCollectPersonalData(): bool { return false; }
    public function canStorePersonalData(): bool { return false; }
    public function canDeliverToCrm(): bool { return false; }
    public function canAcknowledgeVisitor(): bool { return false; }
}
