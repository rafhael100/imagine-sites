<section class="version-reviews" id="version-reviews" data-testid="version-reviews-panel" data-review-revision="{{ $versionReviews['revision'] }}" data-review-digest="{{ $versionReviews['sha256'] }}" aria-labelledby="version-reviews-title">
<h2 id="version-reviews-title">Pareceres da versão {{ $versionReviews['revision'] }}</h2>
<p class="muted">Estes pareceres correspondem à versão {{ $versionReviews['revision'] }} e ao conteúdo identificado abaixo. A versão atual do projeto é {{ $currentRevision }}.</p>
<code>SHA-256: {{ $versionReviews['sha256'] }}</code>
<p class="muted">Mais recentes primeiro. Até {{ \Imagine\Sites\Production\PreviewReviewQuery::PAGE_SIZE }} pareceres por página.</p>
<div data-testid="version-reviews-list">
@forelse($versionReviews['reviews'] as $r)
<article class="editorial-review" data-testid="version-review-item" data-review-revision="{{ $r['revision'] }}" data-review-digest="{{ $r['sha256'] }}">
<p><strong>{{ $r['decision']==='approved_internal' ? 'Aprovada internamente' : 'Ajustes solicitados' }}</strong> · {{ $formatDate($r['created_at']) }} · Brasília</p>
@if($r['note'] !== null)
<p class="review-note" data-review-note>{{ $r['note'] }}</p>
@else
<p class="muted" data-testid="version-review-no-note">Este parecer foi registrado sem observação.</p>
@endif
</article>
@empty
<p data-testid="version-reviews-empty">@if($navigation->reviews?->cursor !== null)Nenhum parecer nesta página. Volte aos pareceres mais recentes.@else Esta versão ainda não recebeu parecer editorial.@endif</p>
@endforelse
</div>
<nav class="pager" data-testid="version-reviews-pager" aria-label="Páginas dos pareceres da versão {{ $versionReviews['revision'] }}">
@if($navigation->reviews?->cursor !== null)<a href="{{ $navigation->versionReviewsUrl($project,$versionReviews['revision']) }}">Voltar aos pareceres mais recentes</a>@endif
@if($versionReviews['next_cursor'] !== null)<a href="{{ $navigation->versionReviewsUrl($project,$versionReviews['revision'],$versionReviews['next_cursor']) }}">Pareceres anteriores →</a>@endif
<a href="{{ $navigation->closeVersionReviewsUrl($project) }}">Fechar consulta de pareceres</a>
</nav>
</section>
