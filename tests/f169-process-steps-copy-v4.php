<?php

declare(strict_types=1);

/**
 * F1-169: ordered "Como funciona" content is a genuine static site feature.
 * The candidate remains fictional, review-only and non-publishable.
 */
spl_autoload_register(static function(string $class):void {
    $root='Imagine\\Sites\\';
    if (!str_starts_with($class,$root)) return;
    $path=substr($class,strlen($root));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$path))
        throw new RuntimeException('Unsafe F1-169 fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StructuredCopyCandidate as Copy;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\StaticSiteRenderer as Renderer;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;
use Imagine\Sites\Production\SyntheticReleaseBundle as Bundle;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as Revision;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as Style;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;

$checks=0;
function check169(bool $condition,string $message):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-169: '.$message);
    ++$checks;
}
function denied169(callable $action):bool {
    try {$action();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}

$v1=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Uma apresentação completa para negócios',
    'description'=>'Conteúdo fictício para verificar modelos responsivos e fluxos de revisão sem integração a clientes reais.',
    'callToAction'=>'Explore os serviços',
    'benefits'=>['Simplicidade','Organização','Revisão controlada'],
];
$faq=[['question'=>'Como o serviço funciona?',
       'answer'=>'Esta é uma versão de demonstração sem solicitações reais.']];
$highlights=[['title'=>'Revisão técnica',
              'description'=>'Um fluxo de produção com verificações e aprovação separada.']];
$steps=[
    ['title'=>'Primeiro contato',
     'description'=>'A equipe Imagine reúne informações em um fluxo privado, fora desta página de demonstração.'],
    ['title'=>'Construção da página',
     'description'=>'Um rascunho é organizado com os benefícios, serviços e conteúdo necessário.'],
    ['title'=>'Revisão antes da entrega',
     'description'=>'O conteúdo é analisado e depende de aprovação humana e do Portal para prosseguir.'],
];
$v2=array_replace($v1,['schemaVersion'=>'sites.ai-copy.v2','faq'=>$faq]);
$v3=array_replace($v2,['schemaVersion'=>'sites.ai-copy.v3','highlights'=>$highlights]);
$v4=array_replace($v3,['schemaVersion'=>'sites.ai-copy.v4','processSteps'=>$steps]);
foreach([$v1,$v2,$v3] as $old) {
    check169(Copy::assemble('onepage','Imagine Demonstrativa',$old)->processSteps===[],
        'previous copy contracts remain functional without process steps');
}
check169(Copy::assemble('onepage','Imagine Demonstrativa',$v4)->processSteps===$steps,
    'v4 safely transports ordered steps');

foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        $args=[
            $product,'Imagine Demonstrativa',$preset,$v4,
            'tenant-f169','site-f169','workflow-f169-'.$product.'-'.$preset,
            'release-f169-'.$product.'-'.$preset,
        ];
        $draft=Assembly::build(...$args);
        $again=Assembly::build(...$args);
        $previous=Assembly::build(...array_replace($args,[3=>$v3]));
        check169($draft->brief->processSteps===$steps &&
            $draft->brief->productCode===$product &&
            $draft->brief->stylePreset===$preset &&
            $draft->brief->brand==='Imagine Demonstrativa',
            'process steps cannot override official product/brand/preset');
        check169($draft->artifact->digest===$again->artifact->digest &&
            $draft->artifact->digest!==$previous->artifact->digest &&
            hash('sha256',$draft->artifact->html)===$draft->artifact->digest,
            'optional ordered section creates deterministic unique bytes');
        $html=$draft->artifact->html;
        check169(str_contains($html,'<section class="process" id="etapas"') &&
            str_contains($html,'<h2 id="process-title">Como funciona</h2>') &&
            substr_count($html,'<ol>')===1 &&
            substr_count($html,'<li><h3>')===count($steps)+count($highlights)+count($faq),
            'process uses native numbered list with h3 headings');
        check169(str_contains($html,'Primeiro contato') &&
            str_contains($html,'Revisão antes da entrega') &&
            strpos($html,'Primeiro contato')<strpos($html,'Revisão antes da entrega'),
            'ordered process steps keep original editorial order');
        check169(Audit::isSafe($html) &&
            QA::inspect($draft->artifact)->passedStructuralChecks &&
            Bundle::verifyFiles($draft->bundle->files,$draft->manifest),
            'ordered process passes inert security, semantic QA and digest verification');
        check169($draft->workflow->state===Workflow::INTERNAL_REVIEW &&
            !$draft->reviewSummary()['publishable'] &&
            !$draft->reviewSummary()['officialClientAcceptance'],
            'new process content cannot publish or authorize client acceptance');

        $approved=$draft->workflow->advance(
            'imagine_reviewer','approve_internal',2,$draft->artifact->digest
        );
        $restyle=Style::begin(
            $approved,$draft->brief,$draft->artifact,'release-f169-old',
            $preset==='classic'?'modern':'classic','release-f169-next-style',
            'imagine_reviewer',3
        );
        check169($restyle->nextBrief->processSteps===$steps &&
            $restyle->draftWorkflow->artifactDigest===null,
            'style changes preserve ordered steps but void earlier approval');

        $replacement=$steps;
        $replacement[1]['description']='Outro conteúdo de revisão exige um novo digest e aceite.';
        $source=$draft->brief;
        $next=new SiteBrief(
            $source->productCode,$source->brand,$source->headline,
            $source->description,$source->callToAction,$source->benefits,
            $source->stylePreset,$source->faq,$source->highlights,$replacement
        );
        $revision=Revision::begin(
            $approved,$source,$draft->artifact,'release-f169-old',
            $next,'release-f169-next-copy','imagine_reviewer',3
        );
        check169($revision->changedFields===['processSteps'] &&
            $revision->nextArtifact->digest!==$draft->artifact->digest &&
            $revision->draftWorkflow->artifactDigest===null,
            'changing one process step generates new immutable review cycle');
    }
}
$base=[
    'onepage','Imagine Demonstrativa','Título exemplo',
    'Descrição com texto suficiente para a página estática.',
    'Conhecer detalhes',['Primeiro benefício'],'classic',[],[],
];
$invalid=[
    'non-list'=>['one'=>['title'=>'Etapa','description'=>'Explicação']],
    'extra-key'=>[['title'=>'Etapa','description'=>'Explicação','publish'=>true]],
    'missing-field'=>[['title'=>'Etapa']],
    'empty-title'=>[['title'=>' ','description'=>'Explicação']],
    'duplicate-titles'=>[['title'=>'Etapa','description'=>'A'],['title'=>'etapa','description'=>'B']],
    'too-many'=>array_fill(0,6,['title'=>'Igual','description'=>'Descrição']),
    'control'=>[['title'=>"Título\nperigoso",'description'=>'Explicação']],
    'url'=>[['title'=>'Etapa','description'=>'https://attacker.invalid/path']],
    'too-long'=>[['title'=>'Etapa','description'=>str_repeat('x',351)]],
];
foreach($invalid as $label=>$candidate) {
    check169(denied169(static fn()=>new SiteBrief(...[...$base,$candidate])),
        'invalid structured process rejected: '.$label);
}
$bad=[
    'v3 cannot silently introduce process'=>array_replace($v3,['processSteps'=>$steps]),
    'v4 must include process'=>array_diff_key($v4,['processSteps'=>true]),
    'v4 must include at least one step'=>array_replace($v4,['processSteps'=>[]]),
    'v4 must reject extra permissions'=>array_replace($v4,['publishable'=>true]),
    'v4 must reject script tags'=>array_replace($v4,[
        'processSteps'=>[['title'=>'<script>alert(1)</script>','description'=>'Exemplo']]
    ]),
];
foreach($bad as $label=>$candidate) {
    check169(denied169(static fn()=>Copy::assemble(
        'onepage','Imagine Demonstrativa',$candidate
    )),'versioned copy schema denied '.$label);
}
echo "$checks F1-169 process section / copy v4 / revisions assertions passed.\n";
