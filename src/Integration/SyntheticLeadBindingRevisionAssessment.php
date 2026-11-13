<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-139 diagnostic-only outcome; cannot grant operational authority.
 */
final readonly class SyntheticLeadBindingRevisionAssessment
{
    private const REASONS = [
        'AUTHORITY_UNAVAILABLE',
        'BINDING_NOT_FOUND',
        'BINDING_INACTIVE',
        'BINDING_CONTEXT_MISMATCH',
        'REVALIDATION_MISSING',
        'REVALIDATION_INACTIVE',
        'REVALIDATION_CONTEXT_MISMATCH',
        'REVISION_CHANGED',
        'STABLE_MATCH_UNVERIFIED',
    ];

    public string $reasonCode;

    public function __construct(string $reasonCode)
    {
        if (!in_array($reasonCode, self::REASONS, true)) {
            throw new InvalidArgumentException('Unsupported synthetic authority fence diagnostic.');
        }
        $this->reasonCode = $reasonCode;
    }

    public function canAcceptLead(): bool { return false; }
    public function canAcknowledgeVisitor(): bool { return false; }
    public function canDeliverToCrm(): bool { return false; }
    public function canRetryAutomatically(): bool { return false; }
}
