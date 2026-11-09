<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\Production\\';
    if (!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe test class name');
    }
    require __DIR__.'/../src/Production/'.$name.'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;

$checks=0;
function f39Assert(bool $ok,string $label):void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    ++$checks;
    echo 'PASS '.$label."\n";
}
$renderer=new StaticSiteRenderer();
foreach (['onepage','landing_page'] as $product) {
    $brief=new SiteBrief($product,'Marca Fictícia Imagine',
        'Uma solução fictícia para demonstração',
        'Conteúdo de laboratório sem informações pessoais.',
        'Saiba mais',['Benefício A','Benefício B']);
    $artifact=$renderer->render($brief);
    $report=QA::inspect($artifact);
    f39Assert($report->passedStructuralChecks,'valid '.$product.' template passes structural checks');
    f39Assert(count($report->checks)===7,'exact expected check inventory: '.$product);
    f39Assert(!in_array(false,$report->checks,true),'all individual checks pass: '.$product);
}
$base=$renderer->render(new SiteBrief('onepage','Marca Fictícia Imagine',
    'Uma solução fictícia para demonstração',
    'Conteúdo de laboratório sem informações pessoais.',
    'Saiba mais',['Benefício A','Benefício B']))->html;
$mutations=[
    'missing language'=>str_replace('lang="pt-BR"','lang="en"',$base),
    'missing main landmark'=>str_replace('<main id="principal">','<div id="principal">',$base),
    'duplicate heading'=>str_replace('</main>','<h1>Extra</h1></main>',$base),
    'broken internal anchor'=>str_replace('href="#contato"','href="#missing"',$base),
    'external navigation'=>str_replace('href="#contato"','href="https://invalid.example"',$base),
    'missing viewport'=>str_replace('name="viewport"','name="wrong-viewport"',$base),
    'robots indexable'=>str_replace('noindex, nofollow','index, follow',$base),
    'active script'=>str_replace('</body>','<script>alert(1)</script></body>',$base),
    'duplicate id'=>str_replace('</main>','<p id="contato">Duplicate</p></main>',$base),
];
foreach ($mutations as $label=>$html) {
    f39Assert($html!==$base,'mutation constructed '.$label);
    $report=QA::inspect(new ReleaseArtifact($html));
    f39Assert(!$report->passedStructuralChecks,'structural QA fails closed '.$label);
}
f39Assert(QA::inspect(new ReleaseArtifact('garbled'))->passedStructuralChecks===false,
    'non-document HTML cannot satisfy readiness checks');
echo "\n$checks F1-39 offline structural checks passed; this is NOT WCAG certification or approval.\n";
