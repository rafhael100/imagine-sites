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
    Phase::Reserved=>'IN_FLIGHT_REVIEW',
    Phase::OutcomeUnknown=>'UNKNOWN_OUTCOME_LOOKUP_REQUIRED',
    Phase::DeliveredClaimed=>'DELIVERED_CLAIM_REVIEW',
    Phase::ExpiredClaimed=>'EXPIRED_CLAIM_REVIEW',
] as $phase=>$reason) {
    // PHP backed enum cases are objects and cannot be array keys; this
    // branch is replaced below by a list of pairs.
}
