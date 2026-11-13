<?php

declare(strict_types=1);

// F1-139: revision and revocation probes with no I/O, keys, tenants or PII.
foreach ([
    'SyntheticCrmLeadEnvelopeV1Draft',
    'SyntheticLeadBindingAuthoritySnapshot',
    'SyntheticLeadBindingAuthoritySource',
    'SyntheticLeadBindingRevisionAssessment',
    'SyntheticLeadBindingRevisionFence',
] as $file) {
    require __DIR__.'/../src/Integration/'.$file.'.php';
}

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySnapshot as Snapshot;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySource as Source;
use Imagine\Sites\Integration\SyntheticLeadBindingRevisionAssessment as Assessment;
use Imagine\Sites\Integration\SyntheticLeadBindingRevisionFence as Fence;

$checks = 0;
function f139Check(bool $pass, string $label): void {
    global $checks;
    if (!$pass) throw new RuntimeException('F1-139 failed: '.$label);
    ++$checks;
}

$incoming = new Draft(
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
    '55555555-5555-4555-8555-555555555555',
    '66666666-6666-4666-8666-666666666666',
);
$make = static fn(array $override = []): Snapshot => new Snapshot(
    $override['org'] ?? $incoming->organizationReference,
    $override['binding'] ?? $incoming->bindingReference,
    $override['site'] ?? $incoming->siteReference,
    $override['form'] ?? $incoming->formReference,
    $override['dest'] ?? $incoming->destinationReference,
    $override['revision'] ?? 7,
    $override['active'] ?? true,
);
$other = '77777777-7777-4777-8777-777777777777';

$source = static fn(array $sequence): Source => new class($sequence, $incoming) implements Source {
    public int $reads = 0;
    public function __construct(
        private array $sequence,
        private Draft $expected
    ) {}
    public function currentBinding(
        string $organizationReference,
        string $bindingReference
    ): ?Snapshot {
        if ($organizationReference !== $this->expected->organizationReference ||
            $bindingReference !== $this->expected->bindingReference) {
            throw new LogicException('Source called for wrong binding.');
        }
        $next = $this->sequence[$this->reads] ?? null;
        ++$this->reads;
        if ($next instanceof Throwable) throw $next;
        return $next;
    }
};

$scenarios = [
    ['BINDING_NOT_FOUND', [null], 1],
    ['AUTHORITY_UNAVAILABLE', [new RuntimeException('offline')], 1],
    ['BINDING_INACTIVE', [$make(['active' => false])], 1],
    ['BINDING_INACTIVE', [$make(['revision' => 0])], 1],
    ['BINDING_CONTEXT_MISMATCH', [$make(['org' => $other])], 1],
    ['BINDING_CONTEXT_MISMATCH', [$make(['binding' => $other])], 1],
    ['BINDING_CONTEXT_MISMATCH', [$make(['site' => $other])], 1],
    ['BINDING_CONTEXT_MISMATCH', [$make(['form' => $other])], 1],
    ['BINDING_CONTEXT_MISMATCH', [$make(['dest' => $other])], 1],
    ['REVALIDATION_MISSING', [$make(), null], 2],
    ['AUTHORITY_UNAVAILABLE', [$make(), new RuntimeException('offline second')], 2],
    ['REVALIDATION_INACTIVE', [$make(), $make(['active' => false])], 2],
    ['REVALIDATION_INACTIVE', [$make(), $make(['revision' => 0])], 2],
    ['REVISION_CHANGED', [$make(), $make(['revision' => 8])], 2],
    ['REVISION_CHANGED', [$make(), $make(['revision' => 6])], 2],
    // A -> B -> A is observable if the authority increments its revision.
    ['REVISION_CHANGED', [$make(), $make(['revision' => 9])], 2],
    ['REVALIDATION_CONTEXT_MISMATCH', [$make(), $make(['site' => $other])], 2],
    ['REVALIDATION_CONTEXT_MISMATCH', [$make(), $make(['dest' => $other])], 2],
    ['STABLE_MATCH_UNVERIFIED', [$make(), $make()], 2],
];

$missing = (new Fence())->inspect($incoming);
f139Check($missing->reasonCode === 'AUTHORITY_UNAVAILABLE', 'default source denies');
f139Check(
    !$missing->canAcceptLead() && !$missing->canAcknowledgeVisitor()
    && !$missing->canDeliverToCrm() && !$missing->canRetryAutomatically(),
    'default source grants zero abilities'
);
foreach ($scenarios as [$expected, $sequence, $expectedReads]) {
    $fixture = $source($sequence);
    $outcome = (new Fence($fixture))->inspect($incoming);
    f139Check($outcome->reasonCode === $expected, $expected.' diagnostic');
    f139Check($fixture->reads === $expectedReads, 'expected source read count');
    f139Check(
        !$outcome->canAcceptLead() && !$outcome->canAcknowledgeVisitor()
        && !$outcome->canDeliverToCrm() && !$outcome->canRetryAutomatically(),
        $expected.' must never grant operational capabilities'
    );
}
foreach (['ACK', 'DELIVERED', 'ALLOW', 'STABLE_MATCH_UNVERIFIED ', ''] as $unknown) {
    try {
        new Assessment($unknown);
        throw new RuntimeException('Unrecognized diagnostic unexpectedly accepted.');
    } catch (InvalidArgumentException) {
        ++$checks;
    }
}
echo "$checks F1-139 double-read revision tests passed; still no authenticated IAM or CRM ACK.\n";
