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
        $benefitsHeading = $isOnepage ? 'Por que escolher '.$brand.'?' : 'O que você encontra aqui';
        $about = $isOnepage ?
            '<section class="about" id="sobre" aria-labelledby="sobre-title"><div class="container">'.
            '<h2 id="sobre-title">Sobre '.$brand.'</h2><p>'.$description.'</p></div></section>' : '';
        $mode = $isOnepage ? 'onepage' : 'landing-page';

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
        return new ReleaseArtifact($html);
    }
}
