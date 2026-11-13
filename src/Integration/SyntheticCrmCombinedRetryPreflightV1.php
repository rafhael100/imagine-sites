<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-319: ordered, side-effect-free composition for a fictional CRM retry:
 * (1) exact attempt sequence and due time, (2) original submission and
 * destination pins, (3) two current binding checks using an injected source.
 *
 * No step is authenticated, transactional, externally durable or a send
 * permission. This is NOT the operational CRM dispatcher or a customer API.
 */
final readonly class SyntheticCrmCombinedRetryPreflightV1
{
    private function __construct(
        public string $reasonCode,
        public string $bindingDiagnostic,
        public bool $syntheticContextMatched,
    ) {}

    public static function inspect(
        SyntheticCrmLeadLedgerIntentV1 $original,
        SyntheticCrmLeadLedgerIntentV1 $candidate,
        SyntheticCrmRetryWindowV1 $retryWindow,
        SyntheticCrmAttemptScopedRetryV1 $attempt,
        ?SyntheticLeadBindingAuthoritySource $source=null,
    ):self {
        // F1-318 must pass before ANY calls to the binding source.
        if (!$attempt->sameSyntheticAttempt ||
            !$attempt->timeElapsed ||
            $attempt->reasonCode!=='SAME_ATTEMPT_TIME_CANDIDATE_NO_AUTHORITY' ||
            $attempt->nextConsiderationAt===null) {
            return new self('ATTEMPT_SCOPE_OR_TIME_NOT_VERIFIED','NOT_CHECKED',false);
        }
        if (!$retryWindow->timeElapsed ||
            $retryWindow->reasonCode!=='TEMPORAL_CHECK_PASSED_NO_AUTHORITY' ||
            $retryWindow->nextConsiderationAt===null ||
            $attempt->nextConsiderationAt->getTimestamp()!==
                $retryWindow->nextConsiderationAt->getTimestamp()) {
            return new self('RETRY_TIME_EVIDENCE_DISAGREES','NOT_CHECKED',false);
        }

        // F1-320: identical next-due timestamps are insufficient when two
        // different durable T0 ledgers are accidentally mixed by a worker.
        // Do not query mutable binding authorities for mixed deadline origins.
        if ($attempt->originalT0->getTimestamp() !==
                $retryWindow->originalT0->getTimestamp()) {
            return new self('RETRY_ORIGINAL_T0_CONTEXT_DISAGREES',
                'NOT_CHECKED',false);
        }

        // This class is intentionally a composition of existing fences:
        // reuse the F1-280 immutable destination/ledger identity comparison
        // and F1-281 double-source revision check without re-implementing.
        $binding=SyntheticCrmRetryBindingRecheckV1::inspect(
            $original,$candidate,$retryWindow,$source
        );
        if ($binding->reasonCode!==
                'LAB_CANDIDATE_STILL_REQUIRES_AUTHENTICATED_AUTHORITY') {
            return new self('BINDING_'. $binding->reasonCode,
                $binding->bindingDiagnostic,$binding->syntheticContextMatched);
        }
        return new self('SYNTHETIC_ALL_DIAGNOSTICS_PASSED_NO_AUTHORITY',
            $binding->bindingDiagnostic,true);
    }

    public function mayDispatchToCrm():bool {return false;}
    public function mayConfirmDelivery():bool {return false;}
    public function mayAcknowledgeVisitor():bool {return false;}
    public function mayPersistPayload():bool {return false;}
    public function mayApproveBinding():bool {return false;}
}
