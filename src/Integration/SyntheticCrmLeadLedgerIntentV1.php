<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-132: metadata-only idempotency intent derived from the synthetic
 * Sites CRM envelope. Matches CRM F3-12 ledger scope for compatibility.
 *
 * A fingerprint is a non-secret comparison hint, NEVER an authenticated
 * claim, reservation, durable CAS, submission receipt or delivery proof.
 */
final readonly class SyntheticCrmLeadLedgerIntentV1
{
    public function __construct(
        public SyntheticCrmLeadEnvelopeV1Draft $envelope,
    ) {}

    /** Scope is organization + submission; NOT contact, receiver or CRM. */
    public function sameLogicalScopeAs(self $other):bool
    {
        return $this->envelope->organizationReference===
                $other->envelope->organizationReference &&
            $this->envelope->submissionReference===
                $other->envelope->submissionReference;
    }

    /**
     * A retry of the same logical submission may NOT change its binding,
     * site, form or original CRM destination. This only compares claims.
     */
    public function samePinnedContextAs(self $other):bool
    {
        return $this->sameLogicalScopeAs($other) &&
            $this->envelope->bindingReference===$other->envelope->bindingReference &&
            $this->envelope->siteReference===$other->envelope->siteReference &&
            $this->envelope->formReference===$other->envelope->formReference &&
            $this->envelope->destinationReference===$other->envelope->destinationReference;
    }

    public function syntheticLedgerFingerprint():string
    {
        return hash('sha256',implode("\x00",[
            'imagine-synthetic-ledger-v1',
            $this->envelope->organizationReference,
            $this->envelope->submissionReference,
        ]));
    }

    public function canCommitReservation():bool {return false;}
    public function canAcknowledgeVisitor():bool {return false;}
    public function canDeliverToCrm():bool {return false;}
}
