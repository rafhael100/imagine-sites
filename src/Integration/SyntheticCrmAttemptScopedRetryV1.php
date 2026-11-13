<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\SyntheticLeadRetryPlanV1;

/**
 * F1-318: constrain a synthetic HTTP observation to the SAME failed attempt
 * as the immutable backoff plan, not merely to a plausible timestamp.
 *
 * Source attempt numbers are caller claims, NOT authentic event receipts.
 * No CRM connection, queue, lead payload, claim, consumer authorization or
 * side effects are implemented by this diagnostic object.
 */
final readonly class SyntheticCrmAttemptScopedRetryV1
{
    private function __construct(
        public string $reasonCode,
        public ?DateTimeImmutable $nextConsiderationAt,
        public bool $timeElapsed,
        public bool $sameSyntheticAttempt,
        public DateTimeImmutable $originalT0,
    ) {}

    public static function inspect(
        LeadDeadline $originalDeadline,
        SyntheticLeadRetryPlanV1 $retry,
        SyntheticCrmResponsePolicyV1 $response,
        int $responseAttemptNumber,
        DateTimeImmutable $providerObservedAt,
        DateTimeImmutable $now,
    ):self {
        // The failed-attempt count is capped at 64 by the retry-plan builder;
        // a separate response MUST refer to the same sequence.
        if ($responseAttemptNumber < 1 || $responseAttemptNumber > 64 ||
            $responseAttemptNumber !== $retry->failedAttempts) {
            return new self('SYNTHETIC_ATTEMPT_SEQUENCE_MISMATCH',null,false,false,$originalDeadline->t0);
        }

        $utc=new DateTimeZone('UTC');
        $observed=$providerObservedAt->setTimezone($utc);
        if ($observed<$retry->lastAttemptAt->setTimezone($utc)) {
            return new self('RESPONSE_BEFORE_PINNED_FAILED_ATTEMPT',null,false,true,$originalDeadline->t0);
        }
        $current=$now->setTimezone($utc);
        if ($originalDeadline->window($current)==='expired') {
            return new self('ORIGINAL_T0_432H_EXPIRED',null,false,true,$originalDeadline->t0);
        }
        // F1-279 still validates the independent provider clock, 202/409,
        // original 432h cut-off and non-resetting Retry-After lower bound.
        $window=SyntheticCrmRetryWindowV1::inspect(
            $originalDeadline,$retry,$response,$observed,$current
        );
        if ($window->reasonCode==='TEMPORAL_CHECK_PASSED_NO_AUTHORITY') {
            return new self('SAME_ATTEMPT_TIME_CANDIDATE_NO_AUTHORITY',
                $window->nextConsiderationAt,true,true,$originalDeadline->t0);
        }
        return new self('ATTEMPT_BOUND_'. $window->reasonCode,
            $window->nextConsiderationAt,false,true,$originalDeadline->t0);
    }

    public function mayDispatchToCrm():bool {return false;}
    public function mayConfirmDelivery():bool {return false;}
    public function mayAcknowledgeVisitor():bool {return false;}
    public function mayRebaseOriginalT0():bool {return false;}
    public function mayAuthenticateResponse():bool {return false;}
}
