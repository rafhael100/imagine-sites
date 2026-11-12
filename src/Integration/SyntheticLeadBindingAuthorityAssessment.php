<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/** F1-138: diagnostic-only preflight. No authorization is conferred. */
final readonly class SyntheticLeadBindingAuthorityAssessment
{
    public function __construct(public string $reasonCode) {}
    public function canAcceptLead(): bool { return false; }
    public function canAcknowledgeVisitor(): bool { return false; }
    public function canDeliverToCrm(): bool { return false; }
    public function canRetryAutomatically(): bool { return false; }
}
