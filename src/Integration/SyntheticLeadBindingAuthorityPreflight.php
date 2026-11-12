<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Throwable;

/**
 * Compares an untrusted inbound envelope against an independent authority
 * source. Even a match is UNVERIFIED and cannot permit any real operation.
 */
final class SyntheticLeadBindingAuthorityPreflight
{
    public function __construct(
        private readonly ?SyntheticLeadBindingAuthoritySource $source = null
    ) {}

    public function inspect(
        SyntheticCrmLeadEnvelopeV1Draft $incoming
    ): SyntheticLeadBindingAuthorityAssessment {
        if ($this->source === null) {
            return new SyntheticLeadBindingAuthorityAssessment('AUTHORITY_UNAVAILABLE');
        }
        try {
            $snapshot = $this->source->currentBinding(
                $incoming->organizationReference,
                $incoming->bindingReference
            );
        } catch (Throwable) {
            return new SyntheticLeadBindingAuthorityAssessment('AUTHORITY_UNAVAILABLE');
        }
        if ($snapshot === null) {
            return new SyntheticLeadBindingAuthorityAssessment('BINDING_NOT_FOUND');
        }
        if (!$snapshot->active || $snapshot->revision <= 0) {
            return new SyntheticLeadBindingAuthorityAssessment('BINDING_INACTIVE');
        }
        if (
            $snapshot->organizationReference !== $incoming->organizationReference ||
            $snapshot->bindingReference !== $incoming->bindingReference ||
            $snapshot->siteReference !== $incoming->siteReference ||
            $snapshot->formReference !== $incoming->formReference ||
            $snapshot->destinationReference !== $incoming->destinationReference
        ) {
            return new SyntheticLeadBindingAuthorityAssessment('BINDING_CONTEXT_MISMATCH');
        }
        return new SyntheticLeadBindingAuthorityAssessment('AUTHORITY_MATCH_UNVERIFIED');
    }
}
