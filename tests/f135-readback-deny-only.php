<?php

declare(strict_types=1);

// F1-135: dependency-free, DB-free regression for the F1-134 deny-only boundary.
// This test intentionally never connects to PostgreSQL or transmits leads.
require __DIR__.'/../src/Integration/SyntheticCrmLeadEnvelopeV1Draft.php';
require __DIR__.'/../src/Integration/SyntheticCrmLeadMetadataReadbackAssessment.php';
require __DIR__.'/../src/Integration/LabPostgresCrmLeadMetadataReadback.php';

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft;
use Imagine\Sites\Integration\SyntheticCrmLeadMetadataReadbackAssessment;
use Imagine\Sites\Integration\LabPostgresCrmLeadMetadataReadback;

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('F1-135 assertion failed: '.$label);
    }
}

$reasons = [
    'LAB_DISABLED', 'TRANSACTION_REQUIRED', 'UNTRUSTED_LAB_CONTEXT',
    'NO_STORED_METADATA', 'PINNED_CONTEXT_CONFLICT',
    'METADATA_MATCH_UNVERIFIED', 'INCONCLUSIVE_DUPLICATE',
    'INCONCLUSIVE_DATABASE',
];
$checks = 0;
foreach ($reasons as $reason) {
    $assessment = new SyntheticCrmLeadMetadataReadbackAssessment($reason);
    foreach ([
        'canReserveSubmission',
        'canAcknowledgeVisitor',
        'canMarkDeliveredToCrm',
        'canRetryAutomatically',
    ] as $capability) {
        check($assessment->$capability() === false, "$reason must deny $capability");
        $checks++;
    }
    check($assessment->reasonCode === $reason, "$reason must remain diagnostic only");
    $checks++;
}

// A successful metadata comparison is still explicitly unverified.
check(
    (new SyntheticCrmLeadMetadataReadbackAssessment('METADATA_MATCH_UNVERIFIED'))
        ->canMarkDeliveredToCrm() === false,
    'a matching row cannot become a CRM receipt'
);
$checks++;

$draft = new SyntheticCrmLeadEnvelopeV1Draft(
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
    '55555555-5555-4555-8555-555555555555',
    '66666666-6666-4666-8666-666666666666',
);
check($draft->canAuthorizeDelivery() === false, 'envelope must remain untrusted');
$checks++;

// The disabled path must short-circuit before accessing an uninitialized PDO.
// No database extension, configuration, or network is needed here.
$old = getenv('SITES_F133_LEAD_PG_LAB');
putenv('SITES_F133_LEAD_PG_LAB=0');
try {
    $reader = (new ReflectionClass(LabPostgresCrmLeadMetadataReadback::class))
        ->newInstanceWithoutConstructor();
    $assessment = $reader->inspect($draft);
    check($assessment->reasonCode === 'LAB_DISABLED', 'lab-disabled must deny before PDO access');
    check(
        !$assessment->canReserveSubmission()
        && !$assessment->canAcknowledgeVisitor()
        && !$assessment->canMarkDeliveredToCrm()
        && !$assessment->canRetryAutomatically(),
        'disabled laboratory cannot grant any capability'
    );
    $checks += 2;
} finally {
    if ($old === false) {
        putenv('SITES_F133_LEAD_PG_LAB');
    } else {
        putenv('SITES_F133_LEAD_PG_LAB='.$old);
    }
}

echo "$checks F1-135 deny-only readback assertions passed; no DB, lead, ACK or deploy.\n";
