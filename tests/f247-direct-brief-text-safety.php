<?php

declare(strict_types=1);

/**
 * F1-247: adversarial direct SiteBrief ingress (not only AI-copy adapter).
 * All examples are fictional and never publish or read a customer.
 */
spl_autoload_register(static function(string $class): void {
    $prefix='Imagine\\Sites\\Production\\';
    if (!str_starts_with($class,$prefix)) return;
    $path=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$path)) {
        throw new RuntimeException('Unsafe F1-247 fixture class');
    }
    require __DIR__.'/../src/Production/'.$path.'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticPreviewQaReport;

$checks=0;
function good247(bool $yes,string $message): void {
    global $checks;
    if (!$yes) throw new RuntimeException('F1-247: '.$message);
    ++$checks;
}
function rejects247(array $values):bool {
    try {new SiteBrief(...$values);}
    catch (InvalidArgumentException) {return true;}
    return false;
}
$base=[
    'productCode'=>'onepage',
    'brand'=>'Aurora & Exemplo',
    'headline'=>'Tecnologia <simples> para empresas',
    'description'=>'Páginas fictícias e informativas, com prévia estática e revisão humana.',
    'callToAction'=>'Conheça as vantagens',
    'benefits'=>['Clareza & qualidade','Navegação <acessível>'],
    'stylePreset'=>'classic',
    'faq'=>[['question'=>'Como funciona?', 'answer'=>'A prévia é <interna> e não publica dados.']],
    'highlights'=>[['title'=>'Serviço & suporte','description'=>'Demonstração <simples>.']],
    'processSteps'=>[['title'=>'Planejamento','description'=>'Briefing & revisão.']],
    'sectionOrder'=>['highlights','process','faq'],
    'sectionNavigation'=>true,
    'socialPreviewMetadata'=>true,
    'seoTitle'=>'Aurora & Exemplo — Soluções',
    'seoDescription'=>'Prévia estática & revisão humana, sem publicação de clientes.',
];
foreach (['onepage','landing_page'] as $product) {
    $values=$base;
    $values['productCode']=$product;
    $values['sectionNavigation']=$product==='onepage';
    $brief=new SiteBrief(...$values);
    $artifact=(new StaticSiteRenderer())->render($brief);
    good247(str_contains($artifact->html,'&lt;simples&gt;') &&
        str_contains($artifact->html,'&amp; Exemplo') &&
        str_contains($artifact->html,'&lt;acessível&gt;') &&
        str_contains($artifact->html,'&lt;interna&gt;'),
        'ordinary readable special characters stay safe and escaped');
    good247(InertPreviewAudit::isSafe($artifact->html) &&
        SyntheticPreviewQaReport::inspect($artifact)->passedStructuralChecks,
        'both products retain inert, auditable semantic HTML');
    good247($artifact->digest===(new StaticSiteRenderer())->render($brief)->digest,
        'strict ingress does not create nondeterministic output');
}

$badTexts=[
    "line\nbreak", "tab\tfield", "NUL\x00suffix", "DEL\x7f",
    "ascii\x1funit", "https://remote.invalid/path",
    "HTTP://remote.invalid", "javascript:alert(1)", "data:text/html;base64,AAAA",
    "bidi-\u{202E}stressed", "embed-\u{202D}direction",
    "isolate-\u{2066}marker", "pop-\u{2069}marker",
    "left-\u{200E}mark", "right-\u{200F}mark",
];
foreach($badTexts as $text) {
    foreach(['brand','headline','description','callToAction'] as $field) {
        $values=$base;
        $values[$field]='Safe '.$text;
        good247(rejects247($values),'reject unsafe direct text for '.$field);
    }
    $values=$base;
    $values['benefits']=['Legitimate','Unsafe '.$text];
    good247(rejects247($values),'reject unsafe benefit text');
    foreach(['seoTitle','seoDescription'] as $field) {
        $values=$base;
        $values[$field]='Safe '.$text;
        good247(rejects247($values),'reject unsafe SEO field');
    }
    foreach(['faq','highlights','processSteps'] as $field) {
        $values=$base;
        $first=$values[$field][0];
        $last=array_key_last($first);
        $values[$field][0][$last]='Unsafe '.$text;
        good247(rejects247($values),'reject unsafe rich section text for '.$field);
    }
}
// Four renderer entry points also accept malformed data types by PHP's
// array constructor. Ensure controls are rejected BEFORE any render.
$values=$base;
$values['benefits']=[false,'Valid'];
good247(rejects247($values),'non-string benefits rejected');
$values=$base;
$values['faq'][0]['answer']='A <b>visible</b> fictional phrase';
$brief=new SiteBrief(...$values);
good247(str_contains((new StaticSiteRenderer())->render($brief)->html,
    'A &lt;b&gt;visible&lt;/b&gt; fictional phrase'),
    'literal angle brackets remain escaped, not stripped or executed');

echo "$checks F1-247 direct-SiteBrief plain-text safety checks passed; no real input.\n";
