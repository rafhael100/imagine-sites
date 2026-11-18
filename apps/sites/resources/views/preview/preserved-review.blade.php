<section class="preserved-review" data-testid="preserved-review" data-review-revision="{{ $reviewSubmission['revision'] }}" data-review-digest="{{ $reviewSubmission['sha256'] }}" aria-labelledby="preserved-review-title">
<h2 id="preserved-review-title">Parecer não registrado</h2>
<p>Este envio continua ligado à versão indicada abaixo. A orientação não será reaplicada a outra versão.</p>
<dl>
<dt>Versão do envio</dt><dd>{{ $reviewSubmission['revision'] }}</dd>
<dt>SHA-256 do envio</dt><dd><code>{{ $reviewSubmission['sha256'] !== '' ? $reviewSubmission['sha256'] : 'Não informado ou inválido.' }}</code></dd>
<dt>Parecer do envio</dt><dd>{{ ['changes_requested'=>'Solicitar ajustes','approved_internal'=>'Aprovado internamente'][$reviewSubmission['decision']] ?? ($reviewSubmission['decision'] !== '' ? $reviewSubmission['decision'] : 'Não informado ou inválido.') }}</dd>
<dt>Orientação do envio</dt><dd class="review-note" data-review-note>{{ $reviewSubmission['note'] }}</dd>
</dl>
<p class="muted">O texto está preservado somente nesta resposta. Copie a orientação antes de sair se precisar guardá-la.</p>
</section>
