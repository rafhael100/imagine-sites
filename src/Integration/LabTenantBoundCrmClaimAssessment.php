<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * CI-only metadata claim: even an actual PostgreSQL row is not a CRM receipt,
 * customer consent, authenticated Portal user or delivery acknowledgment.
 */
final readonly class LabTenantBoundCrmClaimAssessment
{
    private const CODES = [
        'LAB_DISABLED',
        'TRANSACTION_REQUIRED',
        'UNTRUSTED_LAB_CONTEXT',
        'DB_LOGIN_SCOPE_DENIED',
        'INVALID_SYNTHETIC_CLAIM',
        'BINDING_NOT_FOUND',
        'BINDING_INACTIVE',
        'REVISION_CHANGED',
        'BINDING_CONTEXT_MISMATCH',
        'CLAIM_RECORDED_UNVERIFIED',
        'EXISTING_CLAIM_UNVERIFIED',
        'INCONCLUSIVE_CONCURRENT_CLAIM',
        'PINNED_CONTEXT_CONFLICT',
        'INCONCLUSIVE_DATABASE',
        'UNEXPECTED_DATABASE_STATE',
    ];

    public function __construct(public string $reasonCode)
    {
        if (!in_array($reasonCode, self::CODES, true)) {
            throw new InvalidArgumentException('Unsupported lab claim diagnostic.');
        }
    }

    public function canAcceptLead(): bool { return false; }
    public function canAcknowledgeVisitor(): bool { return false; }
    public function canDeliverToCrm(): bool { return false; }
    public function canRetryAutomatically(): bool { return false; }

    public static function safeCode(string $candidate): string
    {
        return in_array($candidate, self::CODES, true)
            ? $candidate : 'UNEXPECTED_DATABASE_STATE';
    }
}
