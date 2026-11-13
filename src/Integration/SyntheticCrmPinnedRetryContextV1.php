<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DateTimeImmutable;

/**
 * F1-280: metadata-only retry-context fence for a synthetic CRM submission.
 *
 * The same logical submission MUST retain organization, binding, site,
 * form and destination through each contemplated retry. Comparison of
 * untrusted UUID CLAIMS is not authentication; time passing and matching
 * metadata never grant an API call, acknowledgment or durable receipt.
 */
final readonly class SyntheticCrmPinnedRetryContextV1
{
    private function __construct(
        public string $reasonCode,
        public ?DateTimeImmutable $nextConsiderationAt,
        public bool $sameLogicalScope,
        public bool $samePinnedDestination,
    ) {}

    public static function inspect(
        SyntheticCrmLeadLedgerIntentV1 $original,
        SyntheticCrmLeadLedgerIntentV1 $retry,
        SyntheticCrmRetryWindowV1 $window,
    ): self {
        if (!$original->sameLogicalScopeAs($retry)) {
            return new self('LOGICAL_SUBMISSION_CHANGED_DENIED',null,false,false);
        }
        if (!$original->samePinnedContextAs($retry) ||
            !hash_equals(
                $original->syntheticLedgerFingerprint(),
                $retry->syntheticLedgerFingerprint()
            )) {
            return new self('PINNED_CONTEXT_CHANGED_DENIED',null,true,false);
        }
        if ($window->nextConsiderationAt === null ||
            !$window->timeElapsed ||
            $window->reasonCode !== 'TEMPORAL_CHECK_PASSED_NO_AUTHORITY') {
            return new self('RETRY_TIME_NOT_ELAPSED_OR_UNVERIFIED',
                $window->nextConsiderationAt,true,true);
        }

        return new self(
            'SAME_SYNTHETIC_CONTEXT_AND_TIME_NO_AUTHORITY',
            $window->nextConsiderationAt,
            true,true
        );
    }

    public function mayDispatchToCrm():bool {return false;}
    public function mayChangeCrmDestination():bool {return false;}
    public function mayResetIdempotencyKey():bool {return false;}
    public function mayAcknowledgeVisitor():bool {return false;}
    public function mayTreatBindingAsAuthenticated():bool {return false;}
}
