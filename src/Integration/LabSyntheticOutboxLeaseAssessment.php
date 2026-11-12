<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-147: diagnostic-only lease classification.
 * There is deliberately no operational ALLOW, ACK or delivery capability.
 */
final readonly class LabSyntheticOutboxLeaseAssessment
{
    private const CODES = [
        'LAB_DISABLED',
        'TRANSACTION_REQUIRED',
        'UNTRUSTED_LAB_CONTEXT',
        'INVALID_SYNTHETIC_LEASE',
        'DB_LOGIN_SCOPE_DENIED',
        'NO_READY_SYNTHETIC_INTENT',
        'BINDING_NOT_CURRENT',
        'LEASE_NONCE_REUSED',
        'LEASED_INITIAL_UNVERIFIED',
        'LEASED_RECOVERY_UNVERIFIED',
        'INCONCLUSIVE_DATABASE',
        'UNEXPECTED_DATABASE_STATE',
    ];

    public function __construct(public string $reasonCode)
    {
        if (!in_array($reasonCode, self::CODES, true)) {
            throw new InvalidArgumentException('Unsupported synthetic lease diagnostic.');
        }
    }

    public static function safeCode(string $received): string
    {
        return in_array($received, self::CODES, true)
            ? $received : 'UNEXPECTED_DATABASE_STATE';
    }

    public function canSendLeadToCrm(): bool { return false; }
    public function canAcknowledgeVisitor(): bool { return false; }
    public function canMarkDelivered(): bool { return false; }
    public function canRetryAutomatically(): bool { return false; }
}
