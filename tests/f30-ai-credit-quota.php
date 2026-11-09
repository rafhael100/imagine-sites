<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\Production\\';
    if (!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Invalid synthetic class name');
    }
    require __DIR__.'/../src/Production/'.$name.'.php';
});

use Imagine\Sites\Production\StructuredCopyCandidate;
use Imagine\Sites\Production\SyntheticCreditLedger as L;
use Imagine\Sites\Production\SyntheticProductionWorkflow as W;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;

$checks=0;
function f30Check(bool $ok,string $reason):void {
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL '.$reason);
    ++$checks;
    echo 'PASS '.$reason."\n";
}
function f30Denied(callable $run):bool {
    try{$run();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}

$initial=L::create(['tenant-a'=>6,'tenant-b'=>3],3);
f30Check($initial->remaining('tenant-a')===6 &&
    $initial->remaining('tenant-b')===3,'independent per-tenant synthetic budgets initialized');
$one=$initial->reserve('tenant-a','op-001','workflow-001',2);
f30Check($one->remaining('tenant-a')===4 && $initial->remaining('tenant-a')===6,
    'immutable reservation does not mutate earlier snapshot');
$retry=$one->reserve('tenant-a','op-001','workflow-001',2);
f30Check($retry->remaining('tenant-a')===4,
    'identical retry returns no additional debit');
f30Check(f30Denied(fn()=>$one->reserve('tenant-a','op-001','workflow-001',3)),
    'same operation with changed credits is conflicting');
f30Check(f30Denied(fn()=>$one->reserve('tenant-a','op-001','other-workflow',2)),
    'same operation for other workflow is conflicting');
f30Check(f30Denied(fn()=>$one->reserve('unknown-tenant','op-001','workflow-001',1)),
    'unregistered tenant denied');
f30Check(f30Denied(fn()=>$one->reserve('tenant-a','../other','workflow-001',1)),
    'unsafe operation reference denied');
f30Check(f30Denied(fn()=>$one->reserve('tenant-a','op-002','workflow-001',4)),
    'large operation denied before spending');
f30Check(f30Denied(fn()=>$one->reserve('tenant-a','op-002','workflow-001',0)),
    'zero-credit operation denied');
f30Check(f30Denied(fn()=>L::create(['tenant-a'=>-1])),
    'negative budget denied');
f30Check(f30Denied(fn()=>L::create(['tenant-a'=>'infinite'])),
    'non-integer budget denied');
f30Check(f30Denied(fn()=>L::create(['tenant-a'=>5],501)),
    'unbounded per-action allowance denied');

$two=$one->reserve('tenant-a','op-002','workflow-001',3);
f30Check($two->remaining('tenant-a')===1,'second operation reserves within budget');
f30Check(f30Denied(fn()=>$two->reserve('tenant-a','op-003','workflow-001',2)),
    'tenant total budget exhausted denies additional reservation');
$other=$two->reserve('tenant-b','op-001','workflow-b',2);
f30Check($other->remaining('tenant-a')===1 &&
    $other->remaining('tenant-b')===1,
    'same operation ID in another tenant uses separate quota');
f30Check(f30Denied(fn()=>$other->reserve('tenant-b','op-002','workflow-b',2)),
    'tenant B cannot borrow quota from tenant A');

$copy=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Conecte sua empresa',
    'description'=>'Soluções experimentais para negócios de todos os tamanhos.',
    'callToAction'=>'Conheça',
    'benefits'=>['Simplicidade','Clareza'],
];
$brief=StructuredCopyCandidate::assemble('onepage','Marca fictícia',$copy);
$artifact=(new StaticSiteRenderer())->render($brief);
f30Check(InertPreviewAudit::isSafe($artifact->html),
    'synthetic structured copy can produce inert preview without network');

$workflow=W::create('tenant-a','site-fake','workflow-001',2);
$workflow=$workflow->advance('agent','start_draft',0);
$workflow=$workflow->advance('agent','submit_draft',1,$artifact->digest);
f30Check($workflow->state===W::INTERNAL_REVIEW &&
    $one->remaining('tenant-a')===4,
    'F1-28 + F1-29 copy remains only in internal review, quotas separate');
f30Check(f30Denied(fn()=>$workflow->advance('agent','approve_internal',2,$artifact->digest)),
    'fictitious credits never grant approval permissions');
f30Check(f30Denied(fn()=>$one->reserve('tenant-a','op-001','workflow-OTHER',2)),
    'idempotent key cannot be reused for an unrelated job');

echo "\n$checks F1-30 fictional in-memory credit checks passed. No paid AI API or production quota.\n";
