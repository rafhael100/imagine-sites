<?php

declare(strict_types=1);

/** F1-318: synthetic CRM attempt scope only; no identifiers, PII or I/O. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\\A(?:Domain|Integration)\\\\[A-Za-z][A-Za-z0-9]*\\z/D',$part)) {
        throw new RuntimeException('F1-318 unknown fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\SyntheticLeadRetryPlanV1 as Backoff;
use Imagine\Sites\Integration\SyntheticCrmResponsePolicyV1 as Reply;
use Imagine\Sites\Integration\SyntheticCrmAttemptScopedRetryV1 as Scoped;

$t0=new DateTimeImmutable('2026-11-13T03:00:00+00:00');
$deadline=new LeadDeadline($t0);
$plus=static fn(DateTimeImmutable $d,int $secs)=>
    $d->modify(($secs<0?'':'+').$secs.' seconds');
$last=$plus($t0,300);
$now=$plus($t0,1800);
$plan=Backoff::assess($deadline,$now,2,$last,true);
$checks=0;
function assert318(bool $condition,string $what):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-318 '.$what);
    ++$checks;
}

assert318($plan->failedAttempts===2 &&
    $plan->lastAttemptAt->getTimestamp()===$last->getTimestamp() &&
    $plan->nextEligibleAt?->getTimestamp()===$t0->getTimestamp()+1200,
    'attempt sequence, last attempt and backoff remain pinned');

$reply=Reply::assess(429,'600');
$ok=Scoped::inspect($deadline,$plan,$reply,2,$last,$now);
assert318($ok->reasonCode==='SAME_ATTEMPT_TIME_CANDIDATE_NO_AUTHORITY' &&
    $ok->timeElapsed && $ok->sameSyntheticAttempt &&
    $ok->nextConsiderationAt?->getTimestamp()===$t0->getTimestamp()+1200,
    'matching attempt and 429 hint yields only an untrusted temporal candidate');
assert318(!$ok->mayDispatchToCrm() && !$ok->mayConfirmDelivery() &&
    !$ok->mayAcknowledgeVisitor() && !$ok->mayRebaseOriginalT0() &&
    !$ok->mayAuthenticateResponse(),
    'no sequence or temporal match grants CRM authority');

foreach([0,1,3,64,65,-1] as $otherAttempt){
    $bad=Scoped::inspect($deadline,$plan,$reply,$otherAttempt,$last,$now);
    assert318($bad->reasonCode==='SYNTHETIC_ATTEMPT_SEQUENCE_MISMATCH' &&
        !$bad->sameSyntheticAttempt && !$bad->timeElapsed &&
        $bad->nextConsiderationAt===null,
        'different or out-of-range response sequence denied: '.$otherAttempt);
}
$stale=Scoped::inspect($deadline,$plan,$reply,2,$plus($last,-1),$now);
assert318($stale->reasonCode==='RESPONSE_BEFORE_PINNED_FAILED_ATTEMPT' &&
    $stale->sameSyntheticAttempt && !$stale->timeElapsed,
    'matching sequence alone cannot rescue an older HTTP observation');
$wait=Scoped::inspect($deadline,$plan,Reply::assess(429,'3600'),2,$last,$now);
assert318($wait->reasonCode==='ATTEMPT_BOUND_WAITING_FOR_CONSERVATIVE_RETRY_WINDOW' &&
    !$wait->timeElapsed && $wait->nextConsiderationAt?->getTimestamp()===
    $t0->getTimestamp()+3900,
    'Retry-After continues to dominate local backoff without clock reset');
foreach([202,200,409,403] as $status){
    $refused=Scoped::inspect($deadline,$plan,Reply::assess($status),2,$last,$now);
    assert318($refused->reasonCode==='ATTEMPT_BOUND_RESPONSE_NOT_RETRY_CANDIDATE' &&
        !$refused->timeElapsed && $refused->nextConsiderationAt===null,
        'HTTP status does not become delivery or re-send evidence '.$status);
}
$future=Scoped::inspect($deadline,$plan,$reply,2,$plus($now,1),$now);
assert318($future->reasonCode==='ATTEMPT_BOUND_UNVERIFIED_RESPONSE_OBSERVATION' &&
    !$future->timeElapsed,'future response timestamp denied');
$expired=Scoped::inspect($deadline,$plan,$reply,2,$last,
    $plus($t0,432*3600));
assert318($expired->reasonCode==='ORIGINAL_T0_432H_EXPIRED' &&
    !$expired->timeElapsed && $expired->nextConsiderationAt===null,
    '432h immutable T0 expiration remains exclusive');
$timezone=new DateTimeZone('America/Sao_Paulo');
$local=Scoped::inspect($deadline,$plan,$reply,2,
    $last->setTimezone($timezone),$now->setTimezone($timezone));
assert318($local->reasonCode===$ok->reasonCode &&
    $local->nextConsiderationAt?->getTimestamp()===$ok->nextConsiderationAt?->getTimestamp(),
    'timezone cannot rebase response observation or backoff');

echo "$checks F1-318 CRM response attempt-scope and immutable T0 checks passed; NO CRM dispatch.\n";
