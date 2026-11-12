<?php

declare(strict_types=1);

/** F1-277: no PII, CRM, queue or lead persistence; only UTC time math. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\Domain\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('F1-277 unsafe class fixture');
    }
    require __DIR__.'/../src/Domain/'.$name.'.php';
});
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\SyntheticLeadRetryPlanV1 as Retry;

$checks=0;
function ok277(bool $value,string $name):void {
    global $checks;
    if(!$value)throw new RuntimeException('F1-277 '.$name);
    ++$checks;
}
function plus277(DateTimeImmutable $d,int $s):DateTimeImmutable {
    return $d->modify("+$s seconds");
}
function denied277(callable $operation):bool {
    try {$operation();} catch (InvalidArgumentException){return true;}
    return false;
}
$t0=new DateTimeImmutable('2026-11-12T12:00:00+00:00');
$deadline=new LeadDeadline($t0);
ok277($deadline->initialEnd->getTimestamp()===$t0->getTimestamp()+72*3600 &&
    $deadline->deliveryDeadline->getTimestamp()===$t0->getTimestamp()+432*3600,
    'fixed original 72h/432h windows');
$backoffs=[300,900,1800,3600,7200,14400,28800,43200,86400];
foreach($backoffs as $idx=>$delay){
    $now=plus277($t0,$delay+60);
    $p=Retry::assess($deadline,$now,$idx+1,$t0,true);
    ok277($p->reasonCode==='TIME_CANDIDATE_UNVERIFIED' &&
        $p->backoffElapsed &&
        $p->deadlineWindow==='initial' &&
        $p->nextEligibleAt?->getTimestamp()===$t0->getTimestamp()+$delay,
        'stable strictly increasing backoff step '.$idx);
    $early=Retry::assess($deadline,plus277($t0,$delay-1),$idx+1,$t0,true);
    ok277($early->reasonCode==='BACKOFF_NOT_ELAPSED' &&
        !$early->backoffElapsed &&
        $early->nextEligibleAt?->getTimestamp()===$p->nextEligibleAt?->getTimestamp(),
        'early candidate cannot skip backoff '.$idx);
    ok277(!$p->mayDispatchToCrm() && !$p->mayPersistLead() &&
        !$p->mayAcknowledgeVisitor() && !$p->mayRebaseOriginalT0(),
        'time-only decision never grants dispatch/queue authority '.$idx);
}
$last=plus277($t0,71*3600);
$a=Retry::assess($deadline,plus277($t0,72*3600),1,$last,true);
ok277($a->deadlineWindow==='recovery' &&
    $a->nextEligibleAt?->getTimestamp()===$last->getTimestamp()+300 &&
    $a->backoffElapsed,
    '72-hour boundary does not change next retry or reset T0');
$b=Retry::assess($deadline,plus277($t0,431*3600),9,
    plus277($t0,430*3600),true);
ok277($b->reasonCode==='NEXT_RETRY_OUTSIDE_ORIGINAL_DEADLINE' &&
    $b->nextEligibleAt===null &&
    !$b->backoffElapsed,
    'cannot schedule a retry beyond original 432h horizon');
$c=Retry::assess($deadline,plus277($t0,432*3600),1,$last,true);
ok277($c->reasonCode==='EXPIRED_UNRECOVERABLE' &&
    $c->deadlineWindow==='expired' && $c->nextEligibleAt===null,
    'exact original 432h deadline expires');
$d=Retry::assess($deadline,plus277($t0,433*3600),1,$last,true);
ok277($d->reasonCode==='EXPIRED_UNRECOVERABLE' &&
    !$d->mayDispatchToCrm(),'past deadline never reopens');
$terminal=Retry::assess($deadline,plus277($t0,300),1,$t0,false);
ok277($terminal->reasonCode==='TERMINAL_FAILURE' &&
    $terminal->nextEligibleAt===null,
    'terminal errors never become scheduled candidates');
$tooEarly=Retry::assess($deadline,plus277($t0,100),1,plus277($t0,200),true);
ok277($tooEarly->reasonCode==='UNVERIFIED_ATTEMPT_HISTORY',
    'future attempt timestamp denied');
$before=Retry::assess($deadline,plus277($t0,300),1,plus277($t0,-1),true);
ok277($before->reasonCode==='UNVERIFIED_ATTEMPT_HISTORY',
    'attempt predating durable T0 denied');
ok277(denied277(static fn()=>Retry::assess($deadline,$t0,0,$t0,true)) &&
    denied277(static fn()=>Retry::assess($deadline,$t0,65,$t0,true)) &&
    denied277(static fn()=>Retry::assess($deadline,$t0,1,null,true)),
    'invalid or missing retry history denied');
$tz=new DateTimeZone('America/Sao_Paulo');
$localNow=plus277($t0,300)->setTimezone($tz);
$localLast=$t0->setTimezone($tz);
$local=Retry::assess($deadline,$localNow,1,$localLast,true);
ok277($local->reasonCode==='TIME_CANDIDATE_UNVERIFIED' &&
    $local->nextEligibleAt?->getTimestamp()===$t0->getTimestamp()+300 &&
    $local->nextEligibleAt?->getTimezone()->getName()==='UTC',
    'local timezone never affects original UTC T0');
$repeat=Retry::assess($deadline,plus277($t0,410),1,$t0,true);
ok277($repeat->nextEligibleAt?->getTimestamp()===$local->nextEligibleAt?->getTimestamp(),
    'clock advance cannot reset retry wait');
$long=Retry::assess($deadline,plus277($t0,100000),64,$t0,true);
ok277($long->reasonCode==='TIME_CANDIDATE_UNVERIFIED' &&
    $long->nextEligibleAt?->getTimestamp()===$t0->getTimestamp()+86400,
    'after step nine backoff never exceeds 24 hours');
echo "$checks F1-277 UTC-only lead retry-window/backoff assertions passed; no PII or CRM.\n";
