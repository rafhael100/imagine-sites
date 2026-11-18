<section class="comparison" aria-labelledby="comparison-title">
<h2 id="comparison-title">Comparar versões</h2>
<p class="muted">Confira o que mudou no briefing entre duas versões deste projeto, inclusive fora da página do histórico. @if($hasUnsavedBrief)O briefing ainda não salvo foi mantido abaixo. Você pode consultar os dados salvos da versão {{ $currentRevision }} junto dele.@elseif($revisionLimitReached || !$canAppendVersion)O briefing salvo da versão atual {{ $currentRevision }} continua disponível para consulta.@else A comparação mantém o formulário de edição na versão atual {{ $currentRevision }}.@endif</p>
@php
    $comparisonInput = $comparisonInput ?? null;
@endphp
@if($comparisonInput !== null)
<p class="field-error" id="comparison-error" data-testid="comparison-error" role="alert">{{ $comparisonInput->message }}</p>
@endif
@if($currentRevision > 1 || $comparisonInput !== null)
<form class="comparison-form" data-testid="comparison-form" method="get" action="{{ $navigation->projectUrl($project) }}">
@foreach($navigation->comparisonFormParameters() as $name => $value)
<input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
<div>
<label for="compare-from">Versão anterior</label>
<input id="compare-from" name="compare_from" type="number" inputmode="numeric" min="1" max="{{ $currentRevision }}" step="1" required value="{{ $comparisonInput !== null ? $comparisonInput->from : ($navigation->compareFrom ?? $currentRevision - 1) }}" aria-describedby="{{ $comparisonInput !== null ? 'comparison-help comparison-error' : 'comparison-help' }}" aria-invalid="{{ $comparisonInput !== null ? 'true' : 'false' }}">
</div>
<div>
<label for="compare-to">Versão posterior</label>
<input id="compare-to" name="compare_to" type="number" inputmode="numeric" min="1" max="{{ $currentRevision }}" step="1" required value="{{ $comparisonInput !== null ? $comparisonInput->to : ($navigation->compareTo ?? $currentRevision) }}" aria-describedby="{{ $comparisonInput !== null ? 'comparison-help comparison-error' : 'comparison-help' }}" aria-invalid="{{ $comparisonInput !== null ? 'true' : 'false' }}">
</div>
<button type="submit">Comparar versões</button>
</form>
<p id="comparison-help" class="muted">Informe dois números entre 1 e {{ $currentRevision }}. A versão anterior deve ter um número menor que a posterior.</p>
@if($currentRevision > 1)
<p><a href="{{ $navigation->comparisonUrl($project,$currentRevision-1,$currentRevision) }}">Comparar a versão atual com a anterior</a></p>
@endif
@endif
@if($comparisonInput !== null)
<p><a href="{{ $navigation->closeComparisonUrl($project) }}">Limpar comparação</a></p>
@endif
@if($currentRevision <= 1)
<p class="history-note">Este projeto tem uma versão. A comparação estará disponível quando uma nova versão for criada.</p>
@endif
@if($comparison !== null)
@php
    $comparisonFields = ['productCode'=>'Formato','brand'=>'Marca fictícia','headline'=>'Chamada principal','description'=>'Descrição','callToAction'=>'Chamada do botão','benefits'=>'Benefícios'];
    $changedLabels = array_values(array_intersect_key($comparisonFields, array_flip($comparison['changed_fields'])));
@endphp
<div data-testid="editorial-comparison" aria-labelledby="comparison-result-title">
<h3 id="comparison-result-title">Da versão {{ $comparison['from']['revision'] }} à versão {{ $comparison['to']['revision'] }}</h3>
@if($changedLabels)
<p class="comparison-summary"><strong>Campos alterados:</strong> {{ implode(', ', $changedLabels) }}.</p>
@else
<p class="comparison-summary">Nenhum campo do briefing mudou entre estas versões.</p>
@endif
<div class="comparison-grid">
@foreach(['from'=>'Antes','to'=>'Depois'] as $side => $sideLabel)
@php($comparedVersion = $comparison[$side])
<article class="comparison-side" data-compare-side="{{ $side }}" data-compare-revision="{{ $comparedVersion['revision'] }}" aria-labelledby="comparison-{{ $side }}-title">
<h3 id="comparison-{{ $side }}-title">{{ $sideLabel }} · versão {{ $comparedVersion['revision'] }}</h3>
<p class="muted">{{ $formatDate($comparedVersion['created_at']) }} · Brasília</p>
<code>SHA-256: {{ $comparedVersion['sha256'] }}</code>
<dl>
@foreach($comparisonFields as $field => $label)
@php($fieldChanged = in_array($field, $comparison['changed_fields'], true))
<dt>{{ $label }} @if($fieldChanged)<span class="comparison-change">Alterado</span>@endif</dt>
@if($field === 'benefits')
<dd class="comparison-value" data-field="{{ $field }}" data-field-changed="{{ $fieldChanged ? 'true' : 'false' }}"><ol>
@foreach($comparedVersion['brief']['benefits'] as $benefit)
<li>{{ $benefit }}</li>
@endforeach
</ol></dd>
@else
<dd class="comparison-value" data-field="{{ $field }}" data-field-changed="{{ $fieldChanged ? 'true' : 'false' }}">{{ $field === 'productCode' ? ($comparedVersion['brief'][$field] === 'onepage' ? 'Site Onepage' : 'Landing Page') : $comparedVersion['brief'][$field] }}</dd>
@endif
@endforeach
</dl>
</article>
@endforeach
</div>
<p><a href="{{ $navigation->closeComparisonUrl($project) }}">Fechar comparação</a></p>
</div>
@endif
</section>
