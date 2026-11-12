<?php

declare(strict_types=1);

/** CRM-POSTBIND-T0-01: synthetic time-of-check/time-of-use deadline gate. */
spl_autoload_register(static function (string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Integration)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe post-binding fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\SyntheticLeadRetryPlanV1 as Retry;
use Imagine\Sites\Integration\SyntheticCrmResponsePolicyV1 as Response;
use Imagine\Sites\Integration\SyntheticCrmRetryWindowV1 as Window;
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Envelope;
use Imagine\Sites\Integration\SyntheticCrmLeadLedgerIntentV1 as Intent;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySource as Source;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySnapshot as Snapshot;
use Imagine\Sites\Integration\SyntheticCrmRetryBindingRecheckV1 as Recheck;
use Imagine\Sites\Integration\SyntheticCrmPostBindingDeadlineFenceV1 as FinalFence;

final class FixedPostBindingSource implements Source
{
    public int $reads=0;
    public function __construct(private Snapshot $snapshot){}
    public function currentBinding(
        string $organizationReference,string $bindingReference
    ):?Snapshot {
        ++$this->reads;
        return $this->snapshot;
    }
}

$checks=0;
function checkPostBinding(bool $yes,string $label):void {
    global $checks;
    if (!$yes)throw new RuntimeException('POSTBIND-T0 '.$label);
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
$t0=new DateTimeImmutable('2026-11-12T12:00:00+00:00');
$deadline=new LeadDeadline($t0);
$plus=static fn(int $s):DateTimeImmutable=>\n    $t0->modify(($s>=0?'+':'').$s.' seconds');
$original=new Intent(new Envelope(...$ids));
$retry=new Intent(new Envelope(...$ids));
$attempt=Retry::assess($deadline,$plus(300),1,$t0,true);
$window=Window::inspect($deadline,$attempt,Response::assess(429,'300'),
    $t0,$plus(300));
checkPostBinding($window->reasonCode==='TEMPORAL_CHECK_PASSED_NO_AUTHORITY' &&
    $window->timeElapsed &&
    $window->nextConsiderationAt?->getTimestamp()===$plus(300)->getTimestamp(),
    'original retry candidate is not a send permission');

$snapshot=new Snapshot(
    $ids[0],$ids[1],$ids[3],$ids[4],$ids[5],9,true
);
$source=new FixedPostBindingSource($snapshot);
$binding=Recheck::inspect($original,$retry,$window,$source);
checkPostBinding($source->reads===2 &&
    $binding->reasonCode==='LAB_CANDIDATE_STILL_REQUIRES_AUTHENTICATED_AUTHORITY',
    'double binding read is still only a claim');
$ready=FinalFence::inspect(
    $deadline,$window,$binding,$plus(300),$plus(301)
);
checkPostBinding($ready->reasonCode==='SYNTHETIC_POST_BINDING_TIME_OK_NO_AUTHORITY' &&
    $ready->deadlineWindow==='initial' &&
    $ready->syntheticTimeStillWithinDeadline &&
    $ready->lastCheckedAt?->getTimestamp()===$plus(301)->getTimestamp(),
    'post-read time still within T0 window is advisory only');
checkPostBinding(!$ready->mayDispatchToCrm() &&
    !$ready->mayAcknowledgeVisitor() &&
    !$ready->mayMarkLeadDelivered() &&
    !$ready->mayExtendOriginalT0() &&
    !$ready->mayTreatClockAsAuthenticated(),
    'passing every synthetic check grants no operation');

$near=FinalFence::inspect(
    $deadline,$window,$binding,$plus(432*3600-2),$plus(432*3600-1)
);
checkPostBinding($near->syntheticTimeStillWithinDeadline &&
    $near->deadlineWindow==='recovery',
    'one second before original deadline remains within window');
foreach([0,1,3600] as $offset){
    $at=FinalFence::inspect(
        $deadline,$window,$binding,
        $plus(432*3600-2),$plus(432*3600+$offset)
    );
    checkPostBinding($at->reasonCode===
        'ORIGINAL_T0_EXPIRED_DURING_BINDING_RECHECK' &&
        !$at->syntheticTimeStillWithinDeadline &&
        $at->deadlineWindow==='expired' &&
        !$at->mayDispatchToCrm(),
        'expiry is exclusive, no renewal by slow read '.$offset);
}
$tz=new DateTimeZone('America/Sao_Paulo');
$normalized=FinalFence::inspect(
    $deadline,$window,$binding,$plus(300)->setTimezone($tz),
    $plus(301)->setTimezone($tz)
);
checkPostBinding($normalized->lastCheckedAt?->getTimezone()->getName()==='UTC' &&
    $normalized->reasonCode==='SYNTHETIC_POST_BINDING_TIME_OK_NO_AUTHORITY',
    'timestamps normalized to UTC before comparison');

foreach([
    'end before start'=>[$plus(301),$plus(300)],
    'start before due'=>[$plus(299),$plus(301)],
    'start before T0'=>[$plus(-1),$plus(301)],
] as $name=>$bounds){
    $report=FinalFence::inspect(
        $deadline,$window,$binding,$bounds[0],$bounds[1]
    );
    checkPostBinding($report->reasonCode==='POST_BINDING_CLOCK_OR_DUE_UNVERIFIED' &&
        !$report->syntheticTimeStillWithinDeadline,
        'untrusted clock/order denied '.$name);
}
$notReady=Window::inspect($deadline,$attempt,Response::assess(429,'1200'),
    $t0,$plus(300));
$blocked=FinalFence::inspect(
    $deadline,$notReady,$binding,$plus(300),$plus(3600)
);
checkPostBinding($blocked->reasonCode==='EARLIER_RETRY_WINDOW_NOT_VERIFIED' &&
    !$blocked->syntheticTimeStillWithinDeadline,
    'provider wait must still be honored');

$readFailed=Recheck::inspect($original,$retry,$window,null);
$reject=FinalFence::inspect(
    $deadline,$window,$readFailed,$plus(300),$plus(301)
);
checkPostBinding($reject->reasonCode==='BINDING_RECHECK_NOT_VERIFIED' &&
    !$reject->syntheticTimeStillWithinDeadline,
    'missing binding authority source denied');
$noRetry=Window::inspect($deadline,$attempt,Response::assess(202),
    $t0,$plus(300));
$reject=FinalFence::inspect(
    $deadline,$noRetry,$binding,$plus(300),$plus(301)
);
checkPostBinding($reject->reasonCode==='EARLIER_RETRY_WINDOW_NOT_VERIFIED',
    'provider HTTP 202 is not an accepted CRM receipt or retry');
echo "$checks CRM POST-BINDING-T0 timing assertions passed; no actual CRM dispatch.\n";
