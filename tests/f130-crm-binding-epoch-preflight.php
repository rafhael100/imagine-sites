<?php

declare(strict_types=1);

/** F1-130: synthetic, metadata-only, NO lead PII, API calls or delivery. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\\A(?:Application|Domain|Integration)\\\\[A-Za-z][A-Za-z0-9]*\\z/D',$name)) {
        throw new RuntimeException('Unsafe F1-130 autoload path');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Application\SyntheticCrmLeadRetryPreflight;
use Imagine\Sites\Application\SyntheticCrmLeadRetryEpochFencedPreflight;
use Imagine\Sites\Domain\SyntheticCrmBindingSnapshot;
use Imagine\Sites\Domain\SyntheticLeadReceipt;
use Imagine\Sites\Integration\LeadBindingAuthoritySource;
use Imagine\Sites\Integration\LeadBindingEpochSource;

final class F130Binding implements LeadBindingAuthoritySource
{
    public int $calls=0;
    public ?Closure $onRead=null;
    public bool $offline=false;
    public ?SyntheticCrmBindingSnapshot $snapshot=null;

    public function currentFor(string $tenantRef,string $siteRef):?SyntheticCrmBindingSnapshot
    {
        ++$this->calls;
        if($this->onRead!==null) ($this->onRead)();
        if($this->offline) throw new RuntimeException('Synthetic binding service unavailable');
        return $this->snapshot;
    }
}

final class F130Epoch implements LeadBindingEpochSource
{
    public int $calls=0;
    public ?int $value=null;
    public bool $offline=false;
    public ?Closure $onRead=null;

    public function currentEpochFor(string $tenantRef,string $siteRef):?int
    {
        ++$this->calls;
        if($this->onRead!==null) ($this->onRead)();
        if($this->offline) throw new RuntimeException('Synthetic epoch service unavailable');
        if($tenantRef!=='tenant-f130' || $siteRef!=='site-f130') return null;
        return $this->value;
    }
}

$checks=0;
function f130Check(bool $passed,string $label):void
{
    global $checks;
    if(!$passed) throw new RuntimeException('FAIL: '.$label);
    ++$checks;
    echo 'PASS: '.$label."\n";
}
$t0=new DateTimeImmutable('2026-11-10T12:00:00+00:00');
$receipt=new SyntheticLeadReceipt(
    'tenant-f130','site-f130','submission-f130',
    'crm-account-f130',4,str_repeat('b',64),$t0
);
$sites=new F130Binding();
$crm=new F130Binding();
$sitesEpoch=new F130Epoch();
$crmEpoch=new F130Epoch();
$sitesEpoch->value=2;
$crmEpoch->value=9;
$snapshot=static fn(DateTimeImmutable $checkedAt):SyntheticCrmBindingSnapshot=>
    new SyntheticCrmBindingSnapshot(
        'tenant-f130','site-f130','crm-account-f130',4,true,$checkedAt
    );
$sites->snapshot=$snapshot($t0);
$crm->snapshot=$snapshot($t0);
$base=new SyntheticCrmLeadRetryPreflight($sites,$crm);
$guard=new SyntheticCrmLeadRetryEpochFencedPreflight($base,$sitesEpoch,$crmEpoch);

// Deny by default even if both binding sources agree.
$default=(new SyntheticCrmLeadRetryEpochFencedPreflight($base))->evaluate($receipt,$t0);
f130Check(!$default->allowed &&
    $default->reasons===['independent_binding_epoch_unavailable'],
    'no independent epoch authorities: fail closed');
f130Check($guard->evaluate($receipt,$t0)->allowed,
    'two independent stable positive epochs permit metadata preflight only');
f130Check($sitesEpoch->calls===2 && $crmEpoch->calls===2 &&
    $sites->calls===1 && $crm->calls===1,
    'each epoch source read before and after both binding snapshots');
f130Check($sitesEpoch->value!==$crmEpoch->value,
    'independent Sites and CRM epoch numbers need not match');

$sites->calls=$crm->calls=0;
$sitesEpoch->calls=$crmEpoch->calls=0;
$crm->onRead=static function() use($sitesEpoch):void { ++$sitesEpoch->value; };
$mutated=$guard->evaluate($receipt,$t0);
f130Check(!$mutated->allowed &&
    $mutated->reasons===['crm_binding_epoch_changed_during_preflight'],
    'Sites binding A-to-B-to-A with unchanged snapshot is detected by epoch change');
f130Check($sites->snapshot->bindingRevision===4 &&
    $crm->snapshot->crmAccountRef==='crm-account-f130',
    'changed Sites epoch did not alter original destination or binding snapshot');
$crm->onRead=null;

$sites->onRead=static function() use($crmEpoch):void { ++$crmEpoch->value; };
$mutated=$guard->evaluate($receipt,$t0);
f130Check(!$mutated->allowed &&
    $mutated->reasons===['crm_binding_epoch_changed_during_preflight'],
    'CRM binding A-to-B-to-A with unchanged snapshot detected independently');
$sites->onRead=null;

$sitesEpoch->value=null;
$beforeSites=$sites->calls;
f130Check(!$guard->evaluate($receipt,$t0)->allowed &&
    $sites->calls===$beforeSites,
    'missing commercial epoch denies without reading binding source');
$sitesEpoch->value=4;
$crmEpoch->offline=true;
f130Check(!$guard->evaluate($receipt,$t0)->allowed,
    'epoch authority outage before binding snapshot fails closed');
$crmEpoch->offline=false;

$crm->onRead=static function() use($crmEpoch):void { $crmEpoch->offline=true; };
try {
    $down=$guard->evaluate($receipt,$t0);
    f130Check(!$down->allowed &&
        $down->reasons===['independent_binding_epoch_unavailable'],
        'epoch outage after valid binding snapshot refuses candidate retry');
} finally {
    $crm->onRead=null;
    $crmEpoch->offline=false;
}

$crm->snapshot=new SyntheticCrmBindingSnapshot(
    'tenant-f130','site-f130','crm-other',4,true,$t0
);
f130Check(!$guard->evaluate($receipt,$t0)->allowed,
    'binding destination mismatch remains DENY with stable epochs');
$crm->snapshot=$snapshot($t0);

$oldSiteEpochCalls=$sitesEpoch->calls;
$oldCrmEpochCalls=$crmEpoch->calls;
$delivered=new SyntheticLeadReceipt(
    'tenant-f130','site-f130','submission-f130',
    'crm-account-f130',4,str_repeat('b',64),$t0,'delivered'
);
f130Check(!$guard->evaluate($delivered,$t0)->allowed &&
    $sitesEpoch->calls===$oldSiteEpochCalls &&
    $crmEpoch->calls===$oldCrmEpochCalls,
    'delivered receipt denied before consulting independent epoch sources');
f130Check(!$guard->evaluate($receipt,$t0->modify('+432 hours'))->allowed &&
    $sitesEpoch->calls===$oldSiteEpochCalls &&
    $crmEpoch->calls===$oldCrmEpochCalls,
    'immutable 432-hour expiry rejects retry without authority lookups');
f130Check(!$guard->evaluate($receipt,$t0->modify('-1 second'))->allowed,
    'retry earlier than durable T0 is denied');
$nearDeadline=$t0->modify('+431 hours +59 minutes');
$sites->snapshot=$snapshot($nearDeadline);
$crm->snapshot=$snapshot($nearDeadline);
f130Check($guard->evaluate($receipt,$nearDeadline)->allowed,
    'last minute of original 432-hour window remains open with fresh binding and epochs');
f130Check($receipt->crmAccountRef==='crm-account-f130' &&
    $receipt->durableReceivedAt===$t0,
    'original CRM destination and durable T0 stay immutable');

echo "\n$checks F1-130 synthetic epoch-fenced CRM preflight assertions passed. No PII, no CRM delivery.\n";
