<?php

declare(strict_types=1);

/** F1-278: offline status/Retry-After interpretation, no real CRM calls. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\Integration\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe F1-278 class name');
    }
    require __DIR__.'/../src/Integration/'.$name.'.php';
});
use Imagine\Sites\Integration\SyntheticCrmResponsePolicyV1 as Policy;

$checks=0;
function check278(bool $pass,string $why):void {
    global $checks;
    if(!$pass)throw new RuntimeException('F1-278 '.$why);
    ++$checks;
}
function denied278(callable $cb):bool {
    try{$cb();}catch(InvalidArgumentException){return true;}
    return false;
}
foreach([200,201,204] as $status) {
    $r=Policy::assess($status);
    check278($r->reasonCode==='HTTP_SUCCESS_NOT_CRM_DELIVERY_PROOF' &&
        !$r->retryTimeMayBeConsidered &&
        $r->requiresExternalObservation &&
        $r->providerMinimumWaitSeconds===null &&
        !$r->mayConfirmCrmDelivery() &&
        !$r->mayAcknowledgeVisitor(),
        'HTTP status cannot replace independent delivery receipt '.$status);
}
$accepted=Policy::assess(202,'86400');
check278($accepted->reasonCode==='PROVIDER_202_PENDING_EXTERNAL_OBSERVATION' &&
    $accepted->requiresExternalObservation &&
    !$accepted->retryTimeMayBeConsidered &&
    $accepted->providerMinimumWaitSeconds===null,
    '202 is pending external observation; no re-send on same request');
$conflict=Policy::assess(409);
check278($conflict->reasonCode==='IDEMPOTENCY_CONFLICT_REQUIRES_RECONCILIATION' &&
    !$conflict->mayConfirmCrmDelivery() &&
    !$conflict->retryTimeMayBeConsidered,
    '409 is not success or permission to bypass deduplication');
foreach([400,401,403,404,410,422] as $status){
    $r=Policy::assess($status);
    check278($r->reasonCode==='TERMINAL_OR_AUTHORITY_REVIEW_REQUIRED' &&
        !$r->retryTimeMayBeConsidered &&
        $r->requiresExternalObservation,
        'hard-stop status requires external identity/integration review '.$status);
}
foreach([408,425,429,500,502,503,504] as $status){
    foreach([null,'0','300','86400'] as $raw) {
        $r=Policy::assess($status,$raw);
        check278($r->reasonCode==='TRANSIENT_RESPONSE_TIME_CANDIDATE' &&
            $r->retryTimeMayBeConsidered &&
            $r->providerMinimumWaitSeconds===($raw===null?null:(int)$raw) &&
            $r->requiresExternalObservation &&
            !$r->mayRetryNow() &&
            !$r->maySkipCurrentBindingCheck(),
            'transient status only permits temporal planning '.$status);
    }
    foreach(['-1','+1','01','86401','900000','1.2','1 2','1'."\n".'2',
        'Thu, 12 Nov 2026 12:00:00 GMT',
        'https://example.invalid/',
        str_repeat('7',256)] as $bad) {
        $r=Policy::assess($status,$bad);
        check278($r->reasonCode==='UNTRUSTED_RETRY_AFTER_REJECTED' &&
            !$r->retryTimeMayBeConsidered &&
            $r->providerMinimumWaitSeconds===null,
            'untrusted Retry-After cannot influence retry policy '.$status);
    }
}
foreach([100,102,203,205,206,207,208,226,301,302,303,304,307,308,418,423,426,428,431,451,
    501,505,507,511,599] as $status) {
    $r=Policy::assess($status);
    check278($r->reasonCode==='UNCLASSIFIED_HTTP_RESPONSE_DENIED' &&
        !$r->retryTimeMayBeConsidered && !$r->mayConfirmCrmDelivery(),
        'unknown HTTP status denied by default '.$status);
}
check278(denied278(static fn()=>Policy::assess(0)) &&
    denied278(static fn()=>Policy::assess(600)) &&
    denied278(static fn()=>Policy::assess(-202)),
    'out of HTTP status range rejected');
foreach([202,409,429,201,503,403] as $status) {
    $r=Policy::assess($status);
    check278(!$r->mayAcknowledgeVisitor() &&
        !$r->mayConfirmCrmDelivery() &&
        !$r->mayRetryNow() &&
        !$r->mayOverrideDurableT0() &&
        !$r->maySkipCurrentBindingCheck(),
        'status alone never grants authority '.$status);
}
echo "$checks F1-278 strict offline CRM response-policy assertions passed.\n";
