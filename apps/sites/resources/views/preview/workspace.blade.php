<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Imagine · Prévia privada</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f6f3;color:#242424;font:16px/1.6 system-ui,sans-serif}header,main{max-width:1440px;margin:auto;padding:28px clamp(18px,4vw,56px)}header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #ddd}a{color:#9b4c00;text-underline-offset:4px}h1{font-size:clamp(30px,4vw,52px);line-height:1.15;margin:12px 0}h2{font-size:23px}strong.brand{font-size:30px;color:#303030}.brand span{color:#f9941d}.eyebrow{font-size:12px;letter-spacing:2px;font-weight:800;color:#945000}p.note{padding:16px 20px;border-left:4px solid #f9941d;background:#fff0db;border-radius:8px}.layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:24px;margin-top:30px}section{background:white;border:1px solid #e4ddd2;border-radius:20px;padding:26px;min-width:0}label,dt{display:block;font-weight:650;margin-top:16px}dd{margin:4px 0;white-space:pre-wrap;overflow-wrap:anywhere}input,select,textarea{font:inherit;width:100%;padding:11px;border:1px solid #aaa;border-radius:8px;background:#fff}textarea{min-height:100px}button,.button{display:inline-block;background:#f9941d;border:1px solid #c4770b;color:#232323;border-radius:9px;padding:13px 20px;font:700 15px system-ui;cursor:pointer;text-decoration:none;margin-top:16px}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:3px solid #9b4c00;outline-offset:3px}.item{padding:16px 0;border-bottom:1px solid #eee}code{display:block;font-size:12px;overflow-wrap:anywhere}.muted{color:#656565;font-size:14px}.review{border-top:1px solid #ddd;margin-top:18px}.status{display:inline-block;border-radius:20px;padding:3px 12px;font-size:13px;font-weight:700;background:#f1f0ec}.status-approved_internal{background:#e5f4ea;color:#205536}.status-changes_requested{background:#fff0db;color:#794100}.errors{background:#fff4f2;border:1px solid #c04835;border-radius:12px;padding:18px 24px;margin:24px 0}.errors h2{font-size:19px;margin:0}.errors ul{margin-bottom:0}.field-error{color:#9b291b;font-size:14px;margin:5px 0}[aria-invalid=true]{border-color:#b53c29}select:disabled{background:#f3f2ee;color:#444}footer{padding:25px;text-align:center;color:#656565}@media(max-width:850px){.layout{grid-template-columns:1fr}header{gap:20px;align-items:start}section{padding:20px}}
</style>
<style>
.queue-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin:18px 0}.queue-filters>div{flex:1 1 220px;min-width:0}.queue-filters>.queue-search{flex-basis:100%}.queue-filters label{margin-top:0}.queue-filters button{margin-top:0}.queue-search-help{margin:4px 0 0}.queue-empty{overflow-wrap:anywhere}.pager{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:20px 0}.pager a{padding:8px 0}.item a,.item p{overflow-wrap:anywhere}.item .project-title{font-size:19px;font-weight:750}.history-note{padding:12px 16px;background:#f3f2ee;border-radius:8px}
.comparison{margin-top:30px}.comparison h2{margin-top:0}.comparison-form{display:flex;flex-wrap:wrap;gap:16px;align-items:end;margin:20px 0}.comparison-form>div{flex:1 1 180px;min-width:0}.comparison-form label{margin-top:0}.comparison-form button{margin-top:0}.comparison-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:20px}.comparison-side{min-width:0;border:1px solid #ded8ce;border-radius:12px;padding:20px}.comparison-side h3{margin-top:0}.comparison-side dl{margin-bottom:0}.comparison-side code{white-space:normal}.comparison-value{min-width:0;overflow-wrap:anywhere}.comparison-value[data-field-changed=true]{border-left:3px solid #c4770b;padding:8px 12px;background:#fff8ee}.comparison-value ol{margin:0;padding-left:22px;white-space:normal}.comparison-value li{white-space:pre-wrap;overflow-wrap:anywhere}.comparison-change{display:inline-block;padding:1px 8px;margin-left:6px;border-radius:12px;background:#fff0db;color:#794100;font-size:12px;font-weight:700}.comparison-summary{padding:12px 16px;border-radius:8px;background:#f3f2ee;overflow-wrap:anywhere}@media(max-width:700px){.comparison-grid{grid-template-columns:1fr}.comparison-form>div{flex-basis:100%}.comparison-side{padding:16px}}
.editorial-guidance,.preserved-review{margin-top:24px}.editorial-guidance h2,.preserved-review h2{margin-top:0}.editorial-review{border-top:1px solid #e4ddd2;padding:14px 0;min-width:0}.editorial-review p{margin:8px 0}.review-note{white-space:pre-wrap;overflow-wrap:anywhere;min-width:0}.review textarea{white-space:pre-wrap;overflow-wrap:anywhere}.preserved-review p,.errors li,.review .muted,.editorial-guidance p{overflow-wrap:anywhere}
</style>
</head>
<body>
@php
    $formatDate = static fn (string $value): string => \Carbon\CarbonImmutable::parse($value)->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i');
    $queueSearchInput = $queueSearchInput ?? null;
@endphp
<header><strong class="brand">imagine<span>.</span></strong><a href="{{ $navigation->queueUrl() }}">← Voltar à fila</a></header>
<main>
<span class="eyebrow">IMAGINE SITES · AMBIENTE PRIVADO</span>
<h1>Da ideia à sua próxima versão.</h1>
<p>Crie uma demonstração, confira o conteúdo e acompanhe a revisão editorial.</p>
<p class="note"><strong>DEMONSTRAÇÃO PRIVADA</strong> — Use apenas dados fictícios. Este workspace não cria contratação, pagamento, aceite do cliente ou autorização de publicação.</p>
@if($fieldErrors)
<div class="errors" role="alert" aria-labelledby="errors-title">
<h2 id="errors-title">{{ isset($fieldErrors['expected_revision']) ? 'Confira a versão atual' : (isset($fieldErrors['review']) ? 'Confira o parecer desta versão' : 'Revise os campos indicados') }}</h2>
<ul>
@foreach($fieldErrors as $messages)
@foreach($messages as $message)
<li>{{ $message }}</li>
@endforeach
@endforeach
</ul>
</div>
@endif
@if($project)
@include('preview.comparison')
@include('preview.current-editorial-guidance')
@endif
@if($reviewSubmission !== null && !$reviewSubmission['canRetry'])
@include('preview.preserved-review')
@endif
<div class="layout">
<section>
<h2>{{ $project ? ($canEdit ? 'Revisar briefing' : 'Briefing da versão atual') : ($canEdit ? 'Novo projeto demonstrativo' : 'Seu acesso ao workspace') }}</h2>
@if($canEdit)
<p class="muted">Cada geração preserva a versão anterior. Os campos aceitam somente texto.</p>
@if($project)<p class="history-note">Este briefing corresponde à versão atual {{ $currentRevision }}.</p>@endif
<form method="post" action="{{ $project ? $navigation->projectUrl($project,$navigation->beforeRevision,$navigation->afterRevision) : $navigation->createUrl() }}">
@csrf
@if($project)<input type="hidden" name="expected_revision" value="{{ $currentRevision }}"><input type="hidden" name="product" value="{{ $form['product'] }}">@endif
<label for="product">Formato</label>
<select id="product" @if(!$project)name="product"@endif @disabled($project !== null)>
<option value="onepage" @selected($form['product']==='onepage')>Site Onepage</option>
<option value="landing_page" @selected($form['product']==='landing_page')>Landing Page</option>
</select>
@if($project)<p class="muted">O formato é definido na criação do projeto.</p>@endif
@foreach(['brand'=>['Marca fictícia',120], 'headline'=>['Chamada principal',180], 'description'=>['Descrição',600], 'call_to_action'=>['Chamada do botão',80], 'benefits'=>['Benefícios (um por linha, até oito)',1500]] as $field => [$label,$max])
<label for="{{ $field }}">{{ $label }}</label>
{{-- Native maxlength counts UTF-16 units, while briefing limits count Unicode code points. --}}
@if(in_array($field,['description','benefits'],true))
<textarea id="{{ $field }}" name="{{ $field }}" required aria-describedby="help-{{ $field }}@if(isset($fieldErrors[$field])) error-{{ $field }}@endif" @if(isset($fieldErrors[$field]))aria-invalid="true"@endif>{{ $form[$field] }}</textarea>
@else
<input id="{{ $field }}" name="{{ $field }}" required value="{{ $form[$field] }}" aria-describedby="help-{{ $field }}@if(isset($fieldErrors[$field])) error-{{ $field }}@endif" @if(isset($fieldErrors[$field]))aria-invalid="true"@endif>
@endif
<p class="muted" id="help-{{ $field }}">@if($field==='benefits')Até 180 caracteres por benefício e {{ $max }} no campo.@else Até {{ $max }} caracteres.@endif</p>
@if(isset($fieldErrors[$field]))<p class="field-error" id="error-{{ $field }}">{{ $fieldErrors[$field][0] }}</p>@endif
@endforeach
<p class="muted">Os limites são conferidos ao enviar. Emojis simples contam como um caractere; acentos combinados e emojis compostos podem contar como mais de um.</p>
<button type="submit">{{ $project ? 'Gerar nova versão privada →' : 'Criar demonstração →' }}</button>
</form>
@elseif($project)
<dl>
<dt>Formato</dt><dd>{{ $form['product']==='onepage' ? 'Site Onepage' : 'Landing Page' }}</dd>
@foreach(['brand'=>'Marca fictícia','headline'=>'Chamada principal','description'=>'Descrição','call_to_action'=>'Chamada do botão','benefits'=>'Benefícios'] as $field=>$label)
<dt>{{ $label }}</dt><dd>{{ $form[$field] }}</dd>
@endforeach
</dl>
<p class="muted">A geração de versões está disponível ao responsável pela edição.</p>
@else
<p>{{ $canReview ? 'Abra um projeto na fila para conferir a prévia e registrar seu parecer editorial.' : 'Abra um projeto para consultar o briefing, as versões e o histórico de revisão.' }}</p>
@endif
</section>
<section @if(!$project)data-testid="preview-queue"@endif>
<h2>{{ $project ? 'Versões e revisão editorial' : 'Fila de revisão editorial' }}</h2>
@php($statusLabels=['awaiting_review'=>'Aguardando revisão','changes_requested'=>'Ajustes solicitados','approved_internal'=>'Aprovada internamente'])
@if(!$project)
<p class="muted">O parecer exibido corresponde à versão atual de cada projeto.</p>
<form class="queue-filters" data-testid="queue-filters" method="get" action="/_preview">
<div class="queue-search"><label for="queue-search">Buscar projeto</label>
<input type="search" id="queue-search" name="q" value="{{ $queueSearchInput?->search ?? $navigation->queue->search }}" placeholder="Marca ou chamada principal" aria-describedby="queue-search-help{{ $queueSearchInput !== null ? ' queue-search-error' : '' }}" aria-invalid="{{ $queueSearchInput !== null ? 'true' : 'false' }}">
<p class="muted queue-search-help" id="queue-search-help">Marca ou chamada principal da versão atual. Até 100 caracteres.</p>
@if($queueSearchInput !== null)<p class="field-error" id="queue-search-error" data-testid="queue-search-error" role="alert">{{ $queueSearchInput->message }}</p>@endif
</div>
<div><label for="queue-status">Parecer na fila</label><select id="queue-status" name="status">
<option value="all" @selected($navigation->queue->status==='all')>Todos os pareceres</option>
@foreach($statusLabels as $value=>$label)
<option value="{{ $value }}" @selected($navigation->queue->status===$value)>{{ $label }}</option>
@endforeach
</select></div>
<div><label for="queue-format">Formato da fila</label><select id="queue-format" name="format">
<option value="all" @selected($navigation->queue->product==='all')>Todos os formatos</option>
<option value="onepage" @selected($navigation->queue->product==='onepage')>Site Onepage</option>
<option value="landing_page" @selected($navigation->queue->product==='landing_page')>Landing Page</option>
</select></div>
<button type="submit">Aplicar filtros</button>
@if($queueSearchInput !== null || $navigation->queue->search !== '')<a href="{{ $navigation->queueUrlWithoutSearch() }}">Limpar busca</a>@endif
<a href="/_preview">Limpar filtros</a>
</form>
@if($queueSearchInput === null)
@forelse($projects as $p)
<div class="item" data-project="{{ $p['id'] }}">
<a class="project-title" href="{{ $navigation->projectUrl($p['id']) }}">{{ $p['brand'] }}</a>
<p>{{ $p['headline'] }}</p>
<p class="muted">{{ $p['product']==='onepage' ? 'Site Onepage' : 'Landing Page' }}</p>
<p><strong>Versão {{ $p['latest_revision'] }}</strong> · <span class="status status-{{ $p['editorial_status'] }}">{{ $statusLabels[$p['editorial_status']] }}</span></p>
<p class="muted">{{ $formatDate($p['latest_created_at']) }} · Brasília</p><code>{{ $p['id'] }}</code>
</div>
@empty
<p class="queue-empty" data-testid="queue-empty">@if($navigation->queue->search !== '')Nenhum projeto encontrado para “{{ $navigation->queue->search }}” nesta página com os filtros escolhidos.@else Nenhum projeto encontrado nesta página com os filtros escolhidos.@endif</p>
@endforelse
<nav class="pager" aria-label="Páginas da fila editorial">
@if($navigation->queue->cursor !== null)<a href="{{ $navigation->queueUrlFor(null) }}">Voltar ao início da fila</a>@endif
@if($queueNext !== null)<a href="{{ $navigation->queueUrlFor($queueNext) }}">Próximos projetos →</a>@endif
</nav>
@endif
@else
<p class="muted">Versão atual: {{ $currentRevision }}. Datas no horário de Brasília.</p>
@if($navigation->beforeRevision !== null || $navigation->afterRevision !== null)
<p class="history-note">O histórico pode mostrar versões anteriores. O briefing de edição continua na versão atual.</p>
@endif
@forelse($versions as $v)
<div class="item" data-version="{{ $v['revision'] }}">
<strong>Versão {{ $v['revision'] }}</strong>@if($v['revision']===$currentRevision) <span class="status">Atual</span>@endif
<p class="muted">{{ $formatDate($v['created_at']) }}</p><code>SHA-256: {{ $v['sha256'] }}</code>
<a class="button" href="/_preview/projects/{{ $project }}/versions/{{ $v['revision'] }}/html" target="_blank" rel="noopener">Visualizar prévia privada ↗</a>
@if($v['revision'] > 1)
<p><a href="{{ $navigation->comparisonUrl($project,$v['revision']-1,$v['revision']) }}">Comparar com a anterior</a></p>
@endif
@if($canReview && $v['reviewable'] && !($reviewSubmission !== null && !$reviewSubmission['canRetry'] && $reviewSubmission['revision']===$v['revision'] && $reviewSubmission['sha256']===$v['sha256']))
@include('preview.review-form')
@endif
</div>
@empty<p>Nenhuma versão nesta página.</p>@endforelse
<nav class="pager" aria-label="Páginas do histórico de versões">
@if($historyNewer !== null)<a href="{{ $navigation->historyUrl($project,null,$historyNewer) }}">← Versões mais recentes</a>@endif
@if($historyOlder !== null)<a href="{{ $navigation->historyUrl($project,$historyOlder) }}">Versões anteriores →</a>@endif
@if($navigation->beforeRevision !== null || $navigation->afterRevision !== null)<a href="{{ $navigation->historyUrl($project) }}">Ir à versão atual</a>@endif
</nav>
<p class="muted">A revisão exige outra pessoa autorizada. Não representa o aceite do cliente nem libera publicação.</p>
<div data-testid="editorial-review-history">
<h3>Pareceres das versões desta página</h3>
@forelse($reviews as $r)
<article class="editorial-review" data-review-revision="{{ $r['revision'] }}" data-review-digest="{{ $r['sha256'] }}">
<p>Versão {{ $r['revision'] }}: {{ $r['decision']==='approved_internal' ? 'Aprovada internamente' : 'Ajustes solicitados' }} · {{ $formatDate($r['created_at']) }}</p>
<code>SHA-256: {{ $r['sha256'] }}</code>
@if($r['note'] !== null)<p class="review-note" data-review-note>{{ $r['note'] }}</p>@else<p class="muted">Este parecer foi registrado sem observação.</p>@endif
</article>
@empty<p>Nenhuma revisão editorial registrada.</p>@endforelse
@if($reviewsTruncated)<p class="muted">Mostrando os 100 pareceres mais recentes das versões desta página.</p>@endif
</div>
@endif
</section>
</div>
</main>
<footer>Imagine Comunicação Digital · Experiências que conectam.</footer>
</body>
</html>
