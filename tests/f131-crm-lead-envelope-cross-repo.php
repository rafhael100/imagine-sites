<?php

declare(strict_types=1);

/** F1-131: independent CRM source pinned by CI; no lead payload or APIs. */
spl_autoload_register(static function(string $class):void {
    $sites='Imagine\\Sites\\Integration\\';
    $crm='App\\Core\\Leads\\';
    if(str_starts_with($class,$sites)) {
        $name=substr($class,strlen($sites));
        if(!preg_match('/\\A[A-Za-z][A-Za-z0-9]*\\z/D',$name)) {
            throw new RuntimeException('Unsafe Sites class name');
        }
        require __DIR__.'/../src/Integration/'.$name.'.php';
    } elseif(str_starts_with($class,$crm)) {
        $name=substr($class,strlen($crm));
        if(!preg_match('/\\A[A-Za-z][A-Za-z0-9]*\\z/D',$name)) {
            throw new RuntimeException('Unsafe CRM class name');
        }
        require __DIR__.'/../crm-candidate/app/Core/Leads/'.$name.'.php';
    }
});

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft;
use App\Core\Leads\SyntheticLeadEnvelopeV1Inspector;

$checks=0;
function f131Check(bool $ok,string $message):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL '.$message);
    ++$checks;
    echo 'PASS '.$message."\n";
}
function f131Denied(callable $fn):bool
{
    try { $fn(); } catch(InvalidArgumentException|DomainException) {return true;}
    return false;
}
$draft=new SyntheticCrmLeadEnvelopeV1Draft(
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
    '55555555-5555-4555-8555-555555555555',
    '66666666-6666-4666-8666-666666666666',
);
$inspector=new SyntheticLeadEnvelopeV1Inspector();
$wire=$draft->metadata();
$result=$inspector->inspect($wire);
f131Check(array_keys($wire)===[
    'protocol','schema_version','organization_reference',
    'binding_reference','submission_reference','site_reference',
    'form_reference','destination_reference',
], 'Sites candidate has exactly the eight CRM F3-11 metadata fields');
f131Check($result->reasonCode==='STRUCTURE_ONLY_UNVERIFIED' &&
    $result->isStructurallyCompatible(),
    'real CRM F3-11 inspector recognizes Sites candidate metadata structure');
f131Check(!$draft->canAuthorizeDelivery() &&
    !$result->canAcceptSubmission() &&
    !$result->canAcknowledgeDurableReceipt() &&
    !$result->canDeliverToCrm(),
    'both projects reject all submission/durable receipt/delivery permissions');

$wireJson=json_encode($wire,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$roundTrip=json_decode($wireJson,true,16,JSON_THROW_ON_ERROR);
f131Check($inspector->inspect($roundTrip)->isStructurallyCompatible() &&
    $draft->metadata()===$roundTrip,
    'JSON roundtrip preserves strict CRM envelope shape without lead payload');
f131Check(!str_contains($wireJson,'@') && !str_contains($wireJson,'payload') &&
    !str_contains($wireJson,'consent') && !str_contains($wireJson,'received_at'),
    'technical metadata has no PII, consent claim or caller timestamp');

$reordered=array_reverse($wire,true);
f131Check($inspector->inspect($reordered)->isStructurallyCompatible() &&
    !$inspector->inspect($reordered)->canDeliverToCrm(),
    'CRM permits field reordering but never treats order as authority');

$bad=[
    'extra lead PII'=>$wire+['email'=>'fake@example.invalid'],
    'extra purported consent'=>$wire+['consent'=>true],
    'forged first-persist timestamp'=>$wire+['first_persisted_at'=>'2026-11-10T12:00:00Z'],
    'missing binding'=>array_diff_key($wire,['binding_reference'=>true]),
    'missing destination'=>array_diff_key($wire,['destination_reference'=>true]),
    'unknown protocol'=>array_replace($wire,['protocol'=>'not-imagine']),
    'unknown schema'=>array_replace($wire,['schema_version'=>2]),
    'string schema'=>array_replace($wire,['schema_version'=>'1']),
    'forged destination'=>array_replace($wire,['destination_reference'=>'other-tenant']),
    'nil submission'=>array_replace($wire,['submission_reference'=>'00000000-0000-0000-0000-000000000000']),
    'nested organization'=>array_replace($wire,['organization_reference'=>['uid'=>$wire['organization_reference']]]),
];
foreach($bad as $reason=>$candidate) {
    $assessment=$inspector->inspect($candidate);
    f131Check(!$assessment->isStructurallyCompatible() &&
        !$assessment->canAcceptSubmission() &&
        !$assessment->canAcknowledgeDurableReceipt() &&
        !$assessment->canDeliverToCrm(),
        'real CRM inspector denies unsafe candidate '.$reason);
}
f131Check(f131Denied(fn()=>new SyntheticCrmLeadEnvelopeV1Draft(
    'not-a-uuid',$wire['binding_reference'],$wire['submission_reference'],
    $wire['site_reference'],$wire['form_reference'],$wire['destination_reference']
)), 'Sites refuses invalid organization reference before constructing metadata');
f131Check(f131Denied(fn()=>new SyntheticCrmLeadEnvelopeV1Draft(
    $wire['organization_reference'],$wire['binding_reference'],
    '00000000-0000-0000-0000-000000000000',
    $wire['site_reference'],$wire['form_reference'],$wire['destination_reference']
)), 'Sites refuses nil submission identifier before constructing metadata');

$retry=$draft->metadata();
f131Check($retry===$wire && !$inspector->inspect($retry)->canDeliverToCrm(),
    'identical retry has stable metadata but no dedup or CRM authorization');

$otherSubmission=new SyntheticCrmLeadEnvelopeV1Draft(
    $wire['organization_reference'],$wire['binding_reference'],
    '77777777-7777-4777-8777-777777777777',
    $wire['site_reference'],$wire['form_reference'],$wire['destination_reference']
);
f131Check($otherSubmission->metadata()['submission_reference']!==$wire['submission_reference'] &&
    $inspector->inspect($otherSubmission->metadata())->isStructurallyCompatible() &&
    !$otherSubmission->canAuthorizeDelivery(),
    'distinct submissions remain distinct and structurally valid, never deliverable');

$upper=new SyntheticCrmLeadEnvelopeV1Draft(
    strtoupper($wire['organization_reference']),
    $wire['binding_reference'],$wire['submission_reference'],
    $wire['site_reference'],$wire['form_reference'],$wire['destination_reference']
);
f131Check($upper->metadata()['organization_reference']===$wire['organization_reference'],
    'Sites normalizes UUID claims to lowercase for deterministic metadata');

echo "\n$checks F1-131 independent CRM envelope structure checks passed. No PII, binding authority, DB, or delivery.\n";
