<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-134 deliberately no ALLOW capability. Comparing an untrusted
 * metadata-only PostgreSQL row is not an ACK, delivery or authority.
 */
final readonly class SyntheticCrmLeadMetadataReadbackAssessment
{
    public function __construct(public string $reasonCode) {}

    public function canReserveSubmission():bool { return false; }
    public function canAcknowledgeVisitor():bool { return false; }
    public function canMarkDeliveredToCrm():bool { return false; }
    public function canRetryAutomatically():bool { return false; }
}
