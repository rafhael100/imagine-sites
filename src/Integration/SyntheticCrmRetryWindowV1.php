<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\SyntheticLeadRetryPlanV1;

/**
 * F1-279: conservative composition of the independent T0 backoff and a
 * CRM provider's untrusted Retry-After seconds. Synthetic scheduling only.
 *
 * The provider observation timestamp must be grounded in a durable,
 * authenticated event by a future caller. It cannot be inferred here.
 * NO authority to send, persist, process, acknowledge or commit a lead.
 */
final readonly class SyntheticCrmRetryWindowV1
{
    private function __construct(
        public string $reasonCode,
        public ?DateTimeImmutable $nextConsiderationAt,
        public bool $timeElapsed,
        // F1-320: pin the ORIGINAL 72h/432h time origin across components.
        public DateTimeImmutable $originalT0,
    ) {}

    public static function inspect(
        LeadDeadline $originalDeadline,
        SyntheticLeadRetryPlanV1 $retry,
        SyntheticCrmResponsePolicyV1 $response,
        DateTimeImmutable $providerResponseObservedAt,
        DateTimeImmutable $now,
    ):self {
        $utc = new DateTimeZone('UTC');
        $observed = $providerResponseObservedAt->setTimezone($utc);
        $current = $now->setTimezone($utc);
        // F1-321: the retry plan and the independent window must come
        // from the SAME original first-persisted T0, not just matching due.
        if ($retry->originalT0->getTimestamp()!==
                $originalDeadline->t0->getTimestamp()) {
            return new self('RETRY_PLAN_ORIGINAL_T0_MISMATCH',null,false,
                $originalDeadline->t0);
        }
        if ($observed < $originalDeadline->t0 ||
            $observed > $current ||
            $current < $originalDeadline->t0) {
            return new self('UNVERIFIED_RESPONSE_OBSERVATION',null,false,$originalDeadline->t0);
        }
        if ($current >= $originalDeadline->deliveryDeadline) {
            return new self('ORIGINAL_T0_WINDOW_EXPIRED',null,false,$originalDeadline->t0);
        }
        // F1-317: a response from an earlier failed attempt must never be
        // reused to shorten the wait for a later one. Read-only UTC evidence
        // only: it cannot prove that the response belongs to this attempt.
        if ($observed < $retry->lastAttemptAt->setTimezone($utc)) {
            return new self('STALE_PROVIDER_REPLY_BEFORE_LATEST_ATTEMPT',null,false,$originalDeadline->t0);
        }
        if (!$response->retryTimeMayBeConsidered) {
            return new self('RESPONSE_NOT_RETRY_CANDIDATE',null,false,$originalDeadline->t0);
        }
        if (!in_array($retry->reasonCode,
                ['TIME_CANDIDATE_UNVERIFIED','BACKOFF_NOT_ELAPSED'],true) ||
            $retry->nextEligibleAt===null ||
            !in_array($retry->deadlineWindow,['initial','recovery'],true)) {
            return new self('RETRY_HISTORY_NOT_VERIFIED',null,false,$originalDeadline->t0);
        }

        $due = $retry->nextEligibleAt->setTimezone($utc);
        if ($due < $originalDeadline->t0 ||
            $due >= $originalDeadline->deliveryDeadline) {
            return new self('RETRY_OUTSIDE_ORIGINAL_T0_WINDOW',null,false,$originalDeadline->t0);
        }

        // The provider's lower bound uses the ORIGINAL response observation
        // timestamp, NOT this poll's "now". Polling cannot reset the clock.
        $extra = $response->providerMinimumWaitSeconds;
        if ($extra!==null) {
            if ($extra < 0 || $extra > 86400) {
                return new self('PROVIDER_WAIT_NOT_VERIFIED',null,false,$originalDeadline->t0);
            }
            $providerDue=$observed->modify('+'.$extra.' seconds');
            if ($providerDue===false) {
                return new self('PROVIDER_WAIT_NOT_VERIFIED',null,false,$originalDeadline->t0);
            }
            if ($providerDue > $due) $due=$providerDue;
        }

        if ($due >= $originalDeadline->deliveryDeadline) {
            return new self('RETRY_EXCEEDS_ORIGINAL_432H_DEADLINE',
                null,false,$originalDeadline->t0);
        }
        return new self(
            $current >= $due
                ? 'TEMPORAL_CHECK_PASSED_NO_AUTHORITY'
                : 'WAITING_FOR_CONSERVATIVE_RETRY_WINDOW',
            $due,$current >= $due,$originalDeadline->t0
        );
    }

    public function mayDispatchToCrm():bool {return false;}
    public function mayPersistLead():bool {return false;}
    public function mayAcknowledgeVisitor():bool {return false;}
    public function mayConfirmDelivery():bool {return false;}
    public function mayExtendOriginalDeadline():bool {return false;}
}
