<?php

declare(strict_types=1);

/** F1-166: fail closed on binding races, revocations and missing source. */
spl_autoload_register(static function (string $class):void {
    foreach([
        'Imagine\\Sites\\Integration\\'=>__DIR__.'/../src/Integration/',
        'App\\Core\\Leads\\'=>__DIR__.'/../crm-current/app/Core/Leads/',
    ] as $prefix=>$dir) {
        if (!str_starts_with($class,$prefix))continue;
        $name=substr($class,strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name))
            throw new RuntimeException('Unexpected fixture class');
        require_once $dir.$name.'.php';
        return;
    }
});

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\SyntheticCrmTechnicalHandoffPreflight as Preflight;
use Imagine\Sites\Integration\SyntheticCrmTechnicalHandoffV1 as Wire;
use Imagine\Sites\Integration\SyntheticCrmTechnicalPreparationAssessment as Result;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySource as Source;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySnapshot as Snapshot;
use App\Core\Leads\PortalServiceHandoffInspector as ActualCrm;

$count=0;
function check166(bool $ok,string $name):void {
    global $count;
    if (!$ok)throw new RuntimeException('F1-166 '.$name);
    ++$count;
}
function neverGrants166(Result $r):bool {
    return !$r->canAuthenticateService() && !$r->canAcceptLead()
        && !$r->canSendLeadToCrm() && !$r->canAcknowledgeVisitor()
        && !$r->canRetryAutomatically() && !$r->canMarkDelivered();
}
function source166(array $items):Source {
    return new class($items) implements Source {
        private int $calls=0;
        public function __construct(private readonly array $snapshots){}
        public function currentBinding(string $organizationReference,
            string $bindingReference):?Snapshot {
            $item=$this->snapshots[$this->calls++]??null;
            if ($item==='throw')throw new RuntimeException('synthetic authority unavailable');
            return $item;
        }
    };
}
function snapshot166(Draft $draft,int $revision=7,bool $active=true,
   ?string $destination=null):Snapshot {
    return new Snapshot(
        $draft->organizationReference,
        $draft->bindingReference,
        $draft->siteReference,
        $draft->formReference,
        $destination??$draft->destinationReference,
        $revision,$active
    );
}
$envelope=new Draft(
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
    '55555555-5555-4555-8555-555555555555',
    '66666666-6666-4666-8666-666666666666',
);
$stable=snapshot166($envelope);
$changed=snapshot166($envelope,8);
$revoked=snapshot166($envelope,7,false);
$differentDestination=snapshot166($envelope,7,true,
    '99999999-9999-4999-8999-999999999999');
$now=2000000000;
$nonce='77777777-7777-4777-8777-777777777777';
$inspect=static fn(?Source $source,string $op='deliver',
    ?int $issuedAt=null,?int $expiresAt=null,?string $once=null):Result=>
    (new Preflight($source))->inspect(
        $envelope,$op,$issuedAt??($now-10),
        $expiresAt??($now+120),$now,$once??$nonce
    );
putenv('APP_ENV=testing');
putenv('SITES_F165_SYNTHETIC_AUTH=1');
putenv('SITES_F166_AUTHORITY_FENCE=1');
$success=$inspect(source166([$stable,$stable]));
check166($success->reasonCode==='LAB_WIRE_PREPARED_UNTRUSTED' &&
    $success->candidate instanceof Wire && neverGrants166($success),
    'matching independent-looking revisions only permit a non-authoritative CI wire');
$crm=new ActualCrm();
$pinned=[
    'organization_reference'=>$stable->organizationReference,
    'binding_reference'=>$stable->bindingReference,
    'site_reference'=>$stable->siteReference,
    'form_reference'=>$stable->formReference,
    'destination_reference'=>$stable->destinationReference,
];
$assessment=$crm->inspect(
    $envelope->metadata(),$pinned,$success->candidate->authentication,
    $now,Wire::FIXTURE_KEY,'deliver'
);
check166($assessment->reasonCode==='LAB_REQUEST_MAC_MATCH_UNTRUSTED' &&
    !$assessment->canAuthenticateService() &&
    !$assessment->canDeliverToCrm() &&
    !$assessment->canAcknowledgeVisitor(),
    'actual CRM sees technical MAC but denies all real operations');

$cases=[
    ['AUTHORITY_UNAVAILABLE',null],
    ['BINDING_NOT_FOUND',source166([null])],
    ['BINDING_INACTIVE',source166([$revoked])],
    ['BINDING_CONTEXT_MISMATCH',source166([$differentDestination])],
    ['REVALIDATION_MISSING',source166([$stable,null])],
    ['REVALIDATION_INACTIVE',source166([$stable,$revoked])],
    ['REVISION_CHANGED',source166([$stable,$changed])],
    ['REVALIDATION_CONTEXT_MISMATCH',source166([$stable,$differentDestination])],
    ['AUTHORITY_UNAVAILABLE',source166(['throw'])],
    ['AUTHORITY_UNAVAILABLE',source166([$stable,'throw'])],
];
foreach($cases as [$expected,$source]){
    $report=$inspect($source);
    check166($report->reasonCode===$expected && $report->candidate===null &&
        neverGrants166($report),
        'changed or untrusted binding cannot produce a signed proposal: '.$expected);
}
foreach([
    ['delete',null,null,null],
    ['deliver',$now+31,null,null],
    ['deliver',null,$now,null],
    ['deliver',null,null,'77777777-7777-1777-8777-777777777777'],
] as [$operation,$issued,$expires,$newNonce]){
    $report=$inspect(source166([$stable,$stable]),$operation,$issued,$expires,$newNonce);
    check166($report->reasonCode==='HANDOFF_REJECTED' &&
        $report->candidate===null && neverGrants166($report),
        'invalid technical operation/window/nonce fails even after stable fixture');
}
putenv('SITES_F166_AUTHORITY_FENCE');
$disabled=$inspect(source166([$stable,$stable]));
check166($disabled->reasonCode==='LAB_DISABLED' &&
    $disabled->candidate===null && neverGrants166($disabled),
    'missing fence opt-in cannot inspect or sign even fictional metadata');
putenv('SITES_F166_AUTHORITY_FENCE=1');
putenv('SITES_F165_SYNTHETIC_AUTH');
$disabled=$inspect(source166([$stable,$stable]));
check166($disabled->reasonCode==='LAB_DISABLED' &&
    $disabled->candidate===null,'missing signer opt-in denies technical request');
putenv('SITES_F165_SYNTHETIC_AUTH=1');
putenv('APP_ENV=production');
$disabled=$inspect(source166([$stable,$stable]));
check166($disabled->reasonCode==='LAB_DISABLED' &&
    $disabled->candidate===null,'production env denies all lab candidate preparation');
putenv('APP_ENV=testing');
foreach(['LAB_DISABLED','REVISION_CHANGED','LAB_WIRE_PREPARED_UNTRUSTED'] as $code){
    $invalid=false;
    try {
        new Result($code,$code==='LAB_WIRE_PREPARED_UNTRUSTED'?null:$success->candidate);
    }catch(InvalidArgumentException){$invalid=true;}
    check166($invalid,'inconsistent diagnostic cannot smuggle untrusted wire: '.$code);
}
echo "$count F1-166 stable binding revision / CRM cross-repo checks passed. ".
    "No authenticated binding, real secrets, leads, transport, CRM ACK or deploy.\n";
