<?php
declare(strict_types=1);

/** F1-159: services highlight content from v3 copy -> inert HTML -> review. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unsafe F1-159 fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;
use Imagine\Sites\Production\StructuredCopyCandidate as Copy;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan;
use Imagine\Sites\Production\SyntheticContentRevisionPlan;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\SyntheticReleaseBundle;

$checks=0;
function check159(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-159: '.$why);
    ++$checks;
}
function denied159(callable $call):bool {
    try{$call();}catch(InvalidArgumentException|DomainException){return true;}
    return false;
}
$highlights=[
 ['title'=>'Atendimento claro',
  'description'=>'Informações organizadas para acompanhar as etapas e as possibilidades de serviço.'],
 ['title'=>'Páginas objetivas',
  'description'=>'Estruturas responsivas com apresentação simples, acessível e revisão interna.'],
];
$faq=[
 ['question'=>'Como posso conhecer as opções?',
  'answer'=>'Esta é uma prévia fictícia sem captação de dados, publicação ou contato externo.']
];
$v1=[
 'schemaVersion'=>'sites.ai-copy.v1',
 'headline'=>'Conheça nossas soluções de demonstração',
 'description'=>'Experiência inteiramente fictícia para revisar layouts e textos de uma página responsiva.',
 'callToAction'=>'Explore as vantagens',
 'benefits'=>['Clareza','Apresentação direta','Organização'],
];
$v2=array_replace($v1,['schemaVersion'=>'sites.ai-copy.v2','faq'=>$faq]);
$v3=array_replace($v2,['schemaVersion'=>'sites.ai-copy.v3','highlights'=>$highlights]);

check159(Copy::assemble('onepage','Marca Fictícia',$v1)->highlights===[],
 'v1 stays unchanged, no highlight section');
check159(Copy::assemble('onepage','Marca Fictícia',$v2)->highlights===[],
 'v2 FAQ stays unchanged, no highlights required');
check159(Copy::assemble('onepage','Marca Fictícia',$v3)->highlights===$highlights,
 'v3 opt-in carries structured safe service highlights');

foreach(['onepage','landing_page'] as $product) {
 foreach(['classic','modern','warm'] as $style) {
    $build=static fn(array $input):Assembly=>Assembly::build(
        $product,'Marca Fictícia',$style,$input,
        'tenant-f159','site-f159','workflow-f159','release-f159'
    );
    $draft=$build($v3);
    $repeat=$build($v3);
    $prior=$build($v2);
    check159($draft->brief->brand==='Marca Fictícia'
       && $draft->brief->productCode===$product
       && $draft->brief->stylePreset===$style
       && $draft->brief->highlights===$highlights
       && $draft->brief->faq===$faq,
       'trusted brand/product/style preserved around both optional sections');
    check159($draft->artifact->digest===$repeat->artifact->digest
       && $draft->artifact->digest!==$prior->artifact->digest
       && hash('sha256',$draft->artifact->html)===$draft->artifact->digest,
       'highlights change release digest without changing v2 output');
    check159(str_contains($draft->artifact->html,'Soluções em destaque')
       && str_contains($draft->artifact->html,'<section class="highlights"')
       && str_contains($draft->artifact->html,'<h3>Atendimento claro</h3>')
       && substr_count($draft->artifact->html,'<section class="faq"')===1,
       'two structured visual sections rendered in same static page');
    check159(InertPreviewAudit::isSafe($draft->artifact->html)
       && QA::inspect($draft->artifact)->passedStructuralChecks
       && SyntheticReleaseBundle::verifyFiles($draft->bundle->files,$draft->manifest),
       'new sections pass inert HTML, semantic QA and manifest checks');
    check159($draft->workflow->state===Workflow::INTERNAL_REVIEW
       && $draft->reviewSummary()['publishable']===false
       && $draft->reviewSummary()['officialClientAcceptance']===false
       && denied159(static fn()=>$draft->workflow->advance(
          'agent','approve_internal',2,$draft->artifact->digest)),
       'new section cannot bypass reviewer or Portal approval');

    $approved=$draft->workflow->advance(
        'imagine_reviewer','approve_internal',2,$draft->artifact->digest
    );
    $restyle=SyntheticStyleRevisionPlan::begin(
        $approved,$draft->brief,$draft->artifact,'release-f159-original',
        $style==='classic'?'modern':'classic','release-f159-new-style',
        'imagine_reviewer',3
    );
    check159($restyle->nextBrief->highlights===$highlights
       && $restyle->nextBrief->faq===$faq
       && $restyle->draftWorkflow->artifactDigest===null,
       're-style preserves all copy while revoking previous review digest');
    $newHighlights=[
      ['title'=>'Outra solução',
       'description'=>'Conteúdo revisado para apresentação em um novo ciclo de aprovação.']
    ];
    $updated=new SiteBrief(
      $product,$draft->brief->brand,$draft->brief->headline,
      $draft->brief->description,$draft->brief->callToAction,
      $draft->brief->benefits,$style,$draft->brief->faq,$newHighlights
    );
    $revision=SyntheticContentRevisionPlan::begin(
       $approved,$draft->brief,$draft->artifact,'release-f159-original',
       $updated,'release-f159-new-copy','imagine_reviewer',3
    );
    check159($revision->changedFields===['highlights']
       && $revision->draftWorkflow->artifactDigest===null
       && $revision->nextArtifact->digest!==$draft->artifact->digest,
       'changing one highlight requires a genuinely new draft/review cycle');
 }
}
$bad=[
 'v2 does not accept unversioned highlights'=>$v2+['highlights'=>$highlights],
 'v3 requires highlights'=>array_diff_key($v3,['highlights'=>true]),
 'v3 rejects extra rights'=>$v3+['officialClientAcceptance'=>true],
 'v3 requires a list'=>array_replace($v3,['highlights'=>['x'=>'bad']]),
 'v3 requires at least one'=>array_replace($v3,['highlights'=>[]]),
 'v3 max four'=>array_replace($v3,['highlights'=>array_fill(0,5,['title'=>'Same','description'=>'x'])]),
 'v3 exact object keys'=>array_replace($v3,['highlights'=>[
    ['title'=>'Solução','description'=>'Exemplo','publish'=>true]
 ]]),
 'v3 rejects raw HTML'=>array_replace($v3,['highlights'=>[
    ['title'=>'<script>unsafe</script>','description'=>'Exemplo']
 ]]),
 'v3 rejects external links'=>array_replace($v3,['highlights'=>[
    ['title'=>'Solução','description'=>'https://external.invalid']
 ]]),
 'v3 rejects duplicate titles'=>array_replace($v3,['highlights'=>[
    ['title'=>'Igual','description'=>'Um'],
    ['title'=>'igual','description'=>'Dois']
 ]]),
 'v3 rejects control characters'=>array_replace($v3,['highlights'=>[
    ['title'=>"Linha\nquebra",'description'=>'Exemplo']
 ]]),
];
foreach($bad as $label=>$candidate) {
   check159(denied159(static fn()=>Copy::assemble('onepage','Marca Fictícia',$candidate)),
      $label);
}
echo "$checks F1-159 static highlight and versioned-copy assertions passed. ".
    "No live AI, client data, form, delivery or public deployment.\n";
