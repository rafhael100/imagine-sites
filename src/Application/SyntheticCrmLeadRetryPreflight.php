<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use DateTimeImmutable;
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\SyntheticCrmBindingSnapshot;
use Imagine\Sites\Domain\SyntheticLeadReceipt;
use Imagine\Sites\Integration\DenyingLeadBindingAuthoritySource;
use Imagine\Sites\Integration\LeadBindingAuthoritySource;
use Throwable;

/**
 * F1-55: metadata-only retry PRECONDITION, never a sender or credential.
 * Both independent bindings must match the immutable DB ingress receipt.
 * Real trust, T0 immutability, RLS and CRM acknowledgment live in adapters.
 */
final class SyntheticCrmLeadRetryPreflight
{
    public function __construct(
        private readonly LeadBindingAuthoritySource $sites =
            new DenyingLeadBindingAuthoritySource(),
        private readonly LeadBindingAuthoritySource $crm =
            new DenyingLeadBindingAuthoritySource(),
    ) {}

    public function evaluate(SyntheticLeadReceipt $receipt,DateTimeImmutable $now):PolicyDecision
    {
        if($receipt->state!=='pending') {
            return new PolicyDecision(false,['receipt_already_final']);
        }
        if(!(new LeadDeadline($receipt->durableReceivedAt))->mayRetryAt($now)) {
            return new PolicyDecision(false,['outside_immutable_432h_window']);
        }

        try {
            // In production these would be two separately authenticated APIs,
            // not values or boolean flags from the form/AI/request body.
            $sites=$this->sites->currentFor($receipt->tenantRef,$receipt->siteRef);
            $crm=$this->crm->currentFor($receipt->tenantRef,$receipt->siteRef);
        } catch(Throwable) {
            return new PolicyDecision(false,['independent_binding_source_unavailable']);
        }
        if(!$sites instanceof SyntheticCrmBindingSnapshot ||
            !$crm instanceof SyntheticCrmBindingSnapshot) {
            return new PolicyDecision(false,['independent_binding_source_unavailable']);
        }

        foreach([$sites,$crm] as $snapshot) {
            $freshness=$now->getTimestamp()-$snapshot->checkedAt->getTimestamp();
            if(!$snapshot->active ||
                $freshness < -5 || $freshness >= 30 ||
                $snapshot->bindingRevision!==$receipt->bindingRevision ||
                !hash_equals($snapshot->tenantRef,$receipt->tenantRef) ||
                !hash_equals($snapshot->siteRef,$receipt->siteRef) ||
                !hash_equals($snapshot->crmAccountRef,$receipt->crmAccountRef)) {
                return new PolicyDecision(false,['crm_binding_not_current_or_mismatched']);
            }
        }
        // ALLOW means the *synthetic retry preflight* passed; it is never
        // permission to transmit a lead or to change an original CRM account.
        return new PolicyDecision(true,[]);
    }
}
