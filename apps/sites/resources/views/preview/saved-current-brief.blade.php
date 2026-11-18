<details class="saved-current-brief" data-testid="saved-current-brief" data-brief-revision="{{ $currentRevision }}" data-brief-digest="{{ $currentSha256 }}">
<summary>Consultar briefing salvo da versão {{ $currentRevision }}</summary>
<p class="muted">Estes são os dados salvos dessa versão. Abrir esta consulta mantém o texto da sua tentativa. Você pode selecionar e copiar os dados que precisar.</p>
<code>SHA-256: {{ $currentSha256 }}</code>
<dl>
@foreach(['productCode'=>'Formato','brand'=>'Marca fictícia','headline'=>'Chamada principal','description'=>'Descrição','callToAction'=>'Chamada do botão','benefits'=>'Benefícios'] as $field => $label)
<dt>{{ $label }}</dt>
@if($field === 'benefits')
<dd data-field="benefits"><ol>
@foreach($brief['benefits'] as $benefit)
<li>{{ $benefit }}</li>
@endforeach
</ol></dd>
@else
<dd data-field="{{ $field }}">{{ $field === 'productCode' ? ($brief[$field] === 'onepage' ? 'Site Onepage' : 'Landing Page') : $brief[$field] }}</dd>
@endif
@endforeach
</dl>
</details>
