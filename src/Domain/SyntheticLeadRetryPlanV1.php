<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F1-277: offline retry scheduling for a lead envelope whose durable T0
 * was ALREADY established externally.
 *
 * All fields are advisory timestamps and reason codes. No tenant identity,
 * lead contact, payload, network access, acknowledgment, queue state or
 * delivery authority. This cannot prove durable T0, binding, consent, or
 * current authorization. A caller must never use it as a send permission.
 */
final readonly class SyntheticLeadRetryPlanV1
{
    private function __construct(
        public string $reasonCode,
        public string $deadlineWindow,
        public ?DateTimeImmutable $nextEligibleAt,
        public bool $backoffElapsed,
        // The latest failed attempt's immutable observation anchor (UTC).
        // Used to reject an earlier provider reply reused for a later retry.
        public DateTimeImmutable $lastAttemptAt,
        public int $failedAttempts,
        public DateTimeImmutable $originalT0,
    ) {}

    public static function assess(
        LeadDeadline $durableDeadline,
        DateTimeImmutable $now,
        int $failedAttempts,
        ?DateTimeImmutable $lastAttemptAt,
        bool $transientError,
    ): self {
        if ($failedAttempts < 1 || $failedAttempts > 64 ||
            $lastAttemptAt === null) {
            throw new InvalidArgumentException('Invalid retry history shape.');
        }
        $utc = new DateTimeZone('UTC');
        $current = $now->setTimezone($utc);
        $last = $lastAttemptAt->setTimezone($utc);
        $window = $durableDeadline->window($current);

        // Never infer a successful submission from a caller's timestamps.
        if ($last < $durableDeadline->t0 || $last > $current) {
            return new self('UNVERIFIED_ATTEMPT_HISTORY',$window,null,false,$last,$failedAttempts,$durableDeadline->t0);
        }
        if ($window === 'before_t0') {
            return new self('BEFORE_DURABLE_T0',$window,null,false,$last,$failedAttempts,$durableDeadline->t0);
        }
        if ($window === 'expired') {
            return new self('EXPIRED_UNRECOVERABLE',$window,null,false,$last,$failedAttempts,$durableDeadline->t0);
        }
        if (!$transientError) {
            return new self('TERMINAL_FAILURE',$window,null,false,$last,$failedAttempts,$durableDeadline->t0);
        }

        // Stable no-jitter lower bound, independent of the "now" supplied
        // by a worker. No retry ever extends the original 432h deadline.
        // 5m, 15m, 30m, 1h, 2h, 4h, 8h, 12h, then 24h.
        $delays = [300,900,1800,3600,7200,14400,28800,43200,86400];
        $delay = $delays[min($failedAttempts-1,count($delays)-1)];
        $candidate = $last->modify('+'.$delay.' seconds');
        if ($candidate === false ||
            $candidate >= $durableDeadline->deliveryDeadline) {
            return new self('NEXT_RETRY_OUTSIDE_ORIGINAL_DEADLINE',
                $window,null,false,$last,$failedAttempts,$durableDeadline->t0);
        }

        $due = $current >= $candidate;
        return new self(
            $due ? 'TIME_CANDIDATE_UNVERIFIED' : 'BACKOFF_NOT_ELAPSED',
            $window,$candidate,$due,$last,$failedAttempts,$durableDeadline->t0
        );
    }

    public function mayDispatchToCrm(): bool { return false; }
    public function mayPersistLead(): bool { return false; }
    public function mayAcknowledgeVisitor(): bool { return false; }
    public function mayRebaseOriginalT0(): bool { return false; }
}
