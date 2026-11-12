<?php

declare(strict_types=1);

/** F1-171: Imagine-side editorial patch is not a client self-service API. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $rel=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$rel)) {
        throw new RuntimeException('Unexpected fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$rel).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer as Renderer;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\SyntheticEditorialPatchV1 as Patch;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as Qa;

$checks=0;
function check171(bool $condition,string $label):void {
    global $checks;
    if (!$condition)throw new RuntimeException('F1-171: '.$label);
    ++$checks;
}
function denied171(callable $callback):bool {
    try{$callback();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$faq=[
 ['question'=>'Como funciona a revisão?','answer'=>'Uma pessoa responsável confere cada detalhe da prévia.'],
];
$highlights=[
 ['title'=>'Planejamento editorial','description'=>'Apresentação organizada para demonstrar um serviço fictício.'],
];
$steps=[
 ['title'=>'Coleta de informações fictícias','description'=>'Somente dados inventados são usados no teste.'],
 ['title'=>'Montagem do rascunho','description'=>'A página é produzida para avaliação interna.'],
];
$renderer=new Renderer();
foreach(['onepage','landing_page'] as $product) {
  foreach(['classic','modern','warm'] as $preset) {
    $brief=new SiteBrief(
      $product,'Marca Fictícia',
      'Uma apresentação digital para serviços',
      'Prévia exclusivamente fictícia com informações de exemplo e um processo controlado de revisão.',
      'Descubra os detalhes',['Informação clara','Processo objetivo'],
      $preset,$faq,$highlights,$steps
    );
    $artifact=$renderer->render($brief);
    $workflow=Workflow::create(
      'tenant-f171','site-f171','workflow-f171-'.$product.'-'.$preset
    )->advance('agent','start_draft',0)
     ->advance('agent','submit_draft',1,$artifact->digest)
     ->advance('imagine_reviewer','approve_internal',2,$artifact->digest);
    $candidate=[
      'schemaVersion'=>'sites.editorial.patch.v1',
      'expectedDigest'=>$artifact->digest,
      'expectedRevision'=>$workflow->revision,
      'changes'=>[
        'faq'=>[['question'=>'Como funciona a revisão?',
          'answer'=>'O rascunho revisado precisa de um novo aceite interno.']],
        'processSteps'=>[
           $steps[0],
           ['title'=>'Montagem e validação do rascunho',
            'description'=>'Novo texto estritamente de demonstração e revisão.'],
        ],
      ],
    ];
    $propose=static fn(array $input,string $role='imagine_reviewer',
      string $nextRelease='release-f171-next')=>
        Patch::propose($workflow,$brief,$artifact,
          'release-f171-approved',$nextRelease,$role,$input);
    $plan=$propose($candidate);
    check171($plan->changedFields===['faq','processSteps'] &&
      $plan->nextBrief->brand===$brief->brand &&
      $plan->nextBrief->productCode===$brief->productCode &&
      $plan->nextBrief->stylePreset===$brief->stylePreset,
      'only requested content changes, official identity unaffected');
    check171($plan->nextArtifact->digest!==$artifact->digest &&
      hash('sha256',$plan->nextArtifact->html)===$plan->nextArtifact->digest &&
      InertPreviewAudit::isSafe($plan->nextArtifact->html) &&
      Qa::inspect($plan->nextArtifact)->passedStructuralChecks,
      'patch renders a new safe HTML artifact with independent digest');
    check171($plan->draftWorkflow->state===Workflow::DRAFTING &&
      $plan->draftWorkflow->artifactDigest===null &&
      $plan->draftWorkflow->revision===$workflow->revision+1 &&
      $workflow->artifactDigest===$artifact->digest,
      'patch revokes approval only on NEW immutable workflow, source unchanged');
    check171($plan->nextBrief->processSteps[1]['title']
       ==='Montagem e validação do rascunho' &&
       $plan->nextBrief->highlights===$highlights,
       'patch preserves unrelated service highlights and process order');

    $oneField=$candidate;
    $oneField['changes']=['headline'=>'Novo título permitido ao editor interno'];
    $titlePlan=$propose($oneField,'imagine_reviewer','release-f171-headline');
    check171($titlePlan->changedFields===['headline'] &&
      $titlePlan->nextBrief->headline==='Novo título permitido ao editor interno',
      'operator can patch one content field without regenerating all blocks');

    foreach([
      'client self-service action'=>[
        'changes'=>['approve'=>true],
      ],
      'attempt to change official brand'=>[
        'changes'=>['brand'=>'Empresa Forjada'],
      ],
      'attempt to switch product'=>[
        'changes'=>['productCode'=>'landing_page'],
      ],
      'attempt to add external link'=>[
        'changes'=>['faq'=>[['question'=>'Onde?',
          'answer'=>'https://attacker.invalid/test']]],
      ],
      'attempt to add script'=>[
        'changes'=>['processSteps'=>[['title'=>'Etapa',
          'description'=>'<script>alert(1)</script>']]],
      ],
      'type confusion'=>[
        'changes'=>['headline'=>['unexpected'=>'nested']],
      ],
      'unknown revision schema'=>['schemaVersion'=>'sites.editorial.patch.v2'],
      'extra caller authority'=>['publishable'=>true],
      'missing expected digest'=>['expectedDigest'=>null],
      'uppercased digest'=>['expectedDigest'=>strtoupper($artifact->digest)],
      'stale revision'=>['expectedRevision'=>$workflow->revision+1],
      'wrong digest'=>['expectedDigest'=>str_repeat('0',64)],
      'empty changes'=>['changes'=>[]],
      'no-op copy'=>['changes'=>['headline'=>$brief->headline]],
      'malformed faq'=>['changes'=>['faq'=>[['question'=>'Missing answer']]]],
    ] as $label=>$replacement) {
      $invalid=array_replace($candidate,$replacement);
      check171(denied171(static fn()=>$propose($invalid)),
        'editorial patch fails closed: '.$label);
    }
    check171(denied171(static fn()=>$propose($candidate,'agent')),
      'AI-like role cannot approve a source revision');
    check171(denied171(static fn()=>$propose(
      $candidate,'imagine_reviewer','release-f171-approved')),
      'new edited content cannot reuse original release identifier');
  }
}
echo "$checks F1-171 constrained editorial patch checks passed. ".
  "No client editor, IAM, HTTP, PII, CRM or publication.\n";
