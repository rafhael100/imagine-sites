<?php

declare(strict_types=1);

/**
 * F1-263: synthetic Sites-to-CRM lead preview with privacy, pinned binding
 * and idempotent retry scope. No HTTP, PII, consent receipt, storage or ACK.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\Integration\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unexpected F1-263 class name');
    }
    require __DIR__.'/../src/Integration/'.$part.'.php';
});
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Envelope;
use Imagine\Sites\Integration\SyntheticContactPrivacyPreflightV1 as Privacy;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySnapshot as Snapshot;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySource as Source;
use Imagine\Sites\Integration\SyntheticLeadIntakePrivacyGateV1 as Gate;

$org='11111111-1111-4111-8111-111111111111';
$bind='22222222-2222-4222-8222-222222222222';
$submission='33333333-3333-4333-8333-333333333333';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$dest='66666666-6666-4666-8666-666666666666';
$other='77777777-7777-4777-8777-777777777777';

$envelope=new Envelope($org,$bind,$submission,$site,$form,$dest);
$snapshot=new Snapshot($org,$bind,$site,$form,$dest,3,true);
$trustedLooking=new class($snapshot) implements Source {
    public function __construct(private ?Snapshot $snapshot) {}
    public function currentBinding(string $organization,string $binding):?Snapshot {
        return $this->snapshot;
    }
};
$exploding=new class implements Source {
    public function currentBinding(string $organization,string $binding):?Snapshot {
        throw new RuntimeException('Synthetic unavailable source');
    }
};
$goodPrivacy=Privacy::inspect(
    'Pessoa de Demonstração','fictional@example.invalid',null,true,false
);
$blockedConsent=Privacy::inspect(
    'Pessoa de Demonstração','fictional@example.invalid',null,false,false
);
$blockedBot=Privacy::inspect(
    'Pessoa de Demonstração','fictional@example.invalid',null,true,true
);
$blockedUnicode=Privacy::inspect(
    "Pessoa\u{202E} Exemplo",'fictional@example.invalid',null,true,false
);
$gate=new Gate($trustedLooking);
$ready=$gate->inspect($envelope,$goodPrivacy);
if($ready->reasonCode!=='S2S_REVIEW_CANDIDATE_UNVERIFIED' ||
   !$ready->readyForIndependentS2SReview()) {
    throw new RuntimeException('F1-263 synthetically pinned privacy plan rejected');
}
$checks=1;
function verify263(string $expected,object $report,string $label):void {
    global $checks;
    if($report->reasonCode!==$expected ||
       $report->readyForIndependentS2SReview()!==(
           $expected==='S2S_REVIEW_CANDIDATE_UNVERIFIED'
       ) ||
       $report->canStoreContact() ||
       $report->canRecordLegalConsent() ||
       $report->canReserveSubmission() ||
       $report->canSendToCrm() ||
       $report->canAcknowledgeVisitor()) {
        throw new RuntimeException('F1-263 '.$label);
    }
    $json=json_encode(get_object_vars($report),JSON_THROW_ON_ERROR);
    foreach(['fictional@example.invalid','Pessoa de Demonstração'] as $pii) {
        if(str_contains($json,$pii)) {
            throw new RuntimeException('F1-263 private contact leaked to report');
        }
    }
    ++$checks;
}
verify263('BINDING_AUTHORITY_UNAVAILABLE',
    (new Gate())->inspect($envelope,$goodPrivacy),'default-deny binding source');
verify263('BINDING_AUTHORITY_UNAVAILABLE',
    (new Gate($exploding))->inspect($envelope,$goodPrivacy),'provider error denied');
verify263('BINDING_BINDING_NOT_FOUND',
    (new Gate(new class implements Source {
        public function currentBinding(string $o,string $b):?Snapshot{return null;}
    }))->inspect($envelope,$goodPrivacy),'missing binding');
verify263('BINDING_BINDING_INACTIVE',
    (new Gate(new class($org,$bind,$site,$form,$dest) implements Source {
        public function __construct(
            private string $o,private string $b,private string $s,
            private string $f,private string $d
        ){}
        public function currentBinding(string $o,string $b):?Snapshot {
            return new Snapshot($this->o,$this->b,$this->s,$this->f,$this->d,4,false);
        }
    }))->inspect($envelope,$goodPrivacy),'revoked binding');
verify263('BINDING_BINDING_CONTEXT_MISMATCH',
    (new Gate(new class($org,$bind,$site,$form,$other) implements Source {
        public function __construct(
            private string $o,private string $b,private string $s,
            private string $f,private string $d
        ){}
        public function currentBinding(string $o,string $b):?Snapshot {
            return new Snapshot($this->o,$this->b,$this->s,$this->f,$this->d,3,true);
        }
    }))->inspect($envelope,$goodPrivacy),'wrong destination');
foreach([$blockedConsent,$blockedBot,$blockedUnicode] as $status) {
    verify263('CONTACT_PRIVACY_REVIEW_INCOMPLETE',
        $gate->inspect($envelope,$status),'privacy preflight denied');
}

$otherSubmission=new Envelope($org,$bind,$other,$site,$form,$dest);
$changedSite=new Envelope($org,$bind,$submission,$other,$form,$dest);
$changedDestination=new Envelope($org,$bind,$submission,$site,$form,$other);
$changedTenant=new Envelope($other,$bind,$submission,$site,$form,$dest);
verify263('RETRY_SUBMISSION_SCOPE_MISMATCH',
    $gate->inspect($otherSubmission,$goodPrivacy,$envelope),
    'no substitution of submission UUID');
verify263('RETRY_SUBMISSION_SCOPE_MISMATCH',
    $gate->inspect($changedTenant,$goodPrivacy,$envelope),
    'no substitution of organization UUID');
verify263('RETRY_BINDING_CONTEXT_CHANGED',
    $gate->inspect($changedSite,$goodPrivacy,$envelope),
    'no cross-site replay within one submission');
verify263('RETRY_BINDING_CONTEXT_CHANGED',
    $gate->inspect($changedDestination,$goodPrivacy,$envelope),
    'no CRM destination switch on retry');
verify263('S2S_REVIEW_CANDIDATE_UNVERIFIED',
    $gate->inspect($envelope,$goodPrivacy,$envelope),
    'exact replay remains only a nonauthoritative candidate');
if($checks!==14)throw new RuntimeException('F1-263 test count drift: '.$checks);
echo "$checks F1-263 privacy/binding/retry lab cases passed; no CRM send/consent/ACK.\n";
