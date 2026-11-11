<?php

declare(strict_types=1);

/** F1-132: only synthetic metadata. No queue, lead body, DB, HTTP or ACK. */
spl_autoload_register(static function(string $class):void {
    $sites='Imagine\\Sites\\Integration\\';
    $crm='App\\Core\\Leads\\';
    if(str_starts_with($class,$sites)) {
        $name=substr($class,strlen($sites));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
            throw new RuntimeException('Unsafe Sites class name');
        }
        require __DIR__.'/../src/Integration/'.$name.'.php';
    } elseif(str_starts_with($class,$crm)) {
        $name=substr($class,strlen($crm));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
            throw new RuntimeException('Unsafe CRM class name');
        }
        require __DIR__.'/../crm-candidate/app/Core/Leads/'.$name.'.php';
    }
});

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\SyntheticCrmLeadLedgerIntentV1 as SitesIntent;
use App\Core\Leads\LeadSubmissionIdentity as CrmIdentity;
use App\Core\Leads\SyntheticIdempotencyClaim as CrmClaim;
use App\Core\Leads\SyntheticIdempotencySnapshot as CrmSnapshot;
use App\Core\Leads\SyntheticIdempotencyPhase as Phase;
use App\Core\Leads\SyntheticIdempotencyCasInspector as Cas;
use App\Core\Leads\SyntheticDeliveryEvidenceInspector as Evidence;

$checks=0;
function f132Check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    ++$checks;
    echo 'PASS: '.$label."\n";
}

$fixture=[
    'organizationReference'=>'11111111-1111-4111-8111-111111111111',
    'bindingReference'=>'22222222-2222-4222-8222-222222222222',
    'submissionReference'=>'33333333-3333-4333-8333-333333333333',
    'siteReference'=>'44444444-4444-4444-8444-444444444444',
    'formReference'=>'55555555-5555-4555-8555-555555555555',
    'destinationReference'=>'66666666-6666-4666-8666-666666666666',
];
$draft=static fn(array $changes=[]):Draft=>new Draft(
    ...array_values(array_replace($fixture,$changes))
);
$claim=static function(Draft $d):CrmClaim {
    return new CrmClaim(
        new CrmIdentity(
            $d->organizationReference,$d->bindingReference,$d->submissionReference
        ),$d->siteReference,$d->formReference,$d->destinationReference
    );
};

$original=$draft();
$sites=new SitesIntent($original);
$crm=$claim($original);
$cas=new Cas();
$open=new CrmSnapshot($crm,Phase::Open,4);

f132Check($sites->syntheticLedgerFingerprint()===$crm->syntheticLedgerFingerprint() &&
    preg_match('/\A[a-f0-9]{64}\z/D',$sites->syntheticLedgerFingerprint())===1,
    'independently computed Sites and CRM F3-12 logical fingerprints match');
f132Check($sites->sameLogicalScopeAs(new SitesIntent($draft())) &&
    $sites->samePinnedContextAs(new SitesIntent($draft())),
    'exact original metadata stays stable for a retry');
f132Check(!$sites->canCommitReservation() &&
    !$sites->canAcknowledgeVisitor() && !$sites->canDeliverToCrm(),
    'Sites never turns a fingerprint or candidate into approval');

$proposed=$cas->inspect($open,$crm,4);
f132Check($proposed->reasonCode==='ATOMIC_STORE_REQUIRED' &&
    $proposed->proposedNextVersion===5 &&
    !$proposed->canCommitReservation() &&
    !$proposed->canRetryDelivery() &&
    !$proposed->canCreateLead(),
    'CRM pure CAS classifier requires a real atomic store and grants nothing');
$simultaneous=$cas->inspect($open,$crm,4);
f132Check($simultaneous->reasonCode==='ATOMIC_STORE_REQUIRED' &&
    $simultaneous->proposedNextVersion===$proposed->proposedNextVersion &&
    !$simultaneous->canCommitReservation(),
    'two simultaneous receivers cannot elect a winner using pure CAS inspection');

foreach([
    'bindingReference'=>'77777777-7777-4777-8777-777777777777',
    'siteReference'=>'77777777-7777-4777-8777-777777777777',
    'formReference'=>'77777777-7777-4777-8777-777777777777',
    'destinationReference'=>'77777777-7777-4777-8777-777777777777',
] as $field=>$replacement) {
    $changed=$draft([$field=>$replacement]);
    $otherSites=new SitesIntent($changed);
    $otherCrm=$claim($changed);
    $assessment=$cas->inspect($open,$otherCrm,4);
    f132Check($sites->sameLogicalScopeAs($otherSites) &&
        !$sites->samePinnedContextAs($otherSites) &&
        $sites->syntheticLedgerFingerprint()===$otherSites->syntheticLedgerFingerprint() &&
        $crm->sameLogicalLedgerScopeAs($otherCrm) &&
        !$crm->samePinnedContextAs($otherCrm) &&
        $assessment->reasonCode==='PINNED_CONTEXT_CONFLICT' &&
        !$assessment->canCreateLead(),
        'same submission cannot move original context: '.$field);
}
foreach([
    'organizationReference'=>'88888888-8888-4888-8888-888888888888',
    'submissionReference'=>'88888888-8888-4888-8888-888888888888',
] as $field=>$replacement) {
    $changed=$draft([$field=>$replacement]);
    $otherSites=new SitesIntent($changed);
    $otherCrm=$claim($changed);
    f132Check(!$sites->sameLogicalScopeAs($otherSites) &&
        $sites->syntheticLedgerFingerprint()!==$otherSites->syntheticLedgerFingerprint() &&
        !$crm->sameLogicalLedgerScopeAs($otherCrm) &&
        $cas->inspect($open,$otherCrm,4)->reasonCode==='LOGICAL_SCOPE_MISMATCH',
        'different organization or submission MUST be another ledger scope: '.$field);
}

