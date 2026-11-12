<?php

declare(strict_types=1);

/** F1-63: advisory-only synthetic content quality, never an SEO guarantee. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\AProduction\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('Invalid editorial test class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer as Renderer;
use Imagine\Sites\Production\SyntheticEditorialReviewReport as Audit;
use Imagine\Sites\Production\ReleaseArtifact;

$n=0;
function f63Check(bool $cond,string $name):void {
    global $n;
    if(!$cond)throw new RuntimeException('FAIL '.$name);
    ++$n;echo "PASS $name\n";
}
$renderer=new Renderer();
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $brief=new SiteBrief(
            $product,
            'Imagine Fictícia',
            'Soluções digitais para melhorar sua presença online',
            'Esta demonstração explica os serviços e benefícios para pequenas empresas, sem captação de contatos.',
            'Conhecer as soluções',
            ['Organização da informação','Comunicação mais clara','Design adaptável'],
            $style
        );
        $artifact=$renderer->render($brief);
        $report=Audit::inspect($brief,$artifact);
        f63Check(count($report->checks)===6 &&
            !in_array(false,$report->checks,true) &&
            $report->suggestions===[],
            "$product $style: suitable synthetic copy has no advisory findings");
        f63Check($artifact->html===$renderer->render($brief)->html &&
            $artifact->digest===hash('sha256',$artifact->html),
            "$product $style: editorial analysis does not change release bytes");
        f63Check(!property_exists($report,'publishable') &&
            !property_exists($report,'officialClientAcceptance') &&
            !property_exists($report,'approved'),
            "$product $style: report never grants publication or client acceptance");
    }
}
$weak=new SiteBrief(
    'onepage','A','Olá','Curto','Ir',
    ['Entrega rápida',' entrega  RÁPIDA ','Só um benefício diferente'],'warm'
);
$result=Audit::inspect($weak,$renderer->render($weak));
f63Check($result->checks['exact_inert_html'] &&
    !$result->checks['descriptive_title'] &&
    !$result->checks['description_readability'] &&
    !$result->checks['cta_clarity'] &&
    !$result->checks['benefits_distinct'],
    'short and duplicated fictional copy produces individual editorial warnings');
f63Check(count($result->suggestions)>=4 &&
    !in_array('publish',array_keys($result->checks),true),
    'warnings are advice, never a publishability switch');
$one=new SiteBrief('landing_page','A','Olá','Curto','Ir',['Um benefício'],'classic');
$result=Audit::inspect($one,$renderer->render($one));
f63Check(!$result->checks['benefit_coverage'] &&
    $result->checks['benefits_distinct'],
    'single benefit is permitted by renderer but flagged for human review');
$original=$renderer->render($one);
$changed=new ReleaseArtifact(str_replace('Próximo passo','Outro passo',$original->html));
$invalid=Audit::inspect($one,$changed);
f63Check(!$invalid->checks['exact_inert_html'] &&
    count($invalid->suggestions)>0,
    'a regenerated digest cannot hide divergent HTML from editorial review');
$escaped=new SiteBrief('onepage','Empresa & Filhos',
    'Uma solução segura para organizar o seu negócio',
    'Este texto explica benefícios comerciais com símbolos < e & em conteúdo inteiramente fictício.',
    'Veja os benefícios',['Segurança & clareza','Orientações < objetivas'],'modern');
$escapedReport=Audit::inspect($escaped,$renderer->render($escaped));
f63Check($escapedReport->checks['exact_inert_html'],
    'safe escaping is compatible with the editorial analysis');
echo "\n$n F1-63 editorial advice checks passed. No search ranking, acceptance or delivery.\n";
