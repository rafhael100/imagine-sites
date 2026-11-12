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
.skip-preview{display:block;max-width:1120px;margin:0 auto 10px;text-align:right;font-size:.78rem;text-decoration:underline;color:#174673;font-weight:700}
.skip-preview:focus-visible{outline:3px solid #2366bc;outline-offset:3px}
.browser{border:1px solid #c5d1df;border-radius:13px;overflow:hidden;box-shadow:0 20px 45px rgba(19,40,65,.13);margin:auto;background:#fff}
.browser.desktop{max-width:1120px;width:100%}
.browser.mobile{max-width:390px;width:100%}
.chrome{display:flex;gap:7px;align-items:center;background:#fafbfd;padding:13px;border-bottom:1px solid #dce4ed}
.chrome i{width:9px;height:9px;border-radius:50%;background:#ced7e3}
.chrome span{margin:auto;color:#52647a;font-size:.7rem}
iframe{width:100%;height:505px;border:0;display:block;background:#fff}
.foot{padding:15px 21px;color:#52647a;font-size:.75rem;border-top:1px solid #e2e7ee}
.layout-evidence-detail{margin:13px 0;font-size:.78rem}.layout-evidence-detail summary{font-weight:700;cursor:pointer}.layout-evidence-detail pre{max-height:290px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #dce4ed;background:#f8fafc;border-radius:8px;padding:12px;font-size:.7rem}
@if(($socialCard ?? null) !== null)
.social-card-preview{margin-top:16px;padding:18px;border:1px solid #d5e1ee;border-radius:13px;background:#f9fbfd;overflow-wrap:anywhere}
.social-card-preview h3{font-size:1rem;line-height:1.3;margin:7px 0 11px;font-weight:800;color:#1f3048}
.social-card-preview p{font-size:.81rem;margin:8px 0;color:#52647a}
.social-card-preview .social-card-kicker{font-size:.69rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#2a577a}
.social-card-preview .social-card-origin{font-weight:700}
@endif
@if(($seoSnippet ?? null) !== null)
.seo-snippet-example{background:#fff;border:1px solid #dce5ef;border-radius:10px;padding:15px;margin-top:12px;overflow-wrap:anywhere}
.seo-snippet-example p{margin:5px 0;font-size:.82rem}
.seo-snippet-example #seo-preview-title{color:#204c88;font-weight:750;font-size:.97rem;line-height:1.3}
.seo-snippet-example #seo-preview-description{color:#405064}
.seo-snippet-advisories{padding-left:20px;margin:8px 0;color:#61470e;font-size:.78rem}
@endif
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
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product=onepage&amp;device={{ $device }}{{ $galleryMode ? '' : '&preset='.$preset }}{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.$anchorNavMode }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}{{ ($visualSocialChoice ?? null) === null ? '' : '&order='.$visualSocialChoice['order'].'&nav='.$visualSocialChoice['nav'].'&palette='.$visualSocialChoice['palette'].'&social='.$visualSocialChoice['social'] }}{{ ($seoCopySelection ?? null) === null ? '' : '&seo='.$seoCopySelection['mode'].'&social='.$seoCopySelection['social'] }}{{ ($ctaMode ?? null) === null ? '' : '&cta='.$ctaMode }}" @if($product==='onepage') aria-current="page" @endif>Onepage</a>
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product=landing_page&amp;device={{ $device }}{{ $galleryMode ? '' : '&preset='.$preset }}{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.($anchorNavMode==='on'?'off':$anchorNavMode) }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}{{ ($visualSocialChoice ?? null) === null ? '' : '&order='.$visualSocialChoice['order'].'&nav=off&palette='.$visualSocialChoice['palette'].'&social='.$visualSocialChoice['social'] }}{{ ($ctaMode ?? null) === null ? '' : '&cta='.$ctaMode }}" @if($product==='landing_page') aria-current="page" @endif>Landing Page</a>
      </nav>
    </section>
    @unless($galleryMode)
    <section class="card" aria-labelledby="style-title"><h2 id="style-title">Estilo visual</h2>
      <p class="sub">Cada tema possui HTML e SHA-256 próprios.</p>
      <nav class="tabs" aria-label="Alternar tema">
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device={{ $device }}&amp;preset=classic{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.$anchorNavMode }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}{{ ($visualSocialChoice ?? null) === null ? '' : '&order='.$visualSocialChoice['order'].'&nav='.$visualSocialChoice['nav'].'&palette='.$visualSocialChoice['palette'].'&social='.$visualSocialChoice['social'] }}{{ ($seoCopySelection ?? null) === null ? '' : '&seo='.$seoCopySelection['mode'].'&social='.$seoCopySelection['social'] }}{{ ($ctaMode ?? null) === null ? '' : '&cta='.$ctaMode }}" @if($preset==='classic') aria-current="page" @endif>Classic</a>
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device={{ $device }}&amp;preset=modern{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.$anchorNavMode }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}{{ ($visualSocialChoice ?? null) === null ? '' : '&order='.$visualSocialChoice['order'].'&nav='.$visualSocialChoice['nav'].'&palette='.$visualSocialChoice['palette'].'&social='.$visualSocialChoice['social'] }}{{ ($seoCopySelection ?? null) === null ? '' : '&seo='.$seoCopySelection['mode'].'&social='.$seoCopySelection['social'] }}{{ ($ctaMode ?? null) === null ? '' : '&cta='.$ctaMode }}" @if($preset==='modern') aria-current="page" @endif>Modern</a>
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device={{ $device }}&amp;preset=warm{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.$anchorNavMode }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}{{ ($visualSocialChoice ?? null) === null ? '' : '&order='.$visualSocialChoice['order'].'&nav='.$visualSocialChoice['nav'].'&palette='.$visualSocialChoice['palette'].'&social='.$visualSocialChoice['social'] }}{{ ($seoCopySelection ?? null) === null ? '' : '&seo='.$seoCopySelection['mode'].'&social='.$seoCopySelection['social'] }}{{ ($ctaMode ?? null) === null ? '' : '&cta='.$ctaMode }}" @if($preset==='warm') aria-current="page" @endif>Warm</a>
      </nav>
      <p class="sub">Trocar o tema exige nova revisão interna da versão gerada.</p>
    </section>
    @endunless
    @if(($ctaMode ?? null) !== null)
    <section class="card" aria-labelledby="secondary-cta-title">
      <h2 id="secondary-cta-title">Comparar CTA secundário</h2>
      <p class="sub">Botão adicional para a seção local de contato. Sem formulário, publicação ou envio de dados.</p>
      <nav class="tabs" aria-label="Alternar CTA secundário">
        @foreach(['off'=>'Desativado','on'=>'Ativado'] as $ctaOption=>$ctaLabel)
          <a href="{{ $reviewRoute }}?product={{ $product }}&amp;device={{ $device }}&amp;preset={{ $preset }}&amp;cta={{ $ctaOption }}" @if($ctaMode===$ctaOption) aria-current="page" @endif>{{ $ctaLabel }}</a>
        @endforeach
      </nav>
      <dl>
        <div><dt>SHA-256 sem CTA</dt><dd><code id="cta-digest-off">{{ $ctaDigestOff }}</code></dd></div>
        <div><dt>SHA-256 com CTA</dt><dd><code id="cta-digest-on">{{ $ctaDigestOn }}</code></dd></div>
        <div><dt>SHA-256 da comparação</dt><dd><code id="cta-evidence-digest">{{ $ctaEvidenceDigest }}</code></dd></div>
        <div><dt>SHA-256 dos pacotes privados</dt><dd><code id="cta-package-evidence-digest">{{ $ctaPackageEvidenceDigest }}</code></dd></div>
        <div><dt>Autoridade da comparação</dt><dd id="cta-evidence-authority">Nenhuma; revisão pendente</dd></div>
        <div><dt>Estado desta prévia</dt><dd id="cta-review-state">Revisão interna pendente</dd></div>
      </dl>
      <p class="locked">Os arquivos privados de ambas as versões foram verificados (HTML, dossiê, resumo e manifesto), sem liberar o acesso ao conteúdo ou autorizar a publicação.</p>
      <p class="locked">Esta evidência vincula apenas as duas versões privadas por SHA-256. Não comprova identidade, aceite, publicação nem autoriza captação de leads.</p>
      <p class="locked">Alterar esta opção cria outra versão. A equipe Imagine precisa revisar os novos bytes; aceite do cliente pertence ao Portal.</p>
    </section>
    @endif
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
    @if(($editorialPatchDetails ?? null) !== null)
    <section class="card" aria-labelledby="editorial-patch-title">
      <h2 id="editorial-patch-title">Comparação da edição</h2>
      <p class="sub">Alterações pontuais de conteúdo fictício pela equipe Imagine. A versão anterior permanece preservada e a nova exige revisão.</p>
      <dl>
        <div><dt>Versão anterior</dt><dd><code id="previous-artifact-digest">{{ $editorialPatchDetails['previousDigest'] }}</code></dd></div>
        <div><dt>Nova versão</dt><dd><code id="next-artifact-digest">{{ $editorialPatchDetails['nextDigest'] }}</code></dd></div>
        <div><dt>Campos alterados</dt><dd id="edited-fields">{{ implode(', ', $editorialPatchDetails['changedFields']) }}</dd></div>
        <div><dt>Permissão de publicar</dt><dd>Não concedida</dd></div>
      </dl>
      <p class="locked">A edição não reaproveita o aceite anterior e não envia o conteúdo ao CRM ou ao Portal.</p>
    </section>
    @endif
    @if(($sectionOrderCode ?? null) !== null)
    <section class="card" aria-labelledby="section-order-title">
      <h2 id="section-order-title">Ordem das seções da página</h2>
      <p class="sub">Escolha uma disposição de demonstração. Cada mudança gera um novo HTML e exige revisão interna.</p>
      <nav class="tabs" aria-label="Alternar ordem de seções">
        @foreach([
          'services_process_faq'=>'Serviços / Etapas / FAQ',
          'services_faq_process'=>'Serviços / FAQ / Etapas',
          'process_services_faq'=>'Etapas / Serviços / FAQ',
          'process_faq_services'=>'Etapas / FAQ / Serviços',
          'faq_services_process'=>'FAQ / Serviços / Etapas',
          'faq_process_services'=>'FAQ / Etapas / Serviços'
        ] as $sectionOrderOption=>$sectionOrderLabel)
          <a href="{{ $reviewRoute }}?product={{ $product }}&amp;device={{ $device }}&amp;preset={{ $preset }}&amp;order={{ $sectionOrderOption }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.$anchorNavMode }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}" @if($sectionOrderCode===$sectionOrderOption) aria-current="page" @endif>{{ $sectionOrderLabel }}</a>
        @endforeach
      </nav>
      <p class="sub">O conteúdo não muda. A aprovação da disposição anterior não é reutilizada.</p>
    </section>
    @endif
    @if(($anchorNavMode ?? null) !== null)
    <section class="card" aria-labelledby="anchor-nav-title">
      <h2 id="anchor-nav-title">Navegação interna da Onepage</h2>
      <p class="sub">Compare o mesmo conteúdo com ou sem links locais para Soluções, Etapas e FAQ.</p>
      @if($product==='onepage')
        <nav class="tabs" aria-label="Alternar menu interno">
          @foreach(['off'=>'Desativada','on'=>'Ativada'] as $navCode=>$navLabel)
            <a href="{{ $reviewRoute }}?product={{ $product }}&amp;device={{ $device }}&amp;preset={{ $preset }}&amp;nav={{ $navCode }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}" @if($anchorNavMode===$navCode) aria-current="page" @endif>{{ $navLabel }}</a>
          @endforeach
        </nav>
      @else
        <p class="sub">A Landing Page não utiliza menu extra; a apresentação permanece objetiva.</p>
      @endif
      <p class="sub">Mudar o menu gera novo hash e exige revisão da equipe Imagine.</p>
    </section>
    @endif
    @if(($layoutRevisionDetails ?? null) !== null)
    <section class="card" aria-labelledby="layout-diff-title">
      <h2 id="layout-diff-title">Comparação antes e depois do layout</h2>
      <p class="sub">A ordem e a navegação podem mudar sem alterar os textos. A decisão anterior não acompanha o novo layout.</p>
      <dl>
        <div><dt>SHA-256 anterior</dt><dd><code id="layout-previous-digest">{{ $layoutRevisionDetails['previousDigest'] }}</code></dd></div>
        <div><dt>SHA-256 novo</dt><dd><code id="layout-next-digest">{{ $layoutRevisionDetails['nextDigest'] }}</code></dd></div>
        <div><dt>Revisão anterior</dt><dd id="layout-prior-revision">{{ $layoutRevisionDetails['previousRevision'] }}</dd></div>
        <div><dt>Revisão reaberta</dt><dd id="layout-reopened-revision">{{ $layoutRevisionDetails['reopenedRevision'] }} — rascunho, sem aceite</dd></div>
        <div><dt>Ordem alterada</dt><dd id="layout-order-changed">{{ $layoutRevisionDetails['orderChanged'] ? 'Sim' : 'Não' }}</dd></div>
        <div><dt>Menu alterado</dt><dd id="layout-navigation-changed">{{ $layoutRevisionDetails['navigationChanged'] ? 'Sim' : 'Não' }}</dd></div>
        @if(($layoutChangeEvidenceDigest ?? null) !== null)
        <div><dt>Evidência da revisão — SHA-256</dt><dd><code id="layout-evidence-digest">{{ $layoutChangeEvidenceDigest }}</code></dd></div>
        <div><dt>Decisão da nova versão</dt><dd id="layout-evidence-state">Pendente — não publicada, não entregue ao CRM e sem aceite oficial</dd></div>
        @endif
      </dl>
      @if(($layoutChangeEvidenceDigest ?? null) !== null)
      <details class="layout-evidence-detail">
        <summary>Examinar registro canônico da alteração (somente leitura)</summary>
        <pre id="layout-evidence-json">{{ $layoutChangeEvidenceJson }}</pre>
      </details>
      @endif
      <p class="locked">A prévia nova é apenas um renderizador de laboratório, não a continuidade persistida da revisão anterior. O Portal ainda não aprovou nada.</p>
    </section>
    @endif
    @if(($brandPaletteCode ?? null) !== null)
    <section class="card" aria-labelledby="brand-palette-title">
      <h2 id="brand-palette-title">Cores da identidade visual</h2>
      <p class="sub">Compare cores de demonstração escolhidas pela equipe Imagine, com limite mínimo de contraste. A seleção gera novo hash e exige revisão.</p>
      <nav class="tabs" aria-label="Alternar cores da marca">
        @foreach(['default'=>'Tema original','ocean'=>'Oceano','plum'=>'Ameixa','forest'=>'Floresta'] as $brandOption=>$brandLabel)
          <a href="{{ $reviewRoute }}?product={{ $product }}&amp;device={{ $device }}&amp;preset={{ $preset }}&amp;palette={{ $brandOption }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.$anchorNavMode }}" @if($brandPaletteCode===$brandOption) aria-current="page" @endif>{{ $brandLabel }}</a>
        @endforeach
      </nav>
      @if($brandPaletteContrast !== null)
      <dl>
        <div><dt>Contraste da abertura — início</dt><dd>{{ number_format($brandPaletteContrast['heroStart'],1,',','.') }}:1</dd></div>
        <div><dt>Contraste da abertura — fim</dt><dd>{{ number_format($brandPaletteContrast['heroEnd'],1,',','.') }}:1</dd></div>
        <div><dt>Contraste do botão</dt><dd>{{ number_format($brandPaletteContrast['button'],1,',','.') }}:1</dd></div>
      </dl>
      <p class="sub">Os três pares verificados atingem pelo menos 4,5:1 para texto normal. Isso não substitui uma auditoria de acessibilidade.</p>
      @else
      <p class="sub">Tema original sem alterações nas cores da marca.</p>
      @endif
      <p class="locked">Paletas predefinidas, sem CSS livre, envio de dados ou publicação.</p>
    </section>
    @endif
    @if(($visualChangeBaseline ?? false) === true)
    <section class="card" aria-labelledby="visual-baseline-title">
      <h2 id="visual-baseline-title">Configuração visual original</h2>
      <p class="sub">Sem alterações propostas. Esta é somente a prévia inicial pendente de revisão; nenhuma decisão anterior foi inventada ou reaproveitada.</p>
    </section>
    @endif
    @if(($visualChangeDetails ?? null) !== null)
    <section class="card" aria-labelledby="visual-before-title">
      <h2 id="visual-before-title">Antes da alteração visual</h2>
      <p class="sub">A prévia anterior é fixa e isolada; o HTML novo está na prévia principal à direita.</p>
      <dl>
        <div><dt>Tema anterior</dt><dd>Classic</dd></div>
        <div><dt>Tema novo</dt><dd>{{ ucfirst($visualChangeDetails['nextStyle']) }}</dd></div>
        <div><dt>Tema alterado</dt><dd id="visual-style-changed">{{ $visualChangeDetails['styleChanged'] ? 'Sim' : 'Não' }}</dd></div>
        <div><dt>Cores alteradas</dt><dd id="visual-palette-changed">{{ $visualChangeDetails['paletteChanged'] ? 'Sim' : 'Não' }}</dd></div>
      </dl>
      <iframe title="Prévia original da composição visual, somente leitura" sandbox="" tabindex="-1" referrerpolicy="no-referrer" srcdoc="{{ $visualChangeDetails['previousHtml'] }}"></iframe>
      <p class="sub">Nova disposição não reutiliza qualquer revisão ou aceite anterior.</p>
    </section>
    @endif
    @if(($socialPreviewMode ?? null) !== null)
    <section class="card" aria-labelledby="social-preview-title">
      <h2 id="social-preview-title">Metadados para compartilhamento</h2>
      <p class="sub">Compare uma página com e sem títulos e descrições Open Graph/Twitter. Ambos os modelos continuam noindex e não estão publicados.</p>
      <nav class="tabs" aria-label="Alternar metadados sociais">
        @foreach(['off'=>'Desativados','on'=>'Ativados'] as $socialCode=>$socialLabel)
          <a href="{{ $reviewRoute }}?product={{ $product }}&amp;device={{ $device }}&amp;preset={{ $preset }}&amp;social={{ $socialCode }}" @if($socialPreviewMode===$socialCode) aria-current="page" @endif>{{ $socialLabel }}</a>
        @endforeach
      </nav>
      @if(($socialRevisionDetails ?? null) !== null)
        <dl>
          <div><dt>SHA-256 anterior</dt><dd><code id="social-previous-digest">{{ $socialRevisionDetails['previousDigest'] }}</code></dd></div>
          <div><dt>SHA-256 novo</dt><dd><code id="social-next-digest">{{ $socialRevisionDetails['nextDigest'] }}</code></dd></div>
          <div><dt>Revisão reaberta</dt><dd id="social-reopened-revision">{{ $socialRevisionDetails['reopenedRevision'] }} — rascunho, sem aceite</dd></div>
        </dl>
        <p class="locked">A versão anterior não transfere sua aprovação para os metadados novos.</p>
      @else
        <p class="sub" id="social-baseline">Modelo original, sem alteração nem aprovação fictícia criada.</p>
      @endif
      @if(($socialCard ?? null) !== null)
      <article class="social-card-preview" aria-labelledby="social-card-heading">
        <p class="social-card-kicker">Simulação textual do cartão de compartilhamento</p>
        <h3 id="social-card-heading">{{ $socialCard->title }}</h3>
        <p id="social-card-description">{{ $socialCard->description }}</p>
        <p class="social-card-origin">{{ $socialCard->brand }} · sem URL e sem imagem</p>
        <p class="sub">Conferido contra os sete metadados reais do HTML. O cartão não foi enviado a nenhuma rede social.</p>
        <p class="sub">SHA-256: <code id="social-card-digest">{{ $socialCard->artifactDigest }}</code></p>
      </article>
      @else
      <p class="sub" id="social-card-disabled">Nenhum cartão social foi incorporado nesta versão.</p>
      @endif
      <p class="locked">A prévia não contém URL social, imagem externa, formulário ou permissão de indexação.</p>
    </section>
    @endif
        @if(($seoCopySelection ?? null) !== null)
    <section class="card" aria-labelledby="seo-copy-options-title">
      <h2 id="seo-copy-options-title">Comparar texto SEO do site</h2>
      <p class="sub">A manchete da página não muda. Estes títulos e descrições são exemplos fictícios, definidos somente pela equipe Imagine.</p>
      <p class="sub">Metadados do título e descrição</p>
      <nav class="tabs" aria-label="Alternar texto SEO editorial">
        @foreach(['default'=>'Automático','custom'=>'Personalizado'] as $seoMode=>$label)
          <a href="{{ $reviewRoute }}?product={{ $product }}&amp;device={{ $device }}&amp;preset={{ $preset }}&amp;seo={{ $seoMode }}&amp;social={{ $seoCopySelection['social'] }}" @if($seoCopySelection['mode']===$seoMode) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
      </nav>
      <p class="sub">Cartão de compartilhamento</p>
      <nav class="tabs" aria-label="Alternar cartão social no revisor SEO">
        @foreach(['off'=>'Desativado','on'=>'Ativado'] as $socialMode=>$label)
          <a href="{{ $reviewRoute }}?product={{ $product }}&amp;device={{ $device }}&amp;preset={{ $preset }}&amp;seo={{ $seoCopySelection['mode'] }}&amp;social={{ $socialMode }}" @if($seoCopySelection['social']===$socialMode) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
      </nav>
      <p class="sub">SHA-256 da origem: <code id="seo-copy-original-digest">{{ $seoCopySelection['originalDigest'] }}</code></p>
      <p class="sub">SHA-256 da prévia selecionada: <code id="seo-copy-selected-digest">{{ $digest }}</code></p>
      <p class="sub" id="seo-copy-review-state">{{ $seoCopySelection['changed'] ? 'Conteúdo SEO modificado; nova revisão obrigatória.' : 'Metadados automáticos da versão de origem, sem aceite fictício.' }}</p>
      <p class="locked">Não é um editor público. Nenhuma configuração publica, indexa, consulta o Google ou concede aceite no Portal.</p>
    </section>
    @endif
    @if(($seoChangeEvidence ?? null) !== null)
    <section class="card" aria-labelledby="seo-evidence-title">
      <h2 id="seo-evidence-title">Evidência técnica da comparação SEO</h2>
      <p class="sub">Os dois pacotes de revisão privada foram conferidos byte a byte. Apenas título, descrição e, quando habilitados, seus equivalentes sociais podem variar.</p>
      <p class="sub">Alterações permitidas: <strong id="seo-evidence-fields">{{ implode(', ', $seoChangeEvidence->record['changedFields']) }}</strong></p>
      <p class="sub">Hash da evidência: <code id="seo-change-evidence-digest">{{ $seoChangeEvidence->digest }}</code></p>
      <p class="sub">HTML automático: <code id="seo-evidence-before">{{ $seoChangeEvidence->record['beforeHtmlSha256'] }}</code></p>
      <p class="sub">HTML personalizado: <code id="seo-evidence-after">{{ $seoChangeEvidence->record['afterHtmlSha256'] }}</code></p>
      <details class="seo-evidence-details">
        <summary>Conferir integridade dos oito arquivos da revisão</summary>
        @foreach(['default'=>'SEO automático','custom'=>'SEO personalizado'] as $kind=>$label)
          <h3>{{ $label }}</h3>
          <ul>
          @foreach($seoChangeEvidence->record['filesSha256'][$kind] as $file=>$checksum)
            <li>{{ $file }}: <code>{{ $checksum }}</code></li>
          @endforeach
          </ul>
        @endforeach
      </details>
      <p class="locked" id="seo-evidence-no-authority">A evidência é somente técnica: revisão pendente, sem aceite do cliente, publicação ou indexação. O hash não é uma assinatura de aprovação.</p>
    </section>
    @endif
@if(($seoSnippet ?? null) !== null)
    <section class="card" aria-labelledby="seo-snippet-title">
      <h2 id="seo-snippet-title">Prévia textual de busca — não indexada</h2>
      <p class="sub">Conferida contra o título, a descrição e o H1 do HTML exato desta revisão. Nenhum buscador foi consultado.</p>
      <div class="seo-snippet-example" aria-label="Exemplo editorial de título e descrição">
        <p id="seo-preview-title">{{ $seoSnippet->titlePreview }}</p>
        <p id="seo-preview-description">{{ $seoSnippet->descriptionPreview }}</p>
      </div>
      <p class="sub">Título: <strong id="seo-title-length">{{ $seoSnippet->titleCharacters }}</strong> caracteres. Descrição: <strong id="seo-description-length">{{ $seoSnippet->descriptionCharacters }}</strong> caracteres.</p>
      <p class="sub">Identidade conferida: <code id="seo-artifact-digest">{{ $seoSnippet->artifactDigest }}</code></p>
      @php
        $seoAdvice=[
            'TITLE_SHORT'=>'Revisar título curto',
            'TITLE_MAY_TRUNCATE'=>'Título poderá aparecer truncado',
            'DESCRIPTION_SHORT'=>'Revisar descrição curta',
            'DESCRIPTION_MAY_TRUNCATE'=>'Descrição poderá aparecer truncada',
        ];
      @endphp
      @if($seoSnippet->advisories !== [])
      <ul class="seo-snippet-advisories" aria-label="Observações editoriais de título e descrição">
        @foreach($seoSnippet->advisories as $code)
        <li>{{ $seoAdvice[$code] ?? 'Revisão editorial necessária' }}</li>
        @endforeach
      </ul>
      @else
      <p class="sub" id="seo-no-advisories">Sem alertas automáticos de tamanho; revisão humana ainda obrigatória.</p>
      @endif
      <p class="locked">Esta página permanece noindex e não tem URL pública. A prévia não é previsão de posição no Google, aceite de cliente ou autorização de publicação.</p>
    </section>
    @endif
    @if(($visualSocialChoice ?? null) !== null)
    <section class="card" aria-labelledby="composite-social-title">
      <h2 id="composite-social-title">Visual completo e compartilhamento</h2>
      <p class="sub">Escolha a composição fictícia. Cada mudança tem HTML e SHA-256 próprios; nenhuma aprovação anterior é transferida.</p>
      <p class="sub">Design {{ $visualSocialChoice['changed'] ? 'modificado e sujeito a nova revisão' : 'original, sem aceite fictício' }}.</p>
      @php
        $visualSocialBase = $reviewRoute.'?product='.$product.'&device='.$device.'&preset='.$preset;
      @endphp
      <p class="sub">Ordem das seções</p>
      <nav class="tabs" aria-label="Ordem de seções e compartilhamento">
        @foreach([
          'services_process_faq'=>'Serviços / Etapas / FAQ',
          'services_faq_process'=>'Serviços / FAQ / Etapas',
          'process_services_faq'=>'Etapas / Serviços / FAQ',
          'process_faq_services'=>'Etapas / FAQ / Serviços',
          'faq_services_process'=>'FAQ / Serviços / Etapas',
          'faq_process_services'=>'FAQ / Etapas / Serviços'
        ] as $option=>$label)
          <a href="{{ $visualSocialBase }}&amp;order={{ $option }}&amp;nav={{ $visualSocialChoice['nav'] }}&amp;palette={{ $visualSocialChoice['palette'] }}&amp;social={{ $visualSocialChoice['social'] }}" @if($visualSocialChoice['order']===$option) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
      </nav>
      <p class="sub">Menu interno</p>
      @if($product==='onepage')
      <nav class="tabs" aria-label="Menu do site e compartilhamento">
        @foreach(['off'=>'Desativado','on'=>'Ativado'] as $option=>$label)
          <a href="{{ $visualSocialBase }}&amp;order={{ $visualSocialChoice['order'] }}&amp;nav={{ $option }}&amp;palette={{ $visualSocialChoice['palette'] }}&amp;social={{ $visualSocialChoice['social'] }}" @if($visualSocialChoice['nav']===$option) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
      </nav>
      @else
      <p class="sub">A Landing Page não permite menu interno adicional.</p>
      @endif
      <p class="sub">Cores da marca</p>
      <nav class="tabs" aria-label="Cores e compartilhamento">
        @foreach(['default'=>'Padrão','ocean'=>'Oceano','plum'=>'Ameixa','forest'=>'Floresta'] as $option=>$label)
          <a href="{{ $visualSocialBase }}&amp;order={{ $visualSocialChoice['order'] }}&amp;nav={{ $visualSocialChoice['nav'] }}&amp;palette={{ $option }}&amp;social={{ $visualSocialChoice['social'] }}" @if($visualSocialChoice['palette']===$option) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
      </nav>
      <p class="sub">Metadados de compartilhamento</p>
      <nav class="tabs" aria-label="Cartão social da composição">
        @foreach(['off'=>'Desativados','on'=>'Ativados'] as $option=>$label)
          <a href="{{ $visualSocialBase }}&amp;order={{ $visualSocialChoice['order'] }}&amp;nav={{ $visualSocialChoice['nav'] }}&amp;palette={{ $visualSocialChoice['palette'] }}&amp;social={{ $option }}" @if($visualSocialChoice['social']===$option) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
      </nav>
      <dl>
        <div><dt>SHA-256 da versão original</dt><dd><code id="composite-social-original-digest">{{ $visualSocialChoice['originalDigest'] }}</code></dd></div>
        <div><dt>SHA-256 da prévia selecionada</dt><dd><code id="composite-social-digest">{{ $digest }}</code></dd></div>
      </dl>
      @if(($socialCard ?? null) !== null)
      <article class="social-card-preview" aria-labelledby="composite-card-title">
        <p class="social-card-kicker">Cartão social — somente texto e sem rede</p>
        <h3 id="composite-card-title">{{ $socialCard->title }}</h3>
        <p id="composite-card-description">{{ $socialCard->description }}</p>
        <p class="social-card-origin">{{ $socialCard->brand }} · sem URL pública ou imagem</p>
        <p class="sub">Inspecionado a partir dos bytes canônicos do mesmo pacote privado.</p>
      </article>
      @else
      <p class="sub" id="composite-social-card-disabled">A composição não possui cartão social.</p>
      @endif
      <p class="locked">Prévia de laboratório, pendente de revisão. Sem login real, cliente, postagem, coleta ou publicação.</p>
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
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device=desktop{{ $galleryMode ? '' : '&preset='.$preset }}{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.$anchorNavMode }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}{{ ($visualSocialChoice ?? null) === null ? '' : '&order='.$visualSocialChoice['order'].'&nav='.$visualSocialChoice['nav'].'&palette='.$visualSocialChoice['palette'].'&social='.$visualSocialChoice['social'] }}{{ ($seoCopySelection ?? null) === null ? '' : '&seo='.$seoCopySelection['mode'].'&social='.$seoCopySelection['social'] }}{{ ($ctaMode ?? null) === null ? '' : '&cta='.$ctaMode }}" @if($device==='desktop') aria-current="page" @endif>Desktop</a>
        <a href="{{ $reviewRoute ?? '/_lab/sites/v1/review' }}?product={{ $product }}&amp;device=mobile{{ $galleryMode ? '' : '&preset='.$preset }}{{ ($richVariant ?? null) === null ? '' : '&variant='.$richVariant }}{{ ($sectionOrderCode ?? null) === null ? '' : '&order='.$sectionOrderCode }}{{ ($anchorNavMode ?? null) === null ? '' : '&nav='.$anchorNavMode }}{{ ($brandPaletteCode ?? null) === null ? '' : '&palette='.$brandPaletteCode }}{{ ($socialPreviewMode ?? null) === null ? '' : '&social='.$socialPreviewMode }}{{ ($visualSocialChoice ?? null) === null ? '' : '&order='.$visualSocialChoice['order'].'&nav='.$visualSocialChoice['nav'].'&palette='.$visualSocialChoice['palette'].'&social='.$visualSocialChoice['social'] }}{{ ($seoCopySelection ?? null) === null ? '' : '&seo='.$seoCopySelection['mode'].'&social='.$seoCopySelection['social'] }}{{ ($ctaMode ?? null) === null ? '' : '&cta='.$ctaMode }}" @if($device==='mobile') aria-current="page" @endif>Mobile</a>
      </nav>
    </div>
    <div class="workspace">
      <a class="skip-preview" href="#apos-previa">Pular a prévia incorporada</a>
      <div class="browser {{ $device }}">
      <div class="chrome" aria-hidden="true"><i></i><i></i><i></i><span>Prévia privada · sem domínio público</span></div>
      <iframe title="Prévia sintética do site {{ $product }}" sandbox="" tabindex="0" referrerpolicy="no-referrer" srcdoc="{{ $previewHtml }}"></iframe>
    </div></div>
    <div class="foot" id="apos-previa" tabindex="-1">Sem captação, scripts, URLs de clientes ou publicação. <a href="#detalhes">Ver identidade da versão</a></div>
  </section>
</div>
<p class="note">Somente para testes Laravel em processo CLI. O Portal é a autoridade exclusiva para contrato, aceite expresso do cliente e evento oficial ENTREGUE.</p>
</main>
</body>
</html>