f132Check($cas->inspect($open,$crm,3)->reasonCode==='STALE_SNAPSHOT_RECONCILE',
    'stale CAS version requires reconciliation, not speculative retry');
f132Check($cas->inspect($open,$crm,-1)->reasonCode==='VERSION_INVALID',
    'invalid negative version cannot reserve');
f132Check($cas->inspect($open,$crm,4,'force_delivered')->reasonCode==='OPERATION_NOT_SUPPORTED',
    'synthetic protocol cannot force delivery');
foreach([
    [Phase::Reserved,'IN_FLIGHT_REVIEW'],
    [Phase::OutcomeUnknown,'UNKNOWN_OUTCOME_LOOKUP_REQUIRED'],
    [Phase::DeliveredClaimed,'DELIVERED_CLAIM_REVIEW'],
    [Phase::ExpiredClaimed,'EXPIRED_CLAIM_REVIEW'],
] as [$phase,$reason]) {
    $result=$cas->inspect(new CrmSnapshot($crm,$phase,4),$crm,4);
    f132Check($result->reasonCode===$reason &&
        $result->proposedNextVersion===null &&
        !$result->canCommitReservation() &&
        !$result->canRetryDelivery(),
        'nonopen or unknown CRM state cannot reserve: '.$reason);
}
$overflow=$cas->inspect(new CrmSnapshot($crm,Phase::Open,PHP_INT_MAX),$crm,PHP_INT_MAX);
f132Check($overflow->reasonCode==='VERSION_OVERFLOW' &&
    $overflow->proposedNextVersion===null,
    'CRM CAS counter overflow cannot wrap into a reservable version');

$evidence=new Evidence();
$recordId='99999999-9999-4999-8999-999999999999';
foreach(['primary','contingency'] as $receiver) {
    $claims=$evidence->inspect($crm->identity,$crm->identity,$receiver,201,201,$recordId);
    f132Check($claims->reasonCode==='TRUSTED_EVIDENCE_REQUIRED' &&
        !$claims->canAcknowledgeVisitor() &&
        !$claims->canMarkDeliveredToCrm() &&
        !$claims->canRetryAutomatically(),
        'even matching HTTP 201 and a claimed CRM ID do NOT prove delivery: '.$receiver);
}
foreach([
    ['primary',null,null,null,'RECEIPT_OUTCOME_UNKNOWN'],
    ['contingency',202,null,null,'DURABLE_RECEIPT_NOT_PROVEN'],
    ['primary',201,null,$recordId,'CRM_OUTCOME_UNKNOWN'],
    ['contingency',201,202,$recordId,'CRM_PERSISTENCE_NOT_PROVEN'],
    ['primary',201,200,null,'CRM_RECORD_UNVERIFIED'],
] as [$receiver,$apiStatus,$crmStatus,$id,$reason]) {
    $assessment=$evidence->inspect(
        $crm->identity,$crm->identity,$receiver,$apiStatus,$crmStatus,$id
    );
    f132Check($assessment->reasonCode===$reason &&
        !$assessment->canAcknowledgeVisitor() &&
        !$assessment->canMarkDeliveredToCrm() &&
        !$assessment->canRetryAutomatically(),
        'no delivery/receipt permission from unverified transport observation: '.$reason);
}
$otherBinding=$claim($draft([
    'bindingReference'=>'77777777-7777-4777-8777-777777777777'
]));
$changedReceiver=$evidence->inspect(
    $crm->identity,$otherBinding->identity,'primary',201,201,$recordId
);
f132Check($changedReceiver->reasonCode==='SUBMISSION_CONTEXT_MISMATCH' &&
    !$changedReceiver->canMarkDeliveredToCrm(),
    'receiver cannot confirm a submission with changed CRM binding');

$serialized=json_encode($proposed,JSON_THROW_ON_ERROR);
f132Check(!str_contains($serialized,$original->organizationReference) &&
    !str_contains($serialized,$original->submissionReference) &&
    !str_contains($serialized,$original->destinationReference),
    'CAS assessment never echoes original IDs or customer payload');
echo "\n$checks F1-132 real CRM F3-07/F3-12 metadata boundaries passed. ".
    "No lead, durable CAS, ACK, delivery or CRM call.\n";
