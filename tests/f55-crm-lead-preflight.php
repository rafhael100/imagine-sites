<?php

declare(strict_types=1);

/** F1-55: fictional lead metadata only; never store PII or contact CRM. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Application|Integration)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Invalid synthetic class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Application\SyntheticCrmLeadRetryPreflight as Guard;
use Imagine\Sites\Domain\SyntheticCrmBindingSnapshot as Binding;
use Imagine\Sites\Domain\SyntheticLeadReceipt as Receipt;
use Imagine\Sites\Integration\LeadBindingAuthoritySource as Source;

final class F55BindingSource implements Source
{
    public bool $offline=false;
    public ?Binding $record=null;
    public int $calls=0;
    public function currentFor(string $tenantRef,string $siteRef):?Binding
    {
        ++$this->calls;
        if($this->offline)throw new RuntimeException('Offline synthetic binding API');
        return $this->record;
    }
}
$n=0;
function f55Assert(bool $ok,string $name):void {
    global $n;
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    ++$n;echo "PASS $name\n";
}
function f55Denied(callable $fn):bool {
    try{$fn();}catch(InvalidArgumentException|DomainException){return true;}
    return false;
}
$t0=new DateTimeImmutable('2026-11-09T12:00:00Z');
$receipt=new Receipt('tenant-f55-demo','site-f55-demo','submit-f55-demo',
    'crm-f55-original',3,str_repeat('a',64),$t0);
$sites=new F55BindingSource();
$crm=new F55BindingSource();
$make=static fn(array $patch=[]):Binding=>new Binding(
    $patch['tenantRef']??'tenant-f55-demo',
    $patch['siteRef']??'site-f55-demo',
    $patch['crmAccountRef']??'crm-f55-original',
    $patch['bindingRevision']??3,
    $patch['active']??true,
    $patch['checkedAt']??$t0
);
$sites->record=$make();
$crm->record=$make();
$gate=new Guard($sites,$crm);
f55Assert(!(new Guard())->evaluate($receipt,$t0)->allowed,
    'DENY-default without two independently supplied binding sources');
f55Assert($gate->evaluate($receipt,$t0)->allowed,
    'two matching fresh fictional bindings allow preflight only');
f55Assert($sites->calls===1 && $crm->calls===1,
    'both independently injected sources are queried');
f55Assert($gate->evaluate($receipt,$t0->modify('+29 seconds'))->allowed,
    'fresh binding snapshot valid through 29 seconds');
f55Assert(!$gate->evaluate($receipt,$t0->modify('+30 seconds'))->allowed,
    'stale binding snapshot denied at 30 seconds');
foreach(['tenantRef'=>'tenant-attacker','siteRef'=>'site-other',
    'crmAccountRef'=>'crm-new-destination','bindingRevision'=>4,'active'=>false] as $field=>$value) {
    $crm->record=$make([$field=>$value]);
    f55Assert(!$gate->evaluate($receipt,$t0)->allowed,
        'second CRM source mismatched or revoked: '.$field);
}
$crm->record=$make();
foreach(['tenantRef'=>'tenant-other','siteRef'=>'site-other',
    'crmAccountRef'=>'crm-renamed','bindingRevision'=>6,'active'=>false] as $field=>$value) {
    $sites->record=$make([$field=>$value]);
    f55Assert(!$gate->evaluate($receipt,$t0)->allowed,
        'Sites source mismatch or revoked: '.$field);
}
$sites->record=$make();
$crm->offline=true;
f55Assert(!$gate->evaluate($receipt,$t0)->allowed,
    'CRM API unavailable: no speculative queue retry');
$crm->offline=false;
$sites->offline=true;
f55Assert(!$gate->evaluate($receipt,$t0)->allowed,
    'Sites API unavailable: no speculative queue retry');
$sites->offline=false;

foreach([
    ['delivered','receipt_already_final'],
    ['expired','receipt_already_final']
] as [$state,$reason]) {
    $final=new Receipt('tenant-f55-demo','site-f55-demo','submit-f55-demo',
        'crm-f55-original',3,str_repeat('a',64),$t0,$state);
    $decision=$gate->evaluate($final,$t0);
    f55Assert(!$decision->allowed && in_array($reason,$decision->reasons,true),
        'no resend after final receipt state '.$state);
}
foreach([
    [$t0->modify('-1 second'),false,'before T0'],
    [$t0,true,'T0 inclusive'],
    [$t0->modify('+72 hours'),false,'72h with stale binding'],
    [$t0->modify('+431 hours +59 minutes'),false,'near deadline with stale binding'],
    [$t0->modify('+432 hours'),false,'432h expired'],
] as [$at,$expected,$desc]) {
    f55Assert($gate->evaluate($receipt,$at)->allowed===$expected,
        'preflight time scenario '.$desc);
}
f55Assert($gate->evaluate($receipt,$t0->modify('+432 hours'))->reasons===
    ['outside_immutable_432h_window'],
    '432h deadline blocks all retry work before any external source is called');

$freshAt72=new F55BindingSource();
$freshCrmAt72=new F55BindingSource();
$freshAt72->record=$make(['checkedAt'=>$t0->modify('+72 hours')]);
$freshCrmAt72->record=$make(['checkedAt'=>$t0->modify('+72 hours')]);
f55Assert((new Guard($freshAt72,$freshCrmAt72))->evaluate(
    $receipt,$t0->modify('+72 hours'))->allowed,
    'after 72h the recovery window remains open with fresh independent bindings');
$freshAt431=new F55BindingSource();
$freshCrm431=new F55BindingSource();
$freshAt431->record=$make(['checkedAt'=>$t0->modify('+431 hours +59 minutes')]);
$freshCrm431->record=$make(['checkedAt'=>$t0->modify('+431 hours +59 minutes')]);
f55Assert((new Guard($freshAt431,$freshCrm431))->evaluate(
    $receipt,$t0->modify('+431 hours +59 minutes'))->allowed,
    'just before 432h delivery deadline retries still permitted by policy');

$other=new Receipt('tenant-f55-demo','site-f55-demo','submit-f55-demo',
    'crm-f55-original',3,str_repeat('b',64),$t0);
f55Assert($receipt->deliveryFingerprint()!==$other->deliveryFingerprint(),
    'idempotency fingerprint binds original opaque digest');
f55Assert($receipt->deliveryFingerprint()===$receipt->deliveryFingerprint(),
    'idempotency key stable on same immutable receipt');
f55Assert(f55Denied(fn()=>new Receipt('../bad','site-f55-demo','submit-f55-demo',
    'crm-f55-original',3,str_repeat('a',64),$t0)),
    'unsafe tenant reference refused');
f55Assert(f55Denied(fn()=>new Receipt('tenant-f55-demo','site-f55-demo','submit-f55-demo',
    'crm-f55-original',0,str_repeat('a',64),$t0)),
    'zero binding version refused');
echo "\n$n F1-55 dual-binding CRM retry preflight checks passed. No PII, CRM call, or delivery.\n";
