<?php

declare(strict_types=1);

require __DIR__.'/../src/Integration/SyntheticCrmLeadMetadataReadbackAssessment.php';

use Imagine\Sites\Integration\SyntheticCrmLeadMetadataReadbackAssessment as Assessment;

$valid = [
    'LAB_DISABLED', 'TRANSACTION_REQUIRED', 'UNTRUSTED_LAB_CONTEXT',
    'NO_STORED_METADATA', 'PINNED_CONTEXT_CONFLICT',
    'METADATA_MATCH_UNVERIFIED', 'INCONCLUSIVE_DUPLICATE', 'INCONCLUSIVE_DATABASE',
];
$checks = 0;
foreach ($valid as $reason) {
    $assessment = new Assessment($reason);
    if ($assessment->reasonCode !== $reason
        || $assessment->canReserveSubmission()
        || $assessment->canAcknowledgeVisitor()
        || $assessment->canMarkDeliveredToCrm()
        || $assessment->canRetryAutomatically()) {
        throw new RuntimeException('Known readback reason must be diagnostic-only: '.$reason);
    }
    $checks++;
}
foreach (['', 'DELIVERED', 'ACK', 'SUCCESS', 'RETRY_ALLOWED', 'metadata_match_unverified', 'METADATA_MATCH_UNVERIFIED ', 'LAB_DISABLED\n'] as $reason) {
    try {
        new Assessment($reason);
        throw new RuntimeException('Unknown readback reason accepted: '.var_export($reason, true));
    } catch (InvalidArgumentException $expected) {
        $checks++;
    }
}
echo "$checks F1-136 diagnostic allowlist assertions passed; no DB, lead, ACK or deploy.\n";
