<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Imagine Sites | Revisão demonstrativa</title>
<style>
:root{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f3048;background:#f4f6fa}
*{box-sizing:border-box}
body{margin:0;line-height:1.5}
a{color:inherit;text-decoration:none}
a:focus-visible{outline:3px solid #387cfa;outline-offset:4px}
header{background:#132641;color:#fff;padding:16px clamp(18px,3vw,44px);display:flex;justify-content:space-between;align-items:center;gap:18px}
.brand{font-size:1.13rem;font-weight:850;letter-spacing:-.04em}
.brand small{font-size:.71rem;letter-spacing:.08em;color:#a9c2e2;display:block;text-transform:uppercase}
.flag{font-size:.78rem;border:1px solid #6a7f99;border-radius:100px;padding:7px 12px;color:#d6e4f5}
main{max-width:1550px;padding:26px clamp(18px,3vw,44px) 55px;margin:auto}
.kicker{color:#27609a;font-size:.73rem;font-weight:800;text-transform:uppercase;letter-spacing:.14em}
h1{font-size:clamp(1.65rem,2.8vw,2.35rem);line-height:1.16;letter-spacing:-.04em;margin:9px 0}
.sub{font-size:.85rem;color:#52647a;margin:5px 0 17px}
.intro{display:flex;align-items:end;justify-content:space-between;gap:22px;margin-bottom:26px}
.status{background:#fff7e7;border:1px solid #efd8aa;color:#73520e;border-radius:11px;padding:10px 13px;font-size:.81rem;font-weight:700}
.layout{display:grid;grid-template-columns:minmax(280px,330px) minmax(0,1fr);gap:20px;align-items:start}
.sidebar{display:grid;gap:16px}
.card,.preview{background:white;border:1px solid #e2e7ee;border-radius:15px;box-shadow:0 9px 26px rgba(16,40,70,.04)}
.card{padding:21px}
h2{font-size:1rem;margin:0;letter-spacing:-.015em}
.tabs{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px}
.tabs a{border:1px solid #dbe3ec;border-radius:9px;padding:9px 13px;font-size:.8rem;font-weight:700}
.tabs a[aria-current]{background:#e8f2fc;border-color:#bed5ec;color:#155e9e}
.steps{list-style:none;margin:16px 0 0;padding:0}
.steps li{padding:0 0 20px 32px;position:relative;font-size:.8rem}
.steps li:last-child{padding-bottom:0}
.steps li:not(:last-child)::after{content:"";position:absolute;width:1px;background:#dce4ed;left:10px;top:26px;bottom:2px}
.icon{position:absolute;left:0;top:0;width:22px;height:22px;border-radius:50%;display:grid;place-items:center;background:#e6f4ea;color:#276742;font-size:.8rem}
.steps li:last-child .icon{background:#fff1d8;color:#835b16}
.steps b{display:block}
.steps small{display:block;color:#52647a;font-size:.75rem}
dl{margin:15px 0 0}
dl div{margin-bottom:13px}
dt{font-size:.69rem;text-transform:uppercase;letter-spacing:.07em;font-weight:800;color:#52647a}
dd{margin:3px 0 0;font-size:.83rem;font-weight:650;overflow-wrap:anywhere}
code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.75rem;color:#2a577a;word-break:break-all}
.locked{margin-top:13px;font-size:.76rem;color:#52647a;padding:13px;border:1px dashed #bdcad7;border-radius:10px;background:#f8fafc}
.preview{overflow:hidden}
.preview-head{padding:18px 21px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;border-bottom:1px solid #e2e7ee}
.preview-head .tabs{margin:0}
.workspace{background:#e8edf4;padding:24px 18px 30px;min-height:550px}
.browser{border:1px solid #c5d1df;border-radius:13px;overflow:hidden;box-shadow:0 20px 45px rgba(19,40,65,.13);margin:auto;background:#fff}
.browser.desktop{max-width:1120px;width:100%}
.browser.mobile{max-width:390px;width:100%}
.chrome{display:flex;gap:7px;align-items:center;background:#fafbfd;padding:13px;border-bottom:1px solid #dce4ed}
.chrome i{width:9px;height:9px;border-radius:50%;background:#ced7e3}
.chrome span{margin:auto;color:#52647a;font-size:.7rem}
iframe{width:100%;height:505px;border:0;display:block;background:#fff}
.foot{padding:15px 21px;color:#52647a;font-size:.75rem;border-top:1px solid #e2e7ee}
.note{font-size:.78rem;background:#ecf3fb;border:1px solid #d5e5f6;padding:15px 18px;border-radius:10px;margin-top:20px;color:#2a5077}
.qa-summary{margin-top:20px;border-top:1px solid #e5ebf2;padding-top:17px}.qa-summary strong{font-size:.84rem}.qa-summary p{font-size:.73rem;color:#52647a;margin:5px 0 10px}.qa-list{padding:0;list-style:none;display:grid;gap:7px}.qa-list li{display:flex;gap:9px;align-items:center;font-size:.76rem}.qa-list span{display:grid;place-items:center;width:18px;height:18px;background:#e8f4ec;color:#246342;border-radius:50%;font-weight:800}.qa-summary p.pending{margin-top:11px;background:#fff8e9;color:#7a5b23;border-radius:7px;padding:9px}
.editorial-summary{margin-top:15px;padding-top:15px;border-top:1px solid #e5ebf2}
.editorial-summary strong{font-size:.84rem}
.editorial-summary .sub{margin:5px 0}
.editorial-note{padding:9px 10px;border-radius:8px;background:#f4f7fb;color:#394f68;font-size:.76rem}
@media(max-width:940px){.layout{grid-template-columns:1fr}.sidebar{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:610px){header,.intro{flex-direction:column;align-items:flex-start}.sidebar{grid-template-columns:1fr}.workspace{padding:10px 8px;min-height:0}iframe{height:480px}}
</style>
</head>
<body>
<header><div class="brand">Imagine Sites<small>Laboratório de revisão visual</small></div><span class="flag">Somente dados fictícios</span></header>
<main>
<div class="intro">
  <div><div class="kicker">Produção de sites / Revisão</div><h1>Confira a prévia antes de avançar</h1><p class="sub">Experiência de demonstração. Nenhuma ação aprova ou publica um site.</p></div>
  <span class="status">Revisão interna pendente · Aceite oficial pendente no Portal</span>
</div>
<div class="layout">
  <aside class="sidebar" aria-label="Painel de revisão">
    <section class="card" aria-labelledby="product-title"><h2 id="product-title">Tipo de site</h2><p class="sub">Alternar modelos de teste</p>
      <nav class="tabs" aria-label="Alternar produto">
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product=onepage&amp;device={{ $device }}{{ $galleryMode ? '' : '&preset='.$preset }}{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}" @if($product==='onepage') aria-current="page" @endif>Onepage</a>
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product=landing_page&amp;device={{ $device }}{{ $galleryMode ? '' : '&preset='.$preset }}{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}" @if($product==='landing_page') aria-current="page" @endif>Landing Page</a>
      </nav>
    </section>
    @unless($galleryMode)
    <section class="card" aria-labelledby="style-title"><h2 id="style-title">Estilo visual</h2>
      <p class="sub">Cada tema possui HTML e SHA-256 próprios.</p>
      <nav class="tabs" aria-label="Alternar tema">
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device={{ $device }}&amp;preset=classic{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}" @if($preset==='classic') aria-current="page" @endif>Classic</a>
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device={{ $device }}&amp;preset=modern{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}" @if($preset==='modern') aria-current="page" @endif>Modern</a>
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device={{ $device }}&amp;preset=warm{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}" @if($preset==='warm') aria-current="page" @endif>Warm</a>
      </nav>
      <p class="sub">Trocar o tema exige nova revisão interna da versão gerada.</p>
    </section>
    @endunless
    @if(($richReview ?? false) === true)
    <section class="card" aria-labelledby="rich-title">
      <h2 id="rich-title">Conteúdo da página ampliado</h2>
      <p class="sub">Prévia {{ count($brief->processSteps) > 0 ? 'v4' : 'v3' }} totalmente fictícia: {{ count($brief->highlights) }} soluções e {{ count($brief->faq) }} perguntas frequentes.</p>
      @if(count($brief->processSteps) > 0)
        <p class="sub">{{ count($brief->processSteps) }} etapas de trabalho incluídas, todas aguardando revisão.</p>
      @endif
      <p class="sub">Os textos seguem pendentes de revisão interna; nenhuma autorização de publicação foi concedida.</p>
    </section>
    @endif
    @if(($richVariant ?? null) !== null)
    <section class="card" aria-labelledby="rich-variant-title">
      <h2 id="rich-variant-title">Comparar blocos do site</h2>
      <p class="sub">Cada composição é uma versão fictícia distinta, com hash próprio e revisão pendente.</p>
      <nav class="tabs" aria-label="Alternar blocos de conteúdo">
        @foreach(['faq'=>'Somente FAQ','highlights'=>'Somente serviços','both'=>'FAQ e serviços','steps'=>'Serviços e etapas','full'=>'Página completa'] as $variantCode=>$variantLabel)
          <a href="{{ $reviewRoute }}?product={{ $product }}&amp;device={{ $device }}&amp;preset={{ $preset }}&amp;variant={{ $variantCode }}" @if($richVariant===$variantCode) aria-current="page" @endif>{{ $variantLabel }}</a>
        @endforeach
      </nav>
    </section>
    @endif
    <section class="card" aria-labelledby="steps-title"><h2 id="steps-title">Etapas de revisão</h2>
      <ol class="steps">
        <li><span class="icon" aria-hidden="true">✓</span><b>Conteúdo estruturado</b><small>Briefing fictício validado</small></li>
        <li><span class="icon" aria-hidden="true">✓</span><b>Prévia HTML gerada</b><small>Hash e auditoria offline</small></li>
        <li><span class="icon" aria-hidden="true">!</span><b>Revisão interna pendente</b><small>Dossiê disponível, nenhuma decisão registrada</small></li>
        <li><span class="icon" aria-hidden="true">!</span><b>Aceite do cliente pendente</b><small>Somente após revisão interna, exclusivo do Portal</small></li>
      </ol>
    </section>
    <section class="card" id="detalhes" aria-labelledby="detail-title"><h2 id="detail-title">Identidade da versão</h2>
      <dl>
        <div><dt>Produto</dt><dd>{{ $product==='onepage' ? 'Onepage' : 'Landing Page' }}</dd></div>
        <div><dt>Tema</dt><dd>{{ ucfirst($preset) }}</dd></div>
        <div><dt>Marca de teste</dt><dd>{{ $brief->brand }}</dd></div>
        <div><dt>Release</dt><dd><code>{{ $reviewSnapshot['releaseRef'] }}</code></dd></div>
        <div><dt>Revisão</dt><dd>{{ $reviewSnapshot['workflowRevision'] }}</dd></div>
        <div><dt>SHA-256 do HTML</dt><dd><code id="artifact-digest">{{ $digest }}</code></dd></div>
        <div><dt>Estado interno</dt><dd id="review-state">Revisão interna pendente</dd></div>
        <div><dt>SHA-256 do dossiê</dt><dd><code id="dossier-digest">{{ $dossierDigest }}</code></dd></div>
        <div><dt>Publicável</dt><dd>Não</dd></div>
        <div><dt>Aceite oficial</dt><dd>Não registrado</dd></div>
      </dl>
      <div class="locked">Aprovação, publicação e entrega desabilitadas neste laboratório.</div>
      <div class="qa-summary" id="qa">
        <strong>Verificações estruturais</strong>
        <p>Resultados automáticos da prévia. Não equivalem à certificação de acessibilidade.</p>
        <ul class="qa-list">
        @foreach([
          'inert_markup'=>'HTML sem conteúdo ativo',
          'sha256_consistent'=>'Identidade dos bytes',
          'lang_and_title'=>'Idioma e título',
          'main_and_heading'=>'Regiões e H1',
          'navigation_anchors'=>'Links internos e IDs',
          'mobile_and_noindex'=>'Viewport e noindex',
          'no_executable_elements'=>'Sem tags executáveis'
        ] as $code=>$label)
          <li><span aria-hidden="true">{{ ($qaChecks[$code]??false) ? '✓' : '!' }}</span>{{ $label }}</li>
        @endforeach
        </ul>
        <p class="pending">Revisão visual, contraste, navegação por teclado e conteúdo exigem validação humana posterior.</p>
      </div>
      <div class="editorial-summary" id="editorial" aria-labelledby="editorial-title">
        <strong id="editorial-title">Revisão editorial orientativa</strong>
        <p class="sub">Sugestões automáticas não substituem a aprovação humana nem autorizam publicação. {{ count($requiredAcknowledgments) }} observação(ões) pendente(s) de reconhecimento.</p>
        @forelse($editorialSuggestions as $suggestion)
          <p class="editorial-note">{{ $suggestion }}</p>
        @empty
          <p class="editorial-note">Sem sugestões automáticas para esta demonstração. Revisão humana ainda necessária.</p>
        @endforelse
      </div>
    </section>
  </aside>
  <section class="preview" aria-labelledby="preview-title">
    <div class="preview-head"><div><h2 id="preview-title">Prévia do site</h2><p class="sub">HTML exato do motor, isolado em iframe sandbox</p></div>
      <nav class="tabs" aria-label="Alternar dispositivo">
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device=desktop{{ $galleryMode ? '' : '&preset='.$preset }}{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}" @if($device==='desktop') aria-current="page" @endif>Desktop</a>
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device=mobile{{ $galleryMode ? '' : '&preset='.$preset }}{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}" @if($device==='mobile') aria-current="page" @endif>Mobile</a>
      </nav>
    </div>
    <div class="workspace"><div class="browser {{ $device }}">
      <div class="chrome" aria-hidden="true"><i></i><i></i><i></i><span>Prévia privada · sem domínio público</span></div>
      <iframe title="Prévia sintética do site {{ $product }}" sandbox="" tabindex="0" referrerpolicy="no-referrer" srcdoc="{{ $previewHtml }}"></iframe>
    </div></div>
    <div class="foot">Sem captação, scripts, URLs de clientes ou publicação. <a href="#detalhes">Ver identidade da versão</a></div>
  </section>
</div>
<p class="note">Somente para testes Laravel em processo CLI. O Portal é a autoridade exclusiva para contrato, aceite expresso do cliente e evento oficial ENTREGUE.</p>
</main>
</body>
</html>
