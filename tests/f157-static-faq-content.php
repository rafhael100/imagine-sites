<?php

declare(strict_types=1);

/**
 * F1-157 structured FAQ copy is an inert static HTML component, never a form,
 * external link, AI approval, publish action or actual customer information.
 */
spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $relative=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('Unsafe fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;
use Imagine\Sites\Production\SyntheticReleaseBundle;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan;
use Imagine\Sites\Production\SyntheticContentRevisionPlan;
use Imagine\Sites\Domain\ReleaseManifest;

$checks=0;
function check157(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-157 '.$why);
    ++$checks;
}
function denied157(callable $cb):bool {
    try{$cb();}catch(InvalidArgumentException|DomainException){return true;}
    return false;
}
$renderer=new StaticSiteRenderer();
$faq=[
    ['question'=>'Quanto tempo dura a prévia?',
     'answer'=>'Esta é apenas uma demonstração interna, sem recebimento de informações.'],
    ['question'=>'Posso conhecer <novidades> e mais opções?',
     'answer'=>'O conteúdo fictício é revisado antes de qualquer possibilidade de publicação.'],
];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $newBrief=static fn(array $questions):SiteBrief=>new SiteBrief(
            $product,'Marca de demonstração','Soluções para pequenos negócios',
            'Prévia estática de laboratório sem formulário, usuário ou dado pessoal.',
            'Conheça vantagens',['Informações claras','Experiência objetiva'],
            $style,$questions
        );
        $brief=$newBrief($faq);
        $artifact=$renderer->render($brief);
        $plain=$renderer->render($newBrief([]));
        $default=new SiteBrief(
            $product,'Marca de demonstração','Soluções para pequenos negócios',
            'Prévia estática de laboratório sem formulário, usuário ou dado pessoal.',
            'Conheça vantagens',['Informações claras','Experiência objetiva'],
            $style
        );
        check157($plain->html===$renderer->render($default)->html,
            'old no-FAQ output remains byte-identical');
        check157($artifact->digest!==$plain->digest
            && $artifact->html===$renderer->render($brief)->html
            && hash_equals($artifact->digest,hash('sha256',$artifact->html)),
            'FAQ creates deterministic new release bytes');
        check157(InertPreviewAudit::isSafe($artifact->html)
            && SyntheticPreviewQaReport::inspect($artifact)->passedStructuralChecks,
            'FAQ still passes inert HTML and accessibility structure');
        check157(substr_count($artifact->html,'<h3>')===2
            && substr_count($artifact->html,'<section class="faq"')===1
            && str_contains($artifact->html,'&lt;novidades&gt;')
            && !str_contains($artifact->html,'<novidades>'),
            'questions are genuine headings and raw HTML remains escaped');
        check157(!str_contains($artifact->html,'<form')
            && !str_contains($artifact->html,'<script')
            && str_contains($artifact->html,'noindex, nofollow')
            && str_contains($artifact->html,"form-action 'none'"),
            'FAQ never activates forms, scripts or indexing');
        $manifest=new ReleaseManifest(
            'tenant-f157-fixture','site-f157-fixture',
            'release-f157-'.$product.'-'.$style,$artifact->digest
        );
        $bundle=new SyntheticReleaseBundle($manifest,$artifact);
        check157(SyntheticReleaseBundle::verifyFiles($bundle->files,$manifest)
            && json_decode($bundle->files['release-manifest.json'],true,16,JSON_THROW_ON_ERROR)
                ['publishable']===false,
            'FAQ bundle remains private, digest-pinned and nonpublishable');

        $approved=Workflow::create('tenant-f157-fixture','site-f157-fixture',
            'workflow-f157-'.$product.'-'.$style,5)
            ->advance('agent','start_draft',0)
            ->advance('agent','submit_draft',1,$artifact->digest)
            ->advance('imagine_reviewer','approve_internal',2,$artifact->digest);
        $styleChange=SyntheticStyleRevisionPlan::begin(
            $approved,$brief,$artifact,'release-f157-source',
            $style==='classic'?'warm':'classic','release-f157-style',
            'imagine_reviewer',3
        );
        check157($styleChange->nextBrief->faq===$faq
            && str_contains($styleChange->nextArtifact->html,'<section class="faq"')
            && $styleChange->draftWorkflow->artifactDigest===null,
            'style revision preserves FAQ yet voids prior approval');

        $changedFaq=[
            $faq[0],
            ['question'=>$faq[1]['question'],
             'answer'=>'Texto atualizado que precisa de uma nova revisão humana.'],
        ];
        $copyChange=SyntheticContentRevisionPlan::begin(
            $approved,$brief,$artifact,'release-f157-source',
            $newBrief($changedFaq),'release-f157-copy',
            'imagine_reviewer',3
        );
        check157($copyChange->changedFields===['faq']
            && $copyChange->draftWorkflow->artifactDigest===null
            && $copyChange->nextArtifact->digest!==$artifact->digest,
            'FAQ copy changes invalidate reviewed artifact and request new review');
    }
}
$baseArgs=[
 'onepage','Marca','Título de teste','Descrição de teste','Ver detalhes',
 ['Benefício'],'classic'
];
$invalid=[
 [['question'=>'', 'answer'=>'Resposta']],
 [['question'=>'Pergunta?', 'answer'=>'']],
 [['question'=>'Repetida?', 'answer'=>'a'],['question'=>'repetida?', 'answer'=>'b']],
 [['answer'=>'Falta pergunta','question'=>'Pergunta?']],
 [['question'=>'Com URL?', 'answer'=>'https://remote.invalid/path']],
 [['question'=>str_repeat('x',181), 'answer'=>'Resposta']],
 [['question'=>'Texto?', 'answer'=>str_repeat('y',901)]],
 [['question'=>"Linha\nperigosa", 'answer'=>'Resposta']],
 [['question'=>'Pergunta?', 'answer'=>'Resposta','publishable'=>true]],
 array_fill(0,7,['question'=>'Pergunta?', 'answer'=>'Resposta']),
];
foreach($invalid as $faqCandidate) {
    check157(denied157(static fn()=>new SiteBrief(...[...$baseArgs,$faqCandidate])),
        'unsafe FAQ candidate rejected before rendering');
}
check157(denied157(static fn()=>new SiteBrief(...[...$baseArgs,
    [['question'=>'Pergunta?','answer'=>'javascript:alert(1)']]])),
    'script URL rejected as content even without HTML tags');

echo "$checks F1-157 optional FAQ static-renderer checks passed. ".
    "No customer, publishable release, form, external link or CRM delivery.\n";
