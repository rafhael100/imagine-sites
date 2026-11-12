<?php

declare(strict_types=1);

/** F1-178: optional local-only navigation for an inert Onepage. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name))
        throw new RuntimeException('Unexpected fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as Style;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as Content;
use Imagine\Sites\Production\SyntheticSectionOrderRevisionPlan as OrderPlan;
use Imagine\Sites\Production\SyntheticEditorialPatchV1 as Patch;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\StaticSiteRenderer as Renderer;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;
use Imagine\Sites\Production\SyntheticReleaseBundle as Bundle;

$checks=0;
function f178Check(bool $ok,string $reason):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-178 '.$reason);
    ++$checks;
}
function f178Deny(callable $cb):bool {
    try {$cb();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$fixture=__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json';
$copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
$orders=[
 ['highlights','process','faq'],
 ['highlights','faq','process'],
 ['process','highlights','faq'],
 ['process','faq','highlights'],
 ['faq','highlights','process'],
 ['faq','process','highlights'],
];
$render=new Renderer();
foreach(['classic','modern','warm'] as $style) {
    foreach($orders as $number=>$order) {
        $args=[
            'onepage','Marca Fictícia Imagine',$style,$copy,
            'tenant-f178','site-f178',
            'workflow-f178-'.$style.'-'.$number,
            'release-f178-'.$style.'-'.$number,
        ];
        $previous=Assembly::build(...[...$args,$order,false]);
        $enabled=Assembly::build(...[...$args,$order,true]);
        $a=$previous->artifact;
        $b=$enabled->artifact;
        f178Check(!hash_equals($a->digest,$b->digest) &&
            hash_equals(hash('sha256',$b->html),$b->digest) &&
            $enabled->brief->sectionOrder===$order &&
            $enabled->brief->sectionNavigation===true,
            'opt-in Onepage anchor navigation has its own exact reviewed bytes');
        f178Check($previous->brief->sectionNavigation===false &&
            !str_contains($a->html,'<a href="#solucoes">') &&
            !str_contains($a->html,'<a href="#etapas">') &&
            !str_contains($a->html,'<a href="#perguntas">'),
            'default previews do not change or silently gain navigation');
        $positions=[];
        foreach($order as $block){
            $anchor=match($block) {
                'highlights'=>'<a href="#solucoes">Soluções</a>',
                'process'=>'<a href="#etapas">Como funciona</a>',
                'faq'=>'<a href="#perguntas">FAQ</a>',
            };
            $position=strpos($b->html,$anchor);
            f178Check($position!==false,'missing exact local anchor '.$block);
            $positions[]=$position;
        }
        f178Check($positions[0]<$positions[1] && $positions[1]<$positions[2],
            'navigation anchors reflect the selected display order');
        f178Check(Audit::isSafe($b->html) &&
            QA::inspect($b)->passedStructuralChecks &&
            Bundle::verifyFiles($enabled->bundle->files,$enabled->manifest) &&
            $enabled->reviewSummary()['publishable']===false &&
            $enabled->reviewSummary()['officialClientAcceptance']===false &&
            $enabled->reviewSummary()['workflowState']==='internal_review',
            'optional anchor nav is inert, content-addressed, not publishable');
        $approved=$enabled->workflow->advance(
            'imagine_reviewer','approve_internal',2,$b->digest
        );
        $newStyle=$style==='classic'?'warm':'classic';
        $styleRevision=Style::begin(
            $approved,$enabled->brief,$b,'release-f178-original',
            $newStyle,'release-f178-style','imagine_reviewer',3
        );
        f178Check($styleRevision->nextBrief->sectionNavigation===true &&
            $styleRevision->nextBrief->sectionOrder===$order &&
            $styleRevision->draftWorkflow->artifactDigest===null,
            'theme adjustment keeps anchors and requires new review');
        $different=array_reverse($order);
        $reorder=OrderPlan::begin(
            $approved,$enabled->brief,$b,'release-f178-original',
            $different,'release-f178-order','imagine_reviewer',3
        );
        f178Check($reorder->nextBrief->sectionNavigation===true &&
            $reorder->nextBrief->sectionOrder===$different &&
            $reorder->draftWorkflow->artifactDigest===null,
            'layout reorder preserves anchor opt-in and invalidates prior review');
        $patch=Patch::propose(
            $approved,$enabled->brief,$b,'release-f178-original',
            'release-f178-patch','imagine_reviewer',[
                'schemaVersion'=>'sites.editorial.patch.v1',
                'expectedDigest'=>$b->digest,
                'expectedRevision'=>3,
                'changes'=>['headline'=>'Nova manchete para revisão interna'],
            ]
        );
        f178Check($patch->nextBrief->sectionNavigation===true &&
            $patch->nextBrief->sectionOrder===$order &&
            $patch->draftWorkflow->artifactDigest===null,
            'plain-copy patch cannot silently drop or add navigation');
        $disabled=new SiteBrief(
            $enabled->brief->productCode,$enabled->brief->brand,
            $enabled->brief->headline,$enabled->brief->description,
            $enabled->brief->callToAction,$enabled->brief->benefits,
            $enabled->brief->stylePreset,$enabled->brief->faq,
            $enabled->brief->highlights,$enabled->brief->processSteps,$order,false
        );
        f178Check(f178Deny(static fn()=>Content::begin(
            $approved,$enabled->brief,$b,'release-f178-original',
            $disabled,'release-f178-illicit-copy','imagine_reviewer',3
        )), 'ordinary content edit cannot switch navigation policy');
    }
}
f178Check(f178Deny(static fn()=>new SiteBrief(
    'landing_page','Marca','Título','Descrição','Detalhes',
    ['Benefício'],'classic',[],[],[],['highlights','process','faq'],true
)), 'navigation opt-in cannot be enabled on a Landing Page');

$empty=new SiteBrief(
    'onepage','Marca','Título','Descrição','Detalhes',['Benefício']
);
$emptyWithOptIn=new SiteBrief(
    'onepage','Marca','Título','Descrição','Detalhes',['Benefício'],
    'classic',[],[],[],['highlights','process','faq'],true
);
f178Check($render->render($empty)->html===$render->render($emptyWithOptIn)->html,
    'without optional sections navigation adds zero invisible markup or CSS');

$onlyFaq=new SiteBrief(
    'onepage','Marca','Título','Descrição','Detalhes',['Benefício'],
    'classic',[['question'=>'Como funciona?','answer'=>'Página privada.']],[],[],
    ['process','faq','highlights'],true
);
$faqHtml=$render->render($onlyFaq)->html;
f178Check(str_contains($faqHtml,'<a href="#perguntas">FAQ</a>') &&
    !str_contains($faqHtml,'<a href="#solucoes">') &&
    !str_contains($faqHtml,'<a href="#etapas">') &&
    Audit::isSafe($faqHtml),
    'only real present sections get navigation links');

$tainted=$copy;
$tainted['sectionNavigation']=true;
f178Check(f178Deny(static fn()=>Assembly::build(
    'onepage','Marca Fictícia','classic',$tainted,
    'tenant-f178','site-f178','workflow-f178-forged','release-f178-forged'
)), 'untrusted AI copy cannot set navigation policy');

echo "$checks F1-178 safe opt-in section anchor navigation assertions passed.\n";
