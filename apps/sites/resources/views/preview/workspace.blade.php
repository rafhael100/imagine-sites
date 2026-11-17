<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Imagine · Prévia privada</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f6f3;color:#242424;font:16px/1.6 system-ui,sans-serif}header,main{max-width:1440px;margin:auto;padding:28px clamp(18px,4vw,56px)}header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #ddd}a{color:#9b4c00;text-underline-offset:4px}h1{font-size:clamp(30px,4vw,52px);line-height:1.15;margin:12px 0}h2{font-size:23px}strong.brand{font-size:30px;color:#303030}.brand span{color:#f9941d}.eyebrow{font-size:12px;letter-spacing:2px;font-weight:800;color:#945000}p.note{padding:16px 20px;border-left:4px solid #f9941d;background:#fff0db;border-radius:8px}.layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:24px;margin-top:30px}section{background:white;border:1px solid #e4ddd2;border-radius:20px;padding:26px;min-width:0}label,dt{display:block;font-weight:650;margin-top:16px}dd{margin:4px 0;white-space:pre-wrap;overflow-wrap:anywhere}input,select,textarea{font:inherit;width:100%;padding:11px;border:1px solid #aaa;border-radius:8px;background:#fff}textarea{min-height:100px}button,.button{display:inline-block;background:#f9941d;border:1px solid #c4770b;color:#232323;border-radius:9px;padding:13px 20px;font:700 15px system-ui;cursor:pointer;text-decoration:none;margin-top:16px}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:3px solid #9b4c00;outline-offset:3px}.item{padding:16px 0;border-bottom:1px solid #eee}code{display:block;font-size:12px;overflow-wrap:anywhere}.muted{color:#656565;font-size:14px}.review{border-top:1px solid #ddd;margin-top:18px}.status{display:inline-block;border-radius:20px;padding:3px 12px;font-size:13px;font-weight:700;background:#f1f0ec}.status-approved_internal{background:#e5f4ea;color:#205536}.status-changes_requested{background:#fff0db;color:#794100}.errors{background:#fff4f2;border:1px solid #c04835;border-radius:12px;padding:18px 24px;margin:24px 0}.errors h2{font-size:19px;margin:0}.errors ul{margin-bottom:0}.field-error{color:#9b291b;font-size:14px;margin:5px 0}[aria-invalid=true]{border-color:#b53c29}select:disabled{background:#f3f2ee;color:#444}footer{padding:25px;text-align:center;color:#656565}@media(max-width:850px){.layout{grid-template-columns:1fr}header{gap:20px;align-items:start}section{padding:20px}}
</style>
</head>
<body>
<header><strong class="brand">imagine<span>.</span></strong><a href="/_preview">← Voltar ao workspace</a></header>
<main>
<span class="eyebrow">IMAGINE SITES · AMBIENTE PRIVADO</span>
<h1>Da ideia à sua próxima versão.</h1>
<p>Crie uma demonstração, confira o conteúdo e acompanhe a revisão editorial.</p>
<p class="note"><strong>DEMONSTRAÇÃO PRIVADA</strong> — Use apenas dados fictícios. Este workspace não cria contratação, pagamento, aceite do cliente ou autorização de publicação.</p>
@if($fieldErrors)
<div class="errors" role="alert" aria-labelledby="errors-title">
<h2 id="errors-title">{{ isset($fieldErrors['expected_revision']) ? 'Confira a versão atual' : 'Revise os campos indicados' }}</h2>
<ul>
@foreach($fieldErrors as $messages)
@foreach($messages as $message)
<li>{{ $message }}</li>
@endforeach
@endforeach
</ul>
</div>
@endif
<div class="layout">
<section>
<h2>{{ $project ? ($canEdit ? 'Revisar briefing' : 'Briefing da versão atual') : ($canEdit ? 'Novo projeto demonstrativo' : 'Seu acesso ao workspace') }}</h2>
@if($canEdit)
<p class="muted">Cada geração preserva a versão anterior. Os campos aceitam somente texto.</p>
<form method="post" action="{{ $project ? '/_preview/projects/'.$project : '/_preview/projects' }}">
@csrf
@if($project)<input type="hidden" name="expected_revision" value="{{ $versions[0]['revision'] }}"><input type="hidden" name="product" value="{{ $form['product'] }}">@endif
<label for="product">Formato</label>
<select id="product" @if(!$project)name="product"@endif @disabled($project !== null)>
<option value="onepage" @selected($form['product']==='onepage')>Site Onepage</option>
<option value="landing_page" @selected($form['product']==='landing_page')>Landing Page</option>
</select>
@if($project)<p class="muted">O formato é definido na criação do projeto.</p>@endif
@foreach(['brand'=>['Marca fictícia',120], 'headline'=>['Chamada principal',180], 'description'=>['Descrição',600], 'call_to_action'=>['Chamada do botão',80], 'benefits'=>['Benefícios (um por linha, até oito)',1500]] as $field => [$label,$max])
<label for="{{ $field }}">{{ $label }}</label>
@if(in_array($field,['description','benefits'],true))
<textarea id="{{ $field }}" name="{{ $field }}" required maxlength="{{ $max }}" @if(isset($fieldErrors[$field]))aria-invalid="true" aria-describedby="error-{{ $field }}"@endif>{{ $form[$field] }}</textarea>
@else
<input id="{{ $field }}" name="{{ $field }}" required maxlength="{{ $max }}" value="{{ $form[$field] }}" @if(isset($fieldErrors[$field]))aria-invalid="true" aria-describedby="error-{{ $field }}"@endif>
@endif
@if(isset($fieldErrors[$field]))<p class="field-error" id="error-{{ $field }}">{{ $fieldErrors[$field][0] }}</p>@endif
@endforeach
<p class="muted">Cada benefício aceita até 180 caracteres. Acentos contam como uma letra.</p>
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
<section>
<h2>{{ $project ? 'Versões e revisão editorial' : 'Fila de revisão editorial' }}</h2>
@php($statusLabels=['awaiting_review'=>'Aguardando revisão','changes_requested'=>'Ajustes solicitados','approved_internal'=>'Aprovada internamente'])
@if(!$project)
<p class="muted">O parecer exibido corresponde à versão atual de cada projeto.</p>
@forelse($projects as $p)
<div class="item" data-project="{{ $p['id'] }}">
<a href="/_preview/projects/{{ $p['id'] }}">{{ $p['product']==='onepage' ? 'Site Onepage' : 'Landing Page' }} · Abrir projeto</a>
<p><strong>Versão {{ $p['latest_revision'] }}</strong> · <span class="status status-{{ $p['editorial_status'] }}">{{ $statusLabels[$p['editorial_status']] }}</span></p>
<p class="muted">{{ $p['latest_created_at'] }}</p><code>{{ $p['id'] }}</code>
</div>
@empty<p>Nenhum projeto demonstrativo disponível para este acesso.</p>@endforelse
@else
@foreach($versions as $v)
<div class="item">
<strong>Versão {{ $v['revision'] }}</strong>@if($loop->first) <span class="status">Atual</span>@endif
<p class="muted">{{ $v['created_at'] }}</p><code>SHA-256: {{ $v['sha256'] }}</code>
<a class="button" href="/_preview/projects/{{ $project }}/versions/{{ $v['revision'] }}/html" target="_blank" rel="noopener">Visualizar prévia privada ↗</a>
@if($canReview && $v['reviewable'])
<form class="review" method="post" action="/_preview/projects/{{ $project }}/versions/{{ $v['revision'] }}/review">
@csrf<input type="hidden" name="sha256" value="{{ $v['sha256'] }}">
<label for="decision-{{ $v['revision'] }}">Parecer editorial desta versão</label>
<select id="decision-{{ $v['revision'] }}" name="decision"><option value="changes_requested">Solicitar ajustes</option><option value="approved_internal">Aprovado internamente</option></select>
<button type="submit">Registrar revisão editorial</button>
</form>
@endif
</div>
@endforeach
<p class="muted">A revisão exige outra pessoa autorizada. Não representa o aceite do cliente nem libera publicação.</p>
@forelse($reviews as $r)
<p>Versão {{ $r['revision'] }}: {{ $r['decision']==='approved_internal' ? 'Aprovada internamente' : 'Ajustes solicitados' }} · {{ $r['created_at'] }}</p>
@empty<p>Nenhuma revisão editorial registrada.</p>@endforelse
@endif
</section>
</div>
</main>
<footer>Imagine Comunicação Digital · Experiências que conectam.</footer>
</body>
</html>
