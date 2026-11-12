<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Imagine\Sites\Domain\LeadDeadline;

/**
 * CRM-POSTBIND-T0-01: final *advisory* temporal check AFTER the two
 * synthetic binding reads. In a real integration the authenticated time
 * and immutable T0 must be read from a durable source at dispatch/CAS time.
 *
 * No client identity, durable claim, CRM connection or sending authority.
 */
final readonly class SyntheticCrmPostBindingDeadlineFenceV1
{
    private function __construct(
        public string $reasonCode,
        public string $deadlineWindow,
        public ?DateTimeImmutable $lastCheckedAt,
        public bool $syntheticTimeStillWithinDeadline,
    ) {}

    public static function inspect(
        LeadDeadline $originalDeadline,
        SyntheticCrmRetryWindowV1 $earlierRetryWindow,
        SyntheticCrmRetryBindingRecheckV1 $bindingRecheck,
        DateTimeImmutable $bindingReadStartedAt,
        DateTimeImmutable $bindingReadFinishedAt,
    ):self {
        $utc=new DateTimeZone('UTC');
        $start=$bindingReadStartedAt->setTimezone($utc);
        $finish=$bindingReadFinishedAt->setTimezone($utc);
        $window=$originalDeadline->window($finish);

        if ($earlierRetryWindow->reasonCode!=='TEMPORAL_CHECK_PASSED_NO_AUTHORITY' ||
            !$earlierRetryWindow->timeElapsed ||
            $earlierRetryWindow->nextConsiderationAt===null) {
            return new self('EARLIER_RETRY_WINDOW_NOT_VERIFIED',
                $window,null,false);
        }

        if ($bindingRecheck->reasonCode!==
                'LAB_CANDIDATE_STILL_REQUIRES_AUTHENTICATED_AUTHORITY' ||
            $bindingRecheck->bindingDiagnostic!=='STABLE_MATCH_UNVERIFIED' ||
            !$bindingRecheck->syntheticContextMatched) {
            return new self('BINDING_RECHECK_NOT_VERIFIED',
                $window,null,false);
        }

        $due=$earlierRetryWindow->nextConsiderationAt->setTimezone($utc);
        if ($due<$originalDeadline->t0 ||
            $due>=$originalDeadline->deliveryDeadline ||
            $start<$originalDeadline->t0 ||
            $finish<$start ||
            $start<$due) {
            return new self('POST_BINDING_CLOCK_OR_DUE_UNVERIFIED',
                $window,null,false);
        }

        // Expiry is EXCLUSIVE. Even a successful metadata recheck cannot
        // reach dispatch when a slow source finishes at/after T0+432h.
        if ($finish>=$originalDeadline->deliveryDeadline) {
            return new self('ORIGINAL_T0_EXPIRED_DURING_BINDING_RECHECK',
                'expired',$finish,false);
        }

        return new self(
            'SYNTHETIC_POST_BINDING_TIME_OK_NO_AUTHORITY',
            $window,$finish,true
        );
    }

    public function mayDispatchToCrm():bool {return false;}
    public function mayAcknowledgeVisitor():bool {return false;}
    public function mayMarkLeadDelivered():bool {return false;}
    public function mayExtendOriginalT0():bool {return false;}
    public function mayTreatClockAsAuthenticated():bool {return false;}
}
