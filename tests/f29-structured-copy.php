<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\Production\\';
    if (!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Invalid synthetic test class.');
    }
    require __DIR__.'/../src/Production/'.$name.'.php';
});

use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\StructuredCopyCandidate;
use Imagine\Sites\Production\SyntheticProductionWorkflow;

$checks=0;
function f29Check(bool $yes,string $label):void {
    global $checks;
    if (!$yes)throw new RuntimeException('FAIL '.$label);
    ++$checks;
    echo 'PASS '.$label."\n";
}
function f29Denied(callable $action):bool {
    try {$action();}catch (DomainException|InvalidArgumentException) {return true;}
    return false;
}
$base=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Soluções de confiança',
    'description'=>'Construímos caminhos eficientes para sua empresa & seu negócio.',
    'callToAction'=>'Saiba mais',
    'benefits'=>['Atendimento próximo','Projetos personalizados'],
];
$brief=StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$base);
f29Check($brief->brand==='Marca oficial fictícia' &&
    $brief->productCode==='onepage',
    'brand and product are bound by caller, not the AI response');
f29Check($brief->headline===$base['headline'],
    'strictly typed AI copy maps into structured SiteBrief');
$a=(new StaticSiteRenderer())->render($brief);
$b=(new StaticSiteRenderer())->render(
    StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$base));
f29Check($a->digest===$b->digest && InertPreviewAudit::isSafe($a->html),
    'trusted local renderer makes deterministic, inert preview');
$landing=(new StaticSiteRenderer())->render(
    StructuredCopyCandidate::assemble('landing_page','Marca oficial fictícia',$base));
f29Check($a->digest!==$landing->digest && InertPreviewAudit::isSafe($landing->html),
    'same copy supports separate Onepage and Landing Page templates');

$copy=$base;$copy['brand']='Marca inventada';
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'generator cannot override official brand');
$copy=$base;$copy['productCode']='landing_page';
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'generator cannot override purchased product');
$copy=$base;$copy['tenantRef']='tenant-attacker';
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'generator cannot declare tenant authority');
$copy=$base;$copy['publishable']=true;
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'generator cannot mark release publishable');
$copy=$base;$copy['officialClientAcceptance']=true;
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'generator cannot forge client acceptance');
$copy=$base;$copy['schemaVersion']='sites.ai-copy.v2';
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'unknown response schema version is denied');
$copy=$base;unset($copy['benefits']);
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'missing field denied');
$copy=$base;$copy['headline']=['raw'=>'HTML'];
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'non-string headline denied');
$copy=$base;$copy['headline']='<script>alert(1)</script>';
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'HTML tags rejected, rather than being interpreted');
$copy=$base;$copy['description']='https://invalid.example/exfiltrate';
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'external URL in AI copy denied');
$copy=$base;$copy['callToAction']="Contato\ninvisível";
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'control characters in CTA denied');
$copy=$base;$copy['benefits']=['javascript:alert(1)'];
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'script pseudo-URL denied in benefits');
$copy=$base;$copy['benefits']=['2'=>'irregular'];
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'non-list benefit dictionary denied');
$copy=$base;$copy['benefits']=array_fill(0,9,'Benefício');
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'excessive benefits denied via SiteBrief');
$copy=$base;$copy['headline']=str_repeat('X',3000);
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy,512)),
    'synthetic byte budget stops oversized response');
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$base,100)),
    'invalid generation byte quota denied');
$copy=$base;$copy['headline']="\xC3\x28";
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'invalid UTF-8 denied');
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('blog','Marca oficial fictícia',$base)),
    'disallowed product cannot be generated');
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','',$base)),
    'missing trusted brand denied');
$copy=$base;$copy['benefits']=[];
f29Check(f29Denied(fn()=>StructuredCopyCandidate::assemble('onepage','Marca oficial fictícia',$copy)),
    'empty benefits denied');

$wf=SyntheticProductionWorkflow::create('tenant-fake','site-fake','job-fake');
$wf=$wf->advance('agent','start_draft',0);
$wf=$wf->advance('agent','submit_draft',1,$a->digest);
f29Check($wf->artifactDigest===$a->digest &&
    $wf->state===SyntheticProductionWorkflow::INTERNAL_REVIEW,
    'structured copy leads to review, never to auto-acceptance');
f29Check(f29Denied(fn()=>$wf->advance('agent','approve_internal',2,$a->digest)),
    'AI does not have reviewer authority even for valid formatted copy');

echo "\n$checks F1-29 synthetic AI copy validation tests passed. No model, provider, Portal auth or deploy.\n";
