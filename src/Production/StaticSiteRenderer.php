<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

/**
 * F1-06 static, deterministic template lab. No AI execution, HTTP, network,
 * external assets, forms, analytics, public URLs or deployment.
 */
final class StaticSiteRenderer
{
    public function render(SiteBrief $brief): ReleaseArtifact
    {
        $escape = static fn (string $text): string =>
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        $brand = $escape($brief->brand);
        $headline = $escape($brief->headline);
        $description = $escape($brief->description);
        $cta = $escape($brief->callToAction);
        $title = $escape($brief->headline.' | '.$brief->brand);
        $bullets = implode("\n", array_map(
            static fn (string $s): string => '            <li>'.$escape($s).'</li>',
            $brief->benefits,
        ));

        $isOnepage = $brief->productCode === 'onepage';
        $nav = $isOnepage ?
            '<nav aria-label="Navegação principal"><a href="#beneficios">Benefícios</a><a href="#sobre">Sobre</a></nav>' :
            '<span class="tagline">Uma solução para você</span>';
        // F1-178: opt-in anchors are generated only from a fixed map and
        // only when the corresponding inert section exists. Never use a
        // user-provided URL, arbitrary CSS or AI-controlled navigation.
        if ($isOnepage && $brief->sectionNavigation &&
            ($brief->highlights!==[] || $brief->processSteps!==[] || $brief->faq!==[])) {
            $anchors=[
                'highlights'=>['#solucoes','Soluções',$brief->highlights!==[]],
                'process'=>['#etapas','Como funciona',$brief->processSteps!==[]],
                'faq'=>['#perguntas','FAQ',$brief->faq!==[]],
            ];
            $additional='';
            foreach($brief->sectionOrder as $name) {
                [$href,$label,$present]=$anchors[$name];
                if ($present) {
                    $additional.='<a href="'.$href.'">'.$label.'</a>';
                }
            }
            $nav=str_replace('</nav>',$additional.'</nav>',$nav);
        }
        $benefitsHeading = $isOnepage ? 'Por que escolher '.$brand.'?' : 'O que você encontra aqui';
        $about = $isOnepage ?
            '<section class="about" id="sobre" aria-labelledby="sobre-title"><div class="container">'.
            '<h2 id="sobre-title">Sobre '.$brand.'</h2><p>'.$description.'</p></div></section>' : '';
        $mode = $isOnepage ? 'onepage' : 'landing-page';
        $highlightsSection = '';
        if ($brief->highlights !== []) {
            $highlightItems = implode("\n", array_map(
                static fn (array $item): string =>
                    '          <li><h3>'.$escape($item['title']).'</h3><p>'.
                    $escape($item['description']).'</p></li>',
                $brief->highlights,
            ));
            $highlightsSection = <<<HIGHLIGHTS
    <section class="highlights" id="solucoes" aria-labelledby="highlights-title">
      <div class="container">
        <h2 id="highlights-title">Soluções em destaque</h2>
        <ul>
{$highlightItems}
        </ul>
      </div>
    </section>
HIGHLIGHTS;
        }
        $processSection = '';
        if ($brief->processSteps !== []) {
            $stepItems = implode("\n", array_map(
                static fn (array $step): string =>
                    '          <li><h3>'.$escape($step['title']).'</h3><p>'.
                    $escape($step['description']).'</p></li>',
                $brief->processSteps,
            ));
            $processSection = <<<PROCESS
    <section class="process" id="etapas" aria-labelledby="process-title">
      <div class="container">
        <h2 id="process-title">Como funciona</h2>
        <ol>
{$stepItems}
        </ol>
      </div>
    </section>
PROCESS;
        }
        $faqSection = '';
        if ($brief->faq !== []) {
            $faqItems = implode("\n", array_map(
                static fn (array $entry): string =>
                    '          <li><h3>'.$escape($entry['question']).'</h3><p>'.
                    $escape($entry['answer']).'</p></li>',
                $brief->faq,
            ));
            $faqSection = <<<FAQ
    <section class="faq" id="perguntas" aria-labelledby="faq-title">
      <div class="container">
        <h2 id="faq-title">Perguntas frequentes</h2>
        <ul>
{$faqItems}
        </ul>
      </div>
    </section>
FAQ;
        }

        // F1-175: only an explicit three-name permutation may change
        // the display order. Empty optional sections are silently skipped.
        // The default order is byte-for-byte identical to F1-174.
        $optionalSections=[
            'highlights'=>$highlightsSection,
            'process'=>$processSection,
            'faq'=>$faqSection,
        ];
        foreach ($brief->sectionOrder as $name) {
            $section=$optionalSections[$name];
            if ($section!=='') {
                $about .= "\n    ".$section;
            }
        }


        $html = <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="{$description}">
  <meta name="robots" content="noindex, nofollow">
  <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; object-src 'none'">
  <title>{$title}</title>
  <style>
    :root { color-scheme: light; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    * { box-sizing: border-box; }
    body { margin: 0; color: #19304c; background: #f7f9fc; line-height: 1.6; }
    a { color: inherit; }
    a:focus-visible { outline: 3px solid #2366bc; outline-offset: 4px; }
    .container { max-width: 1080px; margin-inline: auto; padding-inline: 24px; }
    header { background: #fff; border-bottom: 1px solid #e4eaf1; }
    .header-inner { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; justify-content: space-between; padding-block: 20px; }
    .brand { font-size: 1.15rem; font-weight: 800; letter-spacing: -.02em; }
    nav { display: flex; gap: 24px; }
    nav a { text-decoration: none; font-weight: 600; }
    .tagline { color: #5f6e80; font-size: .9rem; }
    .hero { background: linear-gradient(120deg, #122b4d, #285c88); color: #fff; padding-block: clamp(64px, 9vw, 136px); }
    .hero-inner { max-width: 780px; }
    .eyebrow { font-weight: 700; letter-spacing: .14em; text-transform: uppercase; font-size: .78rem; opacity: .8; }
    h1 { font-size: clamp(2.25rem, 5.5vw, 4.5rem); line-height: 1.1; letter-spacing: -.045em; margin: 18px 0 22px; overflow-wrap: anywhere; }
    h2 { font-size: clamp(1.6rem, 3vw, 2.4rem); line-height: 1.18; letter-spacing: -.025em; overflow-wrap: anywhere; }
    p { max-width: 70ch; }
    .lead { font-size: clamp(1.05rem, 2vw, 1.3rem); opacity: .92; }
    .btn { display: inline-flex; padding: 14px 28px; margin-top: 22px; border-radius: 9px; background: #fff; color: #173e69; font-weight: 800; text-decoration: none; }
    section { padding-block: 64px; }
    .benefits ul { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 18px; list-style: none; padding: 0; }
    .benefits li { background: #fff; padding: 24px; border: 1px solid #e4eaf1; border-radius: 12px; box-shadow: 0 4px 20px rgba(16,37,57,.04); }
    .about { background: #edf3f9; }
    .contact { background: #fff; }
    footer { border-top: 1px solid #e4eaf1; padding: 28px 0; color: #57677b; font-size: .9rem; }
    @media (prefers-reduced-motion: reduce) { * { scroll-behavior: auto !important; } }
  </style>
</head>
<body data-site-product="{$mode}">
  <header><div class="container header-inner"><span class="brand">{$brand}</span>{$nav}</div></header>
  <main id="principal">
    <section class="hero" aria-labelledby="hero-title">
      <div class="container hero-inner">
        <div class="eyebrow">{$brand}</div>
        <h1 id="hero-title">{$headline}</h1>
        <p class="lead">{$description}</p>
        <a class="btn" href="#contato">{$cta}</a>
      </div>
    </section>
    <section class="benefits" id="beneficios" aria-labelledby="benefits-title">
      <div class="container">
        <h2 id="benefits-title">{$benefitsHeading}</h2>
        <ul>
{$bullets}
        </ul>
      </div>
    </section>
    {$about}
    <section class="contact" id="contato" aria-labelledby="contact-title">
      <div class="container">
        <h2 id="contact-title">Próximo passo</h2>
        <p>Entre em contato com {$brand} para conhecer os próximos passos.</p>
        <p>Prévia demonstrativa: formulários e envio de dados permanecem desabilitados.</p>
      </div>
    </section>
  </main>
  <footer><div class="container">{$brand} — demonstração sem publicação e sem captação de dados.</div></footer>
</body>
</html>
HTML;

        // Only opt-in Onepages receive wrapping navigation styles.
        if ($isOnepage && $brief->sectionNavigation &&
            ($brief->highlights!==[] || $brief->processSteps!==[] || $brief->faq!==[])) {
            $navCss = <<<'CSS'
    nav { flex-wrap: wrap; gap: 12px 20px; }
    nav a { overflow-wrap: anywhere; }
CSS;
            $html=str_replace("  </style>\n</head>",$navCss."\n  </style>\n</head>",$html);
        }

        // F1-159: self-contained CSS only when highlights exist.
        if ($highlightsSection !== '') {
            $highlightsCss = <<<'CSS'
    .highlights { background: #eaf0f8; }
    .highlights ul { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,240px),1fr)); gap: 18px; }
    .highlights li { background: #fff; border: 1px solid #d6e0ec; border-radius: 14px; padding: 24px; }
    .highlights h3 { margin: 0 0 10px; font-size: 1.17rem; line-height: 1.3; overflow-wrap: anywhere; }
    .highlights p { margin: 0; overflow-wrap: anywhere; }
CSS;
            $html = str_replace("  </style>\n</head>", $highlightsCss."\n  </style>\n</head>", $html);
        }

        // F1-169: ordered informational steps use native <ol>. No script,
        // link, button, form or route is introduced; legacy bytes unchanged.
        if ($processSection !== '') {
            $processCss = <<<'CSS'
    .process { background: #edf3f9; }
    .process ol { list-style: decimal; padding-left: 24px; display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,260px),1fr)); gap: 18px; }
    .process li { background: #fff; border: 1px solid #d6e0ec; border-radius: 12px; padding: 24px 24px 24px 30px; }
    .process h3 { margin: 0 0 10px; font-size: 1.14rem; line-height: 1.3; overflow-wrap: anywhere; }
    .process p { margin: 0; overflow-wrap: anywhere; }
CSS;
            $html = str_replace("  </style>\n</head>", $processCss."\n  </style>\n</head>", $html);
        }

        // F1-157: FAQ CSS exists only when the optional block is present.
        // Existing no-FAQ releases remain byte-for-byte unchanged.
        if ($faqSection !== '') {
            $faqCss = <<<'CSS'
    .faq { background: #f7f9fc; }
    .faq ul { list-style: none; padding: 0; display: grid; gap: 18px; }
    .faq li { padding: 24px; background: #fff; border: 1px solid #e4eaf1; border-radius: 12px; }
    .faq h3 { margin: 0 0 10px; font-size: 1.1rem; line-height: 1.3; overflow-wrap: anywhere; }
    .faq p { margin: 0; overflow-wrap: anywhere; }
CSS;
            $html = str_replace("  </style>\n</head>", $faqCss."\n  </style>\n</head>", $html);
        }

        // F1-51: conservative, predefined offline design tokens only.
        // The classic profile remains BYTE-IDENTICAL with previous releases.
        // Never interpolate user-defined CSS, links, fonts, JS or assets.
        $presetCss=match($brief->stylePreset) {
            'classic'=>'',
            'modern'=> <<<'CSS'
    /* visual-preset: modern */
    .benefits ul { grid-template-columns: repeat(auto-fit,minmax(min(100%,220px),1fr)); }
    body { color: #1b3154; background: #eff4fa; }
    header { background: #f8fbff; border-color: #dce6f4; }
    nav a { color: #214978; }
    .hero { background: linear-gradient(125deg, #111d33, #254f97); }
    .eyebrow { opacity: 1; color: #d1e7ff; }
    .btn { background: #d8eaff; color: #16375d; border-radius: 999px; }
    .benefits li { border-color: #cbdcec; border-radius: 20px; box-shadow: 0 9px 27px rgba(12,38,70,.08); }
    .about { background: #e1ecfb; }
    .contact { background: #f8fbff; }
    footer { color: #495c75; }
CSS,
            'warm'=> <<<'CSS'
    /* visual-preset: warm */
    .benefits ul { grid-template-columns: repeat(auto-fit,minmax(min(100%,220px),1fr)); }
    body { color: #452c33; background: #fbf5ef; }
    header { background: #fffaf5; border-color: #ecdcd2; }
    nav a { color: #704036; }
    .hero { background: linear-gradient(130deg, #4b2637, #8b4938); }
    .eyebrow { opacity: 1; color: #ffe6d4; }
    .btn { background: #fff0e0; color: #60313a; border-radius: 12px; }
    .benefits li { background: #fffcf8; border-color: #ead8c9; box-shadow: 0 8px 22px rgba(80,46,41,.06); }
    .about { background: #f4e5d9; }
    .contact { background: #fffaf5; }
    footer { color: #6a5156; }
CSS,
        };
        if ($presetCss!=='') {
            // Only the fixed closing tag of our own renderer can be replaced.
            $html=str_replace("  </style>\n</head>",$presetCss."\n  </style>\n</head>",$html);
        }
        return new ReleaseArtifact($html);
    }
}
