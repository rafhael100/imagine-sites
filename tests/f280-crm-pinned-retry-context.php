<?php

declare(strict_types=1);

/** F1-280: cross-tenant and destination-drift denial for retry candidates. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $path=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Integration)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$path)) {
        throw new RuntimeException('F1-280 fixture class rejected');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
});
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\SyntheticLeadRetryPlanV1 as Retry;
use Imagine\Sites\Integration\SyntheticCrmResponsePolicyV1 as Response;
use Imagine\Sites\Integration\SyntheticCrmRetryWindowV1 as Window;
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Envelope;
use Imagine\Sites\Integration\SyntheticCrmLeadLedgerIntentV1 as Intent;
use Imagine\Sites\Integration\SyntheticCrmPinnedRetryContextV1 as Fence;

$checks=0;
function check280(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-280 '.$label);
    ++$checks;
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
$t0=new DateTimeImmutable('2026-11-12T12:00:00+00:00');
$deadline=new LeadDeadline($t0);
$last=$t0;
$plan=Retry::assess($deadline,$t0->modify('+300 seconds'),1,$last,true);
$time=Window::inspect($deadline,$plan,Response::assess(429,'300'),
    $t0,$t0->modify('+300 seconds'));
$ok=Fence::inspect($original,$retry,$time);
check280($ok->reasonCode==='SAME_SYNTHETIC_CONTEXT_AND_TIME_NO_AUTHORITY' &&
    $ok->sameLogicalScope && $ok->samePinnedDestination &&
    $ok->nextConsiderationAt?->getTimestamp()===$t0->getTimestamp()+300 &&
    !$ok->mayDispatchToCrm() &&
    !$ok->mayChangeCrmDestination() &&
    !$ok->mayResetIdempotencyKey() &&
    !$ok->mayAcknowledgeVisitor() &&
    !$ok->mayTreatBindingAsAuthenticated(),
    'identical context and time remain non-authoritative');
$waiting=Window::inspect($deadline,$plan,Response::assess(429,'1200'),
    $t0,$t0->modify('+300 seconds'));
$waitingFence=Fence::inspect($original,$retry,$waiting);
check280($waitingFence->reasonCode==='RETRY_TIME_NOT_ELAPSED_OR_UNVERIFIED' &&
    $waitingFence->sameLogicalScope && $waitingFence->samePinnedDestination &&
    $waitingFence->nextConsiderationAt?->getTimestamp()===$t0->getTimestamp()+1200,
    'pinned context never overrides Retry-After wait');

foreach([0,1,2,3,4,5] as $field) {
    $changed=$ids;
    $changed[$field]='11111111-1111-4111-8111-111111111111';
    $other=new Intent(new Envelope(...$changed));
    $result=Fence::inspect($original,$other,$time);
    $sameScope=in_array($field,[1,3,4,5],true);
    check280($result->reasonCode===(
        $sameScope?'PINNED_CONTEXT_CHANGED_DENIED':
            'LOGICAL_SUBMISSION_CHANGED_DENIED'
        ) &&
        $result->nextConsiderationAt===null &&
        $result->sameLogicalScope===$sameScope &&
        !$result->samePinnedDestination &&
        !$result->mayDispatchToCrm(),
        'disallow a changed organization/submission/binding/site/form/destination '.$field);
}
$crossTenant=$ids;
$crossTenant[0]='22222222-2222-4222-8222-222222222222';
$crossTenant[1]='33333333-3333-4333-8333-333333333333';
$cross=new Intent(new Envelope(...$crossTenant));
check280(Fence::inspect($original,$cross,$time)->reasonCode===
    'LOGICAL_SUBMISSION_CHANGED_DENIED',
    'cross-tenant forged context denied');
$conflict=Window::inspect($deadline,$plan,Response::assess(409),$t0,
    $t0->modify('+300 seconds'));
check280(Fence::inspect($original,$retry,$conflict)->reasonCode===
    'RETRY_TIME_NOT_ELAPSED_OR_UNVERIFIED',
    'same idempotency key cannot override an HTTP 409 conflict');
$accepted=Window::inspect($deadline,$plan,Response::assess(202),$t0,
    $t0->modify('+300 seconds'));
check280(Fence::inspect($original,$retry,$accepted)->nextConsiderationAt===null,
    'HTTP 202 never turns into retry permission');
$expired=Window::inspect($deadline,$plan,Response::assess(429),$t0,
    $t0->modify('+432 hours'));
check280(Fence::inspect($original,$retry,$expired)->reasonCode===
    'RETRY_TIME_NOT_ELAPSED_OR_UNVERIFIED',
    'original T0 deadline still binds idempotent submissions');
check280($original->syntheticLedgerFingerprint()===$retry->syntheticLedgerFingerprint(),
    'same organization/submission key is stable without contact data');
echo "$checks F1-280 exact CRM retry-context and cross-tenant denials passed.\n";
