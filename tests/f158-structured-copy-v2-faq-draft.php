<?php

declare(strict_types=1);

/** F1-158: synthetic AI-like v2 copy -> FAQ -> inert private review draft. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $path=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$path)) {
        throw new RuntimeException('Invalid F1-158 fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
});

use Imagine\Sites\Production\StructuredCopyCandidate as Copy;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticReleaseBundle;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;

$checks=0;
function check158(bool $ok,string $message):void {
    global $checks;
    if (!$ok)throw new RuntimeException('F1-158 '.$message);
    ++$checks;
}
function denied158(callable $callback):bool {
    try {$callback();}catch(InvalidArgumentException|DomainException){return true;}
    return false;
}

$v1=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Tecnologia simples para equipes de negócios',
    'description'=>'Apresentação inteiramente fictícia, com benefícios objetivos e prévia sem qualquer contato real.',
    'callToAction'=>'Conheça as soluções',
    'benefits'=>['Organização eficiente','Informação acessível','Experiência intuitiva'],
];
$faq=[
    ['question'=>'Como é preparada a prévia?',
     'answer'=>'Um modelo estático é produzido para revisão humana, sem envio de dados.'],
    ['question'=>'É possível revisar os textos?',
     'answer'=>'Sim, toda alteração cria outra versão interna que exige uma nova aprovação.'],
];
$v2=array_replace($v1,['schemaVersion'=>'sites.ai-copy.v2']);
$v2['faq']=$faq;
check158(Copy::assemble('onepage','Marca Sintética',$v1)->faq===[],
    'v1 copy unchanged with empty FAQ and no surprise fields');
check158(Copy::assemble('onepage','Marca Sintética',$v2)->faq===$faq,
    'explicit v2 transfers only safe structured FAQ text');
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        $build=static fn(array $copy):Assembly=>Assembly::build(
            $product,'Marca Sintética',$preset,$copy,
            'tenant-f158-demo','site-f158-demo','workflow-f158-demo','release-f158-demo'
        );
        $a=$build($v2);
        $b=$build($v2);
        $before=$build($v1);
        check158($a->brief->brand==='Marca Sintética'
            && $a->brief->productCode===$product
            && $a->brief->stylePreset===$preset
            && $a->brief->faq===$faq,
            'v2 FAQ cannot select tenant, product, official brand or preset');
        check158($a->artifact->digest===$b->artifact->digest
            && $a->artifact->digest!==$before->artifact->digest
            && $a->artifact->html===$b->artifact->html,
            'private draft is deterministic but distinct from unchanged v1');
        check158(str_contains($a->artifact->html,'<section class="faq"')
            && str_contains($a->artifact->html,'Como é preparada a prévia?')
            && str_contains($a->artifact->html,'</h3>')
            && InertPreviewAudit::isSafe($a->artifact->html),
            'FAQ exists as escaped static markup, no active elements');
        check158($a->structuralReport->passedStructuralChecks
            && SyntheticReleaseBundle::verifyFiles($a->bundle->files,$a->manifest),
            'v2 draft passes structural and manifest integrity checks');
        check158($a->workflow->state===Workflow::INTERNAL_REVIEW
            && $a->reviewSummary()['internalReviewRequired']===true
            && $a->reviewSummary()['publishable']===false
            && $a->reviewSummary()['officialClientAcceptance']===false,
            'v2 drafting still requires manual review and Portal authority');
        check158(denied158(static fn()=>$a->workflow->advance(
                'agent','approve_internal',2,$a->artifact->digest))
            && denied158(static fn()=>$a->workflow->portalReviewCandidate(
                'release-f158-demo',$a->artifact->digest,2)),
            'AI-like content never grants review or publication rights');
    }
}
$bad=[
    'v1 must reject v2-only FAQ'=>$v1+['faq'=>$faq],
    'v2 must require FAQ'=>array_diff_key($v2,['faq'=>true]),
    'v2 may not forge authority'=>$v2+['publishable'=>true],
    'v2 FAQ must be a list'=>array_replace($v2,['faq'=>['q'=>'not-list']]),
    'v2 FAQ must have exact fields'=>array_replace($v2,[
        'faq'=>[['question'=>'Pergunta?','answer'=>'Resposta','approved'=>true]]
    ]),
    'v2 FAQ must have no raw HTML'=>array_replace($v2,[
        'faq'=>[['question'=>'<script>alert(1)</script>','answer'=>'Resposta']]
    ]),
    'v2 FAQ must have no URL'=>array_replace($v2,[
        'faq'=>[['question'=>'Pergunta?','answer'=>'https://unsafe.invalid/']]
    ]),
    'v2 FAQ cannot repeat question'=>array_replace($v2,[
        'faq'=>[['question'=>'Mesma?','answer'=>'R1'],
                ['question'=>'mesma?','answer'=>'R2']]
    ]),
    'v2 FAQ limited to six'=>array_replace($v2,[
        'faq'=>array_fill(0,7,['question'=>'Repetida?','answer'=>'Resposta'])
    ]),
    'v2 FAQ denies control characters'=>array_replace($v2,[
        'faq'=>[['question'=>"Pergunta\noutra?",'answer'=>'Resposta']]
    ]),
];
foreach($bad as $label=>$input) {
    check158(denied158(static fn()=>Copy::assemble('onepage','Marca Sintética',$input)),
        $label);
}
check158(denied158(static fn()=>Copy::assemble(
    'onepage','Marca Sintética',$v2,512)),
    'byte budget remains enforced for longer v2 content');
echo "$checks F1-158 structured copy v2 and FAQ draft assertions passed. ".
    "No real AI, HTTP, lead data, approvals, CRM or publishing.\n";
