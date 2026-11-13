<?php

declare(strict_types=1);

/** F1-249: synthetic style/semantic regression for 44px local navigation. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\Production\\';
    if (!str_starts_with($class,$prefix))return;
    $short=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
        throw new RuntimeException('F1-249 unexpected class');
    }
    require __DIR__.'/../src/Production/'.$short.'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;

$fixture=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
$checks=0;
function assert249(bool $valid,string $why):void {
    global $checks;
    if (!$valid)throw new RuntimeException('F1-249 '.$why);
    ++$checks;
}
$hashes=[];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        $brief=new SiteBrief(
            $product,'Aurora Exemplo',$fixture['headline'],$fixture['description'],
            $fixture['callToAction'],$fixture['benefits'],$preset,
            $fixture['faq'],$fixture['highlights'],$fixture['processSteps'],
            ['highlights','process','faq'],$product==='onepage',
            null,true
        );
        $r=new StaticSiteRenderer();
        $html=$r->render($brief)->html;
        assert249(InertPreviewAudit::isSafe($html) &&
            SyntheticPreviewQaReport::inspect($r->render($brief))->passedStructuralChecks,
            'new CSS keeps private static HTML inert and accessible structurally');
        assert249(str_contains($html,
            'nav a { text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; min-height: 44px; padding-block: 8px; }'),
            'all local navigation anchors have explicit 44px minimum');
        assert249(str_contains($html,
            '.btn { display: inline-flex; align-items: center; justify-content: center; min-height: 44px;'),
            'local CTA retains semantic link with explicit 44px target');
        assert249(substr_count($html,'href="#contato"')===1 &&
            substr_count($html,'id="contato"')===1 &&
            !str_contains($html,'<form') &&
            !str_contains($html,'<script'),
            'CTA remains a local anchor without customer lead capture');
        if ($product==='onepage') {
            foreach(['beneficios','sobre','solucoes','etapas','perguntas'] as $id) {
                assert249(str_contains($html,'href="#'.$id.'"'),
                    'onepage touch target navigates to '.$id);
            }
        } else {
            assert249(!str_contains($html,'<nav ') &&
                !str_contains($html,'href="#perguntas"'),
                'landing page stays simple without injected navigation');
        }
        $hashes[$product.'-'.$preset]=hash('sha256',$html);
    }
}
assert249(count($hashes)===6 && count(array_unique($hashes))===6,
    'six distinct deterministic product/preset review bytes');
echo "$checks F1-249 nav 44px target, semantics and inert SHA checks passed.\n";
