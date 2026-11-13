<?php

declare(strict_types=1);

/** F1-281: no real tenant, CRM calls or active authority source. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $path=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Integration)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$path)) {
        throw new RuntimeException('Unexpected synthetic fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
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

final class Fixture281Source implements Source
{
    public int $calls=0;
    /** @param list<?Snapshot> $snapshots */
    public function __construct(
        private array $snapshots,
        private bool $throw=false
    ){}
    public function currentBinding(
        string $organizationReference,string $bindingReference
    ):?Snapshot {
        ++$this->calls;
        if($this->throw)throw new RuntimeException('fixture untrusted source down');
        return $this->snapshots[$this->calls-1]??null;
    }
}
$checks=0;
function ok281(bool $yes,string $message):void {
    global $checks;
    if(!$yes)throw new RuntimeException('F1-281 '.$message);
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
$old=new Intent(new Envelope(...$ids));
$same=new Intent(new Envelope(...$ids));
$t0=new DateTimeImmutable('2026-11-12T12:00:00+00:00');
$deadline=new LeadDeadline($t0);
$now=$t0->modify('+300 seconds');
$plan=Retry::assess($deadline,$now,1,$t0,true);
$window=Window::inspect($deadline,$plan,Response::assess(429,'300'),
    $t0,$now);
$snapshot=static fn(int $revision,bool $active=true,
    ?string $destination=null):Snapshot=>new Snapshot(
        $ids[0],$ids[1],$ids[3],$ids[4],$destination??$ids[5],
        $revision,$active
    );
$source=new Fixture281Source([$snapshot(9),$snapshot(9)]);
$passed=Recheck::inspect($old,$same,$window,$source);
ok281($passed->reasonCode===
    'LAB_CANDIDATE_STILL_REQUIRES_AUTHENTICATED_AUTHORITY' &&
    $passed->bindingDiagnostic==='STABLE_MATCH_UNVERIFIED' &&
    $passed->syntheticContextMatched && $source->calls===2 &&
    !$passed->mayContactCrm() &&
    !$passed->mayReserveDurableNonce() &&
    !$passed->mayAcknowledgeVisitor() &&
    !$passed->mayMarkLeadDelivered() &&
    !$passed->mayApproveBinding(),
    'even stable double-read grants no CRM action');
$missing=Recheck::inspect($old,$same,$window,null);
ok281($missing->reasonCode==='CURRENT_BINDING_SOURCE_UNAVAILABLE' &&
    $missing->bindingDiagnostic==='AUTHORITY_UNAVAILABLE',
    'missing source denied');
foreach([
    'first inactive'=>[$snapshot(9,false),$snapshot(9),'BINDING_INACTIVE'],
    'revoked before second'=>[$snapshot(9),$snapshot(9,false),'REVALIDATION_INACTIVE'],
    'revision changed'=>[$snapshot(9),$snapshot(10),'REVISION_CHANGED'],
    'destination changed'=>[$snapshot(9),$snapshot(9,true,$ids[2]),
        'REVALIDATION_CONTEXT_MISMATCH'],
    'missing second'=>[$snapshot(9),null,'REVALIDATION_MISSING'],
    'missing first'=>[null,$snapshot(9),'BINDING_NOT_FOUND'],
] as $label=>$data){
    $source=new Fixture281Source([$data[0],$data[1]]);
    $result=Recheck::inspect($old,$same,$window,$source);
    ok281($result->reasonCode==='CURRENT_BINDING_REVOKED_CHANGED_OR_UNVERIFIED' &&
        $result->bindingDiagnostic===$data[2] &&
        !$result->mayContactCrm(),
        'binding revocation/change denied '.$label);
}
$down=new Fixture281Source([],true);
$failed=Recheck::inspect($old,$same,$window,$down);
ok281($failed->reasonCode==='CURRENT_BINDING_REVOKED_CHANGED_OR_UNVERIFIED' &&
    $failed->bindingDiagnostic==='AUTHORITY_UNAVAILABLE' &&
    $down->calls===1,'unavailable source denied');
foreach([0,1,2,3,4,5] as $field){
    $altered=$ids;
    $altered[$field]='11111111-1111-4111-8111-111111111111';
    $other=new Intent(new Envelope(...$altered));
    $source=new Fixture281Source([$snapshot(9),$snapshot(9)]);
    $result=Recheck::inspect($old,$other,$window,$source);
    ok281($result->reasonCode==='SYNTHETIC_SUBMISSION_CONTEXT_CHANGED' &&
        !$result->syntheticContextMatched && $source->calls===0,
        'cross-tenant/context rejected before source access '.$field);
}
$source=new Fixture281Source([$snapshot(9),$snapshot(9)]);
$wait=Window::inspect($deadline,$plan,Response::assess(429,'1200'),
    $t0,$now);
$pending=Recheck::inspect($old,$same,$wait,$source);
ok281($pending->reasonCode==='ORIGINAL_T0_OR_PROVIDER_BACKOFF_NOT_READY' &&
    $source->calls===0,'deferred retry cannot consume binding source');
$source=new Fixture281Source([$snapshot(9),$snapshot(9)]);
$async=Window::inspect($deadline,$plan,Response::assess(202),$t0,$now);
$asyncReport=Recheck::inspect($old,$same,$async,$source);
ok281($asyncReport->reasonCode==='ORIGINAL_T0_OR_PROVIDER_BACKOFF_NOT_READY' &&
    $source->calls===0,'HTTP 202 cannot trigger re-send or read');
$source=new Fixture281Source([$snapshot(9),$snapshot(9)]);
$expiry=Window::inspect($deadline,$plan,Response::assess(429),$t0,
    $t0->modify('+432 hours'));
ok281(Recheck::inspect($old,$same,$expiry,$source)->reasonCode===
    'ORIGINAL_T0_OR_PROVIDER_BACKOFF_NOT_READY' &&
    $source->calls===0,'expired original T0 denies before source lookup');
echo "$checks F1-281 current CRM binding recheck assertions passed.\n";
