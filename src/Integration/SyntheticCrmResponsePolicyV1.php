<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-278: classify only an already observed HTTP status and an optional
 * primitive Retry-After hint. No network, lead payload, credentials, PII,
 * CRM acknowledgment or signed observation. Not a dispatch permission.
 */
final readonly class SyntheticCrmResponsePolicyV1
{
    private function __construct(
        public string $reasonCode,
        public bool $retryTimeMayBeConsidered,
        public bool $requiresExternalObservation,
        public ?int $providerMinimumWaitSeconds,
    ) {}

    public static function assess(
        int $httpStatus,
        ?string $retryAfter = null,
    ): self {
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new InvalidArgumentException('HTTP status out of range.');
        }

        // 202 means the provider accepted a request for processing, not
        // that a lead is present in CRM or the site release was observed.
        if ($httpStatus === 202) {
            return new self('PROVIDER_202_PENDING_EXTERNAL_OBSERVATION',
                false,true,null);
        }
        if (in_array($httpStatus,[200,201,204],true)) {
            return new self('HTTP_SUCCESS_NOT_CRM_DELIVERY_PROOF',
                false,true,null);
        }
        if ($httpStatus === 409) {
            return new self('IDEMPOTENCY_CONFLICT_REQUIRES_RECONCILIATION',
                false,true,null);
        }
        if (in_array($httpStatus,[400,401,403,404,410,422],true)) {
            return new self('TERMINAL_OR_AUTHORITY_REVIEW_REQUIRED',
                false,true,null);
        }

        // Backoff is only a temporal candidate, never an authority to
        // re-send or to treat the submission as delivered.
        if (in_array($httpStatus,[408,425,429,500,502,503,504],true)) {
            $wait = null;
            if ($retryAfter !== null) {
                if (!preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D',$retryAfter) ||
                    (int)$retryAfter > 86400) {
                    return new self('UNTRUSTED_RETRY_AFTER_REJECTED',
                        false,true,null);
                }
                $wait=(int)$retryAfter;
            }
            return new self('TRANSIENT_RESPONSE_TIME_CANDIDATE',
                true,true,$wait);
        }

        return new self('UNCLASSIFIED_HTTP_RESPONSE_DENIED',
            false,true,null);
    }

    public function mayAcknowledgeVisitor():bool {return false;}
    public function mayConfirmCrmDelivery():bool {return false;}
    public function mayRetryNow():bool {return false;}
    public function mayOverrideDurableT0():bool {return false;}
    public function maySkipCurrentBindingCheck():bool {return false;}
}
