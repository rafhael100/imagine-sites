<?php

declare(strict_types=1);

/** F1-319: isolated metadata-only CRM attempt + binding checks. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $path=substr($class,strlen($prefix));
    if(!preg_match('/\\A(?:Domain|Integration)\\\\[A-Za-z][A-Za-z0-9]*\\z/D',$path)) {
        throw new RuntimeException('Unsafe test class name');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
});

use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\SyntheticLeadRetryPlanV1 as Backoff;
use Imagine\Sites\Integration\SyntheticCrmResponsePolicyV1 as Reply;
use Imagine\Sites\Integration\SyntheticCrmRetryWindowV1 as Window;
use Imagine\Sites\Integration\SyntheticCrmAttemptScopedRetryV1 as Attempt;
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Envelope;
use Imagine\Sites\Integration\SyntheticCrmLeadLedgerIntentV1 as Intent;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySource as Authority;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySnapshot as Snapshot;
use Imagine\Sites\Integration\SyntheticCrmCombinedRetryPreflightV1 as Combined;

final class Fixture319Authority implements Authority {
    public int $calls=0;
    /** @param list<?Snapshot> $results */
    public function __construct(private array $results){}
    public function currentBinding(string $org,string $binding):?Snapshot {
        return $this->results[$this->calls++]??null;
    }
}

$ids=[
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    'ffffffff-ffff-4fff-8fff-ffffffffffff',
];
$original=new Intent(new Envelope(...$ids));
$retry=new Intent(new Envelope(...$ids));
$t0=new DateTimeImmutable('2026-11-13T03:00:00+00:00');
$deadline=new LeadDeadline($t0);
$last=$t0->modify('+300 seconds');
$now=$t0->modify('+1800 seconds');
$backoff=Backoff::assess($deadline,$now,2,$last,true);
$response=Reply::assess(429,'600');
$window=Window::inspect($deadline,$backoff,$response,$last,$now);
$attempt=Attempt::inspect($deadline,$backoff,$response,2,$last,$now);
$snapshot=static fn(int $revision,bool $active=true):Snapshot=>
    new Snapshot($ids[0],$ids[1],$ids[3],$ids[4],$ids[5],$revision,$active);
$checks=0;
function assert319(bool $value,string $name):void {
    global $checks;
    if(!$value)throw new RuntimeException('F1-319 '.$name);
    ++$checks;
}

$source=new Fixture319Authority([$snapshot(9),$snapshot(9)]);
$ok=Combined::inspect($original,$retry,$window,$attempt,$source);
assert319($ok->reasonCode==='SYNTHETIC_ALL_DIAGNOSTICS_PASSED_NO_AUTHORITY'
    && $ok->bindingDiagnostic==='STABLE_MATCH_UNVERIFIED'
    && $ok->syntheticContextMatched && $source->calls===2,
    'valid synthetic sequence calls binding source twice, without operational authority');
assert319(!$ok->mayDispatchToCrm() && !$ok->mayConfirmDelivery() &&
    !$ok->mayAcknowledgeVisitor() && !$ok->mayPersistPayload() &&
    !$ok->mayApproveBinding(),
    'a complete lab diagnostic cannot send, persist or acknowledge a lead');

$wrongAttempt=Attempt::inspect($deadline,$backoff,$response,1,$last,$now);
$source=new Fixture319Authority([$snapshot(9),$snapshot(9)]);
$denied=Combined::inspect($original,$retry,$window,$wrongAttempt,$source);
assert319($denied->reasonCode==='ATTEMPT_SCOPE_OR_TIME_NOT_VERIFIED' &&
    $source->calls===0 && !$denied->syntheticContextMatched,
    'mismatched attempt is rejected before authority source access');

$waitResponse=Reply::assess(429,'3600');
$wait=Attempt::inspect($deadline,$backoff,$waitResponse,2,$last,$now);
$source=new Fixture319Authority([$snapshot(9),$snapshot(9)]);
assert319(Combined::inspect($original,$retry,$window,$wait,$source)->reasonCode===
    'ATTEMPT_SCOPE_OR_TIME_NOT_VERIFIED' && $source->calls===0,
    'provider wait never triggers binding reads');

