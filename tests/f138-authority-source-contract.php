<?php

declare(strict_types=1);

foreach ([
    'SyntheticCrmLeadEnvelopeV1Draft',
    'SyntheticLeadBindingAuthoritySnapshot',
    'SyntheticLeadBindingAuthoritySource',
    'SyntheticLeadBindingAuthorityAssessment',
    'SyntheticLeadBindingAuthorityPreflight',
] as $file) {
    require __DIR__.'/../src/Integration/'.$file.'.php';
}

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySnapshot as Snapshot;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySource as Source;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthorityPreflight as Preflight;

$checks = 0;
function assertF138(bool $condition, string $why): void {
    global $checks;
    if (!$condition) throw new RuntimeException('F1-138: '.$why);
    $checks++;
}
$draft = new Draft(
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
    '55555555-5555-4555-8555-555555555555',
    '66666666-6666-4666-8666-666666666666',
);
$make = static fn(array $changes = []): Snapshot => new Snapshot(
    $changes['org'] ?? $draft->organizationReference,
    $changes['binding'] ?? $draft->bindingReference,
    $changes['site'] ?? $draft->siteReference,
    $changes['form'] ?? $draft->formReference,
    $changes['dest'] ?? $draft->destinationReference,
    $changes['revision'] ?? 1,
    $changes['active'] ?? true,
);
$source = static fn(?Snapshot $snapshot, bool $fail = false): Source => new class($snapshot, $fail) implements Source {
    public function __construct(private ?Snapshot $snapshot, private bool $fail) {}
    public function currentBinding(string $organizationReference, string $bindingReference): ?Snapshot {
        if ($this->fail) throw new RuntimeException('synthetic source failure');
        return $this->snapshot;
    }
};
$scenarios = [
    ['AUTHORITY_UNAVAILABLE', new Preflight()],
    ['AUTHORITY_UNAVAILABLE', new Preflight($source(null, true))],
    ['BINDING_NOT_FOUND', new Preflight($source(null))],
    ['BINDING_INACTIVE', new Preflight($source($make(['active' => false])))],
    ['BINDING_INACTIVE', new Preflight($source($make(['revision' => 0])))],
    ['BINDING_CONTEXT_MISMATCH', new Preflight($source($make(['org' => '77777777-7777-4777-8777-777777777777'])))],
    ['BINDING_CONTEXT_MISMATCH', new Preflight($source($make(['binding' => '77777777-7777-4777-8777-777777777777'])))],
    ['BINDING_CONTEXT_MISMATCH', new Preflight($source($make(['site' => '77777777-7777-4777-8777-777777777777'])))],
    ['BINDING_CONTEXT_MISMATCH', new Preflight($source($make(['form' => '77777777-7777-4777-8777-777777777777'])))],
    ['BINDING_CONTEXT_MISMATCH', new Preflight($source($make(['dest' => '77777777-7777-4777-8777-777777777777'])))],
    ['AUTHORITY_MATCH_UNVERIFIED', new Preflight($source($make()))],
];
foreach ($scenarios as [$expected, $preflight]) {
    $assessment = $preflight->inspect($draft);
    assertF138($assessment->reasonCode === $expected, 'expected '.$expected);
    assertF138(
        !$assessment->canAcceptLead() &&
        !$assessment->canAcknowledgeVisitor() &&
        !$assessment->canDeliverToCrm() &&
        !$assessment->canRetryAutomatically(),
        $expected.' grants no production capability'
    );
}
echo "$checks F1-138 fail-closed authority boundary assertions passed. No IAM, PII or CRM delivery.\n";
