@php
    $preservedReview = $reviewSubmission !== null && $reviewSubmission['canRetry'] &&
        $reviewSubmission['revision'] === $v['revision'] && $reviewSubmission['sha256'] === $v['sha256'];
    $reviewDecision = $preservedReview ? $reviewSubmission['decision'] : 'changes_requested';
    $reviewNote = $preservedReview ? $reviewSubmission['note'] : '';
    $noteError = $preservedReview && isset($fieldErrors['note']);
    $decisionError = $preservedReview && isset($fieldErrors['decision']);
@endphp
<form class="review" method="post" action="{{ $navigation->reviewUrl($project,$v['revision']) }}" data-review-revision="{{ $v['revision'] }}" data-review-digest="{{ $v['sha256'] }}" @if($preservedReview)data-testid="preserved-review"@endif>
@csrf<input type="hidden" name="sha256" value="{{ $v['sha256'] }}">
<label for="decision-{{ $v['revision'] }}">Parecer editorial desta versão</label>
<select id="decision-{{ $v['revision'] }}" name="decision" @if($decisionError)aria-invalid="true" aria-describedby="error-decision-{{ $v['revision'] }}"@endif>
@if(!in_array($reviewDecision,['changes_requested','approved_internal'],true))<option value="" selected disabled>Selecione o parecer</option>@endif
<option value="changes_requested" @selected($reviewDecision==='changes_requested')>Solicitar ajustes</option>
<option value="approved_internal" @selected($reviewDecision==='approved_internal')>Aprovado internamente</option>
</select>
@if($decisionError)<p class="field-error" id="error-decision-{{ $v['revision'] }}">{{ $fieldErrors['decision'][0] }}</p>@endif
<label for="note-{{ $v['revision'] }}">Orientação editorial</label>
{{-- A native maxlength counts UTF-16 units rather than the server's Unicode characters.
     The initial line break is intentionally discarded by HTML textarea parsing. --}}
<textarea id="note-{{ $v['revision'] }}" name="note" rows="5" aria-describedby="note-help-{{ $v['revision'] }}@if($noteError) error-note-{{ $v['revision'] }}@endif" @if($noteError)aria-invalid="true"@endif>
{{ $reviewNote }}</textarea>
<p class="muted" id="note-help-{{ $v['revision'] }}">Até 2000 caracteres. Obrigatória ao solicitar ajustes; opcional ao aprovar internamente. Somente texto, visível às pessoas autorizadas deste preview.</p>
@if($noteError)<p class="field-error" id="error-note-{{ $v['revision'] }}">{{ $fieldErrors['note'][0] }}</p>@endif
@if($preservedReview)<p class="muted">O envio não foi registrado. A orientação foi mantida para esta versão {{ $v['revision'] }}.</p>@endif
<button type="submit">Registrar revisão editorial</button>
</form>
