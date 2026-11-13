<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-281: synthetically recheck a CRM binding AFTER the same-submission,
 * same-destination and original-T0 candidate pass.
 *
 * Two mutable reads do not create an atomic or authenticated authorization.
 * The source is injected and therefore untrusted. The only outcome is a
 * metadata-only diagnostic, NEVER a dispatch or acknowledgment.
 */
final readonly class SyntheticCrmRetryBindingRecheckV1
{
    private function __construct(
        public string $reasonCode,
        public string $bindingDiagnostic,
        public bool $syntheticContextMatched,
    ) {}

    public static function inspect(
        SyntheticCrmLeadLedgerIntentV1 $original,
        SyntheticCrmLeadLedgerIntentV1 $retry,
        SyntheticCrmRetryWindowV1 $window,
        ?SyntheticLeadBindingAuthoritySource $authoritySource,
    ):self {
        $context=SyntheticCrmPinnedRetryContextV1::inspect(
            $original,$retry,$window
        );
        if (!$context->sameLogicalScope || !$context->samePinnedDestination) {
            return new self(
                'SYNTHETIC_SUBMISSION_CONTEXT_CHANGED',
                'NOT_CHECKED',false
            );
        }
        if ($context->reasonCode!==
                'SAME_SYNTHETIC_CONTEXT_AND_TIME_NO_AUTHORITY') {
            return new self(
                'ORIGINAL_T0_OR_PROVIDER_BACKOFF_NOT_READY',
                'NOT_CHECKED',true
            );
        }
        if ($authoritySource===null) {
            return new self(
                'CURRENT_BINDING_SOURCE_UNAVAILABLE',
                'AUTHORITY_UNAVAILABLE',true
            );
        }

        $binding=(new SyntheticLeadBindingRevisionFence(
            $authoritySource
        ))->inspect($retry->envelope);

        if ($binding->reasonCode!=='STABLE_MATCH_UNVERIFIED') {
            return new self(
                'CURRENT_BINDING_REVOKED_CHANGED_OR_UNVERIFIED',
                $binding->reasonCode,true
            );
        }
        return new self(
            'LAB_CANDIDATE_STILL_REQUIRES_AUTHENTICATED_AUTHORITY',
            $binding->reasonCode,true
        );
    }

    public function mayContactCrm():bool {return false;}
    public function mayReserveDurableNonce():bool {return false;}
    public function mayAcknowledgeVisitor():bool {return false;}
    public function mayMarkLeadDelivered():bool {return false;}
    public function mayApproveBinding():bool {return false;}
}
