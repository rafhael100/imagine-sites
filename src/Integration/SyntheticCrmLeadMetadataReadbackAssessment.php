<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-136: a diagnostic-only result for the synthetic F1-133/134 laboratory.
 * Neither a recognized diagnostic nor metadata parity implies CRM delivery.
 * Unexpected labels are rejected rather than silently treated as new states.
 */
final readonly class SyntheticCrmLeadMetadataReadbackAssessment
{
    private const REASONS = [
        'LAB_DISABLED',
        'TRANSACTION_REQUIRED',
        'UNTRUSTED_LAB_CONTEXT',
        'NO_STORED_METADATA',
        'PINNED_CONTEXT_CONFLICT',
        'METADATA_MATCH_UNVERIFIED',
        'INCONCLUSIVE_DUPLICATE',
        'INCONCLUSIVE_DATABASE',
    ];

    public string $reasonCode;

    public function __construct(string $reasonCode)
    {
        if (!in_array($reasonCode, self::REASONS, true)) {
            throw new InvalidArgumentException('Unsupported synthetic readback diagnostic.');
        }
        $this->reasonCode = $reasonCode;
    }

    public function canReserveSubmission(): bool { return false; }
    public function canAcknowledgeVisitor(): bool { return false; }
    public function canMarkDeliveredToCrm(): bool { return false; }
    public function canRetryAutomatically(): bool { return false; }
}
