<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Throwable;

/**
 * F1-139 lab-only, two-read revision consistency probe.
 *
 * NOTE: an injected source is not authenticated by this code. A real source
 * must provide a durable, monotonic revision; two reads are NOT an atomic
 * authorization or a substitute for an authenticated transactional check.
 */
final class SyntheticLeadBindingRevisionFence
{
    public function __construct(
        private readonly ?SyntheticLeadBindingAuthoritySource $source = null
    ) {}

    public function inspect(
        SyntheticCrmLeadEnvelopeV1Draft $incoming
    ): SyntheticLeadBindingRevisionAssessment {
        if ($this->source === null) {
            return new SyntheticLeadBindingRevisionAssessment('AUTHORITY_UNAVAILABLE');
        }

        try {
            $first = $this->source->currentBinding(
                $incoming->organizationReference,
                $incoming->bindingReference
            );
        } catch (Throwable) {
            return new SyntheticLeadBindingRevisionAssessment('AUTHORITY_UNAVAILABLE');
        }
        if ($first === null) {
            return new SyntheticLeadBindingRevisionAssessment('BINDING_NOT_FOUND');
        }
        if (!$first->active || $first->revision <= 0) {
            return new SyntheticLeadBindingRevisionAssessment('BINDING_INACTIVE');
        }
        if (!$this->matches($incoming, $first)) {
            return new SyntheticLeadBindingRevisionAssessment('BINDING_CONTEXT_MISMATCH');
        }

        try {
            $second = $this->source->currentBinding(
                $incoming->organizationReference,
                $incoming->bindingReference
            );
        } catch (Throwable) {
            return new SyntheticLeadBindingRevisionAssessment('AUTHORITY_UNAVAILABLE');
        }
        if ($second === null) {
            return new SyntheticLeadBindingRevisionAssessment('REVALIDATION_MISSING');
        }
        if (!$second->active || $second->revision <= 0) {
            return new SyntheticLeadBindingRevisionAssessment('REVALIDATION_INACTIVE');
        }
        if ($second->revision !== $first->revision) {
            return new SyntheticLeadBindingRevisionAssessment('REVISION_CHANGED');
        }
        if (!$this->matches($incoming, $second)) {
            return new SyntheticLeadBindingRevisionAssessment('REVALIDATION_CONTEXT_MISMATCH');
        }

        // A match is a synthetic comparison ONLY, not an authorization.
        return new SyntheticLeadBindingRevisionAssessment('STABLE_MATCH_UNVERIFIED');
    }

    private function matches(
        SyntheticCrmLeadEnvelopeV1Draft $incoming,
        SyntheticLeadBindingAuthoritySnapshot $snapshot
    ): bool {
        return $snapshot->organizationReference === $incoming->organizationReference
            && $snapshot->bindingReference === $incoming->bindingReference
            && $snapshot->siteReference === $incoming->siteReference
            && $snapshot->formReference === $incoming->formReference
            && $snapshot->destinationReference === $incoming->destinationReference;
    }
}