$otherWindow=Window::inspect($deadline,$backoff,Reply::assess(429,'1100'),$last,$now);
$source=new Fixture319Authority([$snapshot(9),$snapshot(9)]);
assert319(Combined::inspect($original,$retry,$otherWindow,$attempt,$source)->reasonCode===
    'RETRY_TIME_EVIDENCE_DISAGREES' && $source->calls===0,
    'two inconsistent retry hints cannot be composed as a pass');

// F1-320: even equal next-due timestamps are not interchangeable if
// an outbox worker accidentally pairs diagnostics from two T0 ledgers.
$foreignT0=new LeadDeadline($t0->modify('+60 seconds'));
$foreignWindow=Window::inspect($foreignT0,$backoff,$response,$last,$now);
assert319($foreignWindow->timeElapsed &&
    $foreignWindow->nextConsiderationAt?->getTimestamp()===
        $window->nextConsiderationAt?->getTimestamp() &&
    $foreignWindow->originalT0->getTimestamp()!==$attempt->originalT0->getTimestamp(),
    'different durable T0 can produce the exact same next-retry timestamp');
$source=new Fixture319Authority([$snapshot(9),$snapshot(9)]);
$crossT0=Combined::inspect($original,$retry,$foreignWindow,$attempt,$source);
assert319($crossT0->reasonCode==='RETRY_ORIGINAL_T0_CONTEXT_DISAGREES' &&
    $source->calls===0 && !$crossT0->syntheticContextMatched &&
    !$crossT0->mayDispatchToCrm(),
    'mixed retry-origin evidence denied before any binding lookup');

foreach ([0,1,2,3,4,5] as $field) {
    $altered=$ids;
    $altered[$field]='11111111-1111-4111-8111-111111111111';
    $candidate=new Intent(new Envelope(...$altered));
    $source=new Fixture319Authority([$snapshot(9),$snapshot(9)]);
    $result=Combined::inspect($original,$candidate,$window,$attempt,$source);
    assert319($result->reasonCode===
        'BINDING_SYNTHETIC_SUBMISSION_CONTEXT_CHANGED' &&
        !$result->syntheticContextMatched && $source->calls===0,
        'tenant, idempotency and CRM destination cannot drift: '.$field);
}

$source=new Fixture319Authority([$snapshot(9),$snapshot(10)]);
$changed=Combined::inspect($original,$retry,$window,$attempt,$source);
assert319($changed->reasonCode===
    'BINDING_CURRENT_BINDING_REVOKED_CHANGED_OR_UNVERIFIED' &&
    $changed->bindingDiagnostic==='REVISION_CHANGED' &&
    $source->calls===2 && !$changed->mayDispatchToCrm(),
    'revision drift across independent binding reads denied');

$source=new Fixture319Authority([$snapshot(9),$snapshot(9,false)]);
$revoked=Combined::inspect($original,$retry,$window,$attempt,$source);
assert319($revoked->reasonCode===
    'BINDING_CURRENT_BINDING_REVOKED_CHANGED_OR_UNVERIFIED' &&
    $revoked->bindingDiagnostic==='REVALIDATION_INACTIVE',
    'revocation during double-read denied');

$unavailable=Combined::inspect($original,$retry,$window,$attempt,null);
assert319($unavailable->reasonCode==='BINDING_CURRENT_BINDING_SOURCE_UNAVAILABLE' &&
    !$unavailable->mayAcknowledgeVisitor(),
    'missing independent binding source cannot pass');

$expiredNow=$t0->modify('+432 hours');
$expiredWindow=Window::inspect($deadline,$backoff,$response,$last,$expiredNow);
$expiredAttempt=Attempt::inspect($deadline,$backoff,$response,2,$last,$expiredNow);
$source=new Fixture319Authority([$snapshot(9),$snapshot(9)]);
assert319(Combined::inspect($original,$retry,$expiredWindow,$expiredAttempt,$source)
    ->reasonCode==='ATTEMPT_SCOPE_OR_TIME_NOT_VERIFIED' &&
    $source->calls===0,
    'expired immutable original T0 is rejected before binding source access');

echo "$checks F1-319 composed CRM attempt, scope, binding and original-T0 denials passed.\n";
