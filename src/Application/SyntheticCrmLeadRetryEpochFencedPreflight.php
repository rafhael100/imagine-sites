<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use DateTimeImmutable;
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\SyntheticLeadReceipt;
use Imagine\Sites\Integration\DenyingLeadBindingEpochSource;
use Imagine\Sites\Integration\LeadBindingEpochSource;
use Throwable;

/**
 * F1-130. Metadata-only candidate that rejects Sites/CRM binding A->B->A
 * during a retry preflight. No lead payload, network call, or queue write.
 * ALLOW NEVER authorizes transmission or changes the original CRM account.
 */
final class SyntheticCrmLeadRetryEpochFencedPreflight
{
    public function __construct(
        private readonly SyntheticCrmLeadRetryPreflight $bindingPreflight =
            new SyntheticCrmLeadRetryPreflight(),
        private readonly LeadBindingEpochSource $sitesEpochs =
            new DenyingLeadBindingEpochSource(),
        private readonly LeadBindingEpochSource $crmEpochs =
            new DenyingLeadBindingEpochSource(),
    ) {}

    public function evaluate(SyntheticLeadReceipt $receipt,DateTimeImmutable $now):PolicyDecision
    {
        // Do not query a remote authority for a final or expired receipt.
        if($receipt->state!=='pending') {
            return new PolicyDecision(false,['receipt_already_final']);
        }
        if(!(new LeadDeadline($receipt->durableReceivedAt))->mayRetryAt($now)) {
            return new PolicyDecision(false,['outside_immutable_432h_window']);
        }

        // The two epochs belong to DIFFERENT authorities and need not
        // equal one another; each must remain stable across the decision.
        $before=$this->readEpochPair($receipt);
        if($before===null) {
            return new PolicyDecision(false,['independent_binding_epoch_unavailable']);
        }

        $binding=$this->bindingPreflight->evaluate($receipt,$now);
        if(!$binding->allowed) {
            return $binding;
        }

        $after=$this->readEpochPair($receipt);
        if($after===null) {
            return new PolicyDecision(false,['independent_binding_epoch_unavailable']);
        }
        if($before!==$after) {
            return new PolicyDecision(false,['crm_binding_epoch_changed_during_preflight']);
        }
        // Still just a metadata preflight, not a sender/authorization token.
        return new PolicyDecision(true,[]);
    }

    /** @return array{sites:int,crm:int}|null */
    private function readEpochPair(SyntheticLeadReceipt $receipt):?array
    {
        try {
            $sites=$this->sitesEpochs->currentEpochFor($receipt->tenantRef,$receipt->siteRef);
            $crm=$this->crmEpochs->currentEpochFor($receipt->tenantRef,$receipt->siteRef);
        } catch(Throwable) {
            return null;
        }
        if(!is_int($sites) || $sites<1 || !is_int($crm) || $crm<1) {
            return null;
        }
        return ['sites'=>$sites,'crm'=>$crm];
    }
}
