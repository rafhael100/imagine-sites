<?php

declare(strict_types=1);

/** F1-71 end-to-end synthetic copy → inert preview → private review draft. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('Invalid fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticReleaseBundle;

$checks=0;
function f71Assert(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    ++$checks;
    echo "PASS $label\n";
}
function f71Denied(callable $call):bool {
    try {$call();} catch(DomainException|InvalidArgumentException) {return true;}
    return false;
}

$copy=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Uma solução segura para pequenas empresas',
    'description'=>'Demonstração inteiramente fictícia com benefícios de organização e apresentação de serviços.',
    'callToAction'=>'Conheça os benefícios',
    'benefits'=>['Clareza na comunicação','Organização dos processos','Informações acessíveis'],
];
$build=static fn(string $product,string $style,array $draft=[])=>Assembly::build(
    $product,'Imagine Exemplo', $style,$draft?:$copy,
    'tenant-f71','site-f71','workflow-f71','release-f71',
);
$hashes=[];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $a=$build($product,$style);
        $b=$build($product,$style);
        $s=$a->reviewSummary();
        f71Assert($a->workflow->state===Workflow::INTERNAL_REVIEW &&
            $a->workflow->revision===2 &&
            $a->workflow->artifactDigest===$a->artifact->digest,
            "$product/$style: assembled draft waits for internal review");
        f71Assert($s['publishable']===false &&
            $s['officialClientAcceptance']===false &&
            $s['internalReviewRequired']===true &&
            $s['portalAuthorityRequired']===true &&
            $s['workflowState']===Workflow::INTERNAL_REVIEW,
            "$product/$style: no publish/accept capability");
        f71Assert($a->artifact->digest===$b->artifact->digest &&
            $a->artifact->html===$b->artifact->html &&
            $a->bundle->files===$b->bundle->files &&
            hash('sha256',$a->artifact->html)===$a->artifact->digest,
            "$product/$style: deterministic exact output");
        f71Assert($a->structuralReport->passedStructuralChecks &&
            InertPreviewAudit::isSafe($a->artifact->html) &&
            SyntheticReleaseBundle::verifyFiles($a->bundle->files,$a->manifest),
            "$product/$style: safe, verifiable offline artifact");
        f71Assert(f71Denied(fn()=>$a->workflow->portalReviewCandidate(
            'release-f71',$a->artifact->digest,2)) &&
            f71Denied(fn()=>$a->workflow->advance(
                'agent','approve_internal',2,$a->artifact->digest)),
            "$product/$style: agent cannot approve or send Portal proposal");
        f71Assert($a->brief->brand==='Imagine Exemplo' &&
            $a->brief->stylePreset===$style &&
            $a->brief->productCode===$product,
            "$product/$style: externally selected identity/preset preserved");
        $reviewed=$a->workflow->advance(
            'imagine_reviewer','approve_internal',2,$a->artifact->digest);
        $informational=$reviewed->portalReviewCandidate(
            'release-f71',$a->artifact->digest,3);
        f71Assert($informational['publishable']===false &&
            $informational['officialClientAcceptance']===false &&
            $informational['portalAuthorityRequired']===true,
            "$product/$style: separate reviewer still cannot publish");
        $hashes[]=$a->artifact->digest;
    }
}
f71Assert(count(array_unique($hashes))===6,
    'two products and three fixed styles produce six distinct artifacts');
$bad=[
    ['schemaVersion'=>'sites.ai-copy.v2']+$copy,
    array_replace($copy,['schemaVersion'=>'sites.ai-copy.v2']),
    $copy+['publishable'=>true],
    $copy+['officialClientAcceptance'=>true],
    array_replace($copy,['headline'=>'<script>payload</script>']),
    array_replace($copy,['description'=>'https://remote.invalid/']),
    array_replace($copy,['benefits'=>['good','javascript:alert(1)']]),
    array_replace($copy,['benefits'=>['valid',55]]),
    array_replace($copy,['benefits'=>[]]),
];
foreach($bad as $i=>$candidate) {
    f71Assert(f71Denied(fn()=>$build('onepage','classic',$candidate)),
        "unsafe or forged copy $i is denied");
}
f71Assert(f71Denied(fn()=>$build('invalid','classic')),'invalid product denied');
f71Assert(f71Denied(fn()=>$build('onepage','url(https://invalid)')),
    'arbitrary visual style denied');
f71Assert(f71Denied(fn()=>Assembly::build(
    'onepage','Imagine Exemplo','classic',$copy,'tenant-f71','site-f71','workflow-f71','../invalid'
)), 'invalid release reference denied');
$poor=array_replace($copy,[
    'headline'=>'Oi',
    'description'=>'Descrição curta',
    'callToAction'=>'Ir',
    'benefits'=>['Clareza'],
]);
$advisory=$build('onepage','classic',$poor);
f71Assert(count($advisory->editorialReport->suggestions)>0 &&
    $advisory->workflow->state===Workflow::INTERNAL_REVIEW &&
    $advisory->reviewSummary()['publishable']===false,
    'editorial findings remain advisory; approval is never implicit');
echo "\n$checks F1-71 draft assembly checks passed. No live AI, customer, publication or approval.\n";
