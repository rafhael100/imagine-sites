<?php

declare(strict_types=1);

/** F1-279: offline combination; no CRM transport or user data. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Integration)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unsafe F1-279 fixture');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\SyntheticLeadRetryPlanV1 as Backoff;
use Imagine\Sites\Integration\SyntheticCrmResponsePolicyV1 as Response;
use Imagine\Sites\Integration\SyntheticCrmRetryWindowV1 as Window;
$t0=new DateTimeImmutable('2026-11-12T12:00:00+00:00');
$deadline=new LeadDeadline($t0);
$plus=static fn(DateTimeImmutable $date,int $secs):DateTimeImmutable=>
    $date->modify("+$secs seconds");
$checks=0;
function f279(bool $condition,string $what):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-279 '.$what);
    ++$checks;
}
$observed=$plus($t0,0);
$retry=Backoff::assess($deadline,$plus($t0,300),1,$t0,true);
$response=Response::assess(429,'3600');
$before=Window::inspect($deadline,$retry,$response,$observed,$plus($t0,3599));
f279($before->reasonCode==='WAITING_FOR_CONSERVATIVE_RETRY_WINDOW' &&
    !$before->timeElapsed &&
    $before->nextConsiderationAt?->getTimestamp()===$t0->getTimestamp()+3600,
    'provider lower bound dominates own backoff without reset');
$ready=Window::inspect($deadline,$retry,$response,$observed,$plus($t0,3600));
f279($ready->reasonCode==='TEMPORAL_CHECK_PASSED_NO_AUTHORITY' &&
    $ready->timeElapsed &&
    $ready->nextConsiderationAt?->getTimestamp()===$before->nextConsiderationAt?->getTimestamp(),
    'polling now never resets provider Retry-After anchor');
f279(!$ready->mayDispatchToCrm() &&
    !$ready->mayPersistLead() &&
    !$ready->mayAcknowledgeVisitor() &&
    !$ready->mayConfirmDelivery() &&
    !$ready->mayExtendOriginalDeadline(),
    'time check grants no operation');
$noHint=Window::inspect($deadline,$retry,Response::assess(503),$observed,$plus($t0,300));
f279($noHint->nextConsiderationAt?->getTimestamp()===$t0->getTimestamp()+300 &&
    $noHint->timeElapsed,
    'unhinted provider status retains local backoff');
foreach([202,200,201,204,409,400,401,403,422,599] as $status){
    $st=Window::inspect($deadline,$retry,Response::assess($status),
        $observed,$plus($t0,3600));
    f279($st->reasonCode==='RESPONSE_NOT_RETRY_CANDIDATE' &&
        !$st->timeElapsed && $st->nextConsiderationAt===null &&
        !$st->mayConfirmDelivery(),
        'no false retry or receipt after status '.$status);
}
$expired=Window::inspect(
    $deadline,$retry,$response,$observed,$plus($t0,432*3600));
f279($expired->reasonCode==='ORIGINAL_T0_WINDOW_EXPIRED' &&
    $expired->nextConsiderationAt===null,'432h T0 expiry is exclusive');

$near=$plus($t0,431*3600);
$nearBackoff=Backoff::assess($deadline,$near,1,$plus($near,-300),true);
$nearWindow=Window::inspect($deadline,$nearBackoff,
    Response::assess(429,'7200'),$near,$near);
f279($nearWindow->reasonCode==='RETRY_EXCEEDS_ORIGINAL_432H_DEADLINE' &&
    $nearWindow->nextConsiderationAt===null,
    'provider delay cannot extend fixed 432h horizon');
$future=Window::inspect($deadline,$retry,$response,$plus($t0,10000),$plus($t0,3600));
f279($future->reasonCode==='UNVERIFIED_RESPONSE_OBSERVATION',
    'future provider observation fails closed');
$old=Window::inspect($deadline,$retry,$response,$plus($t0,-1),$plus($t0,3600));
f279($old->reasonCode==='UNVERIFIED_RESPONSE_OBSERVATION',
    'provider observation before durable T0 fails closed');

// F1-317: a retry must not borrow a 429/Retry-After observation from
// another older attempt even if the timestamps are within original T0.
$recentLast=$plus($t0,3600);
$recentBackoff=Backoff::assess($deadline,$plus($t0,4500),2,$recentLast,true);
f279($recentBackoff->lastAttemptAt->getTimestamp()===$recentLast->getTimestamp(),
    'latest failed attempt timestamp remains immutable in retry plan');
$staleObserved=Window::inspect($deadline,$recentBackoff,Response::assess(429,'300'),
    $plus($t0,3599),$plus($t0,4500));
f279($staleObserved->reasonCode==='STALE_PROVIDER_REPLY_BEFORE_LATEST_ATTEMPT' &&
    $staleObserved->nextConsiderationAt===null && !$staleObserved->timeElapsed &&
    !$staleObserved->mayDispatchToCrm(),
    'provider reply preceding latest failed attempt never shortens later retry');
$equalObserved=Window::inspect($deadline,$recentBackoff,Response::assess(429,'300'),
    $recentLast,$plus($t0,4500));
f279($equalObserved->reasonCode==='TEMPORAL_CHECK_PASSED_NO_AUTHORITY' &&
    $equalObserved->nextConsiderationAt?->getTimestamp()===$t0->getTimestamp()+4500 &&
    $equalObserved->timeElapsed,
    'reply at most recent attempt preserves immutable backoff');
$tzRecent=new DateTimeZone('America/Sao_Paulo');
$crossZone=Window::inspect($deadline,$recentBackoff,
    Response::assess(429,'300'),
    $plus($t0,3599)->setTimezone($tzRecent),
    $plus($t0,4500)->setTimezone($tzRecent));
f279($crossZone->reasonCode==='STALE_PROVIDER_REPLY_BEFORE_LATEST_ATTEMPT',
    'stale reply remains denied in alternate timezone');

$rejectRetry=Backoff::assess($deadline,$plus($t0,200),1,$t0,false);
$rejected=Window::inspect($deadline,$rejectRetry,$response,$observed,$plus($t0,3600));
f279($rejected->reasonCode==='RETRY_HISTORY_NOT_VERIFIED',
    'terminal original failure cannot be overridden by 429');
foreach([408,425,429,500,502,503,504] as $status){
    foreach([0,300,3600,43200,86400] as $wait){
        $policy=Response::assess($status,(string)$wait);
        $at=$plus($t0,$wait+300);
        $candidate=Window::inspect($deadline,$retry,$policy,$observed,$at);
        f279($candidate->reasonCode==='TEMPORAL_CHECK_PASSED_NO_AUTHORITY' &&
            $candidate->timeElapsed &&
            !$candidate->mayDispatchToCrm(),
            'bounded hint time only '.$status.' '.$wait);
    }
}
$tz=new DateTimeZone('America/Sao_Paulo');
$at=Window::inspect($deadline,$retry,$response,$observed->setTimezone($tz),
    $plus($t0,3600)->setTimezone($tz));
f279($at->nextConsiderationAt?->getTimestamp()===$t0->getTimestamp()+3600 &&
    $at->nextConsiderationAt?->getTimezone()->getName()==='UTC',
    'cross-timezone observation normalizes to UTC');
echo "$checks F1-279 conservative CRM/backoff/T0 composition assertions passed.\n";
