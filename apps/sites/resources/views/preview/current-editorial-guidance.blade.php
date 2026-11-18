<section class="editorial-guidance" data-testid="current-editorial-guidance" data-review-revision="{{ $currentRevision }}" data-review-digest="{{ $currentSha256 }}" aria-labelledby="current-guidance-title">
<h2 id="current-guidance-title">Orientações da versão atual</h2>
<p class="muted">Estes pareceres correspondem à versão {{ $currentRevision }}, mesmo quando o histórico ou a comparação mostram versões anteriores.</p>
<code>SHA-256: {{ $currentSha256 }}</code>
@forelse($currentReviews as $r)
<article class="editorial-review" data-review-revision="{{ $r['revision'] }}" data-review-digest="{{ $r['sha256'] }}">
<p><strong>{{ $r['decision']==='approved_internal' ? 'Aprovada internamente' : 'Ajustes solicitados' }}</strong> · {{ $formatDate($r['created_at']) }} · Brasília</p>
@if($r['note'] !== null)
<p class="review-note" data-review-note>{{ $r['note'] }}</p>
@else
<p class="muted">Este parecer foi registrado sem observação.</p>
@endif
</article>
@empty<p>A versão atual ainda não recebeu parecer editorial.</p>@endforelse
@if($currentReviewsTruncated)<p class="muted">Mostrando os 100 pareceres mais recentes da versão atual.</p>@endif
</section>
