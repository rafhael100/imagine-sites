# Reapresentação de campos do briefing com quebras de linha

## Defeito e origem

A candidata `fix/sites-preview-multiline-fields-2026-11-18` parte da
[#528](https://github.com/rafhael100/imagine-sites/pull/528), HEAD
`035e50e979e7cdf45f29a7e2c66d0aa1b5d63944`, validada com **12/12 checks
SUCCESS** e 26 novos/1149 registry totais em PHP 8.3/8.4. Essa evidência pertence
à consulta privada da base; não substitui o HTTP desta correção.

`brand`, `headline` e `call_to_action` aceitam quebras internas de linha no
[parser do briefing](../../apps/sites/app/Preview/PreviewBriefInput.php) e no
domínio. A reprodução em memória, com Validator e middlewares reais, confirmou
LF, CRLF e CR válidos nesses campos e sua preservação quando apenas a descrição
ultrapassa 600 caracteres. Não foi necessário banco para essa reprodução.

A [view](../../apps/sites/resources/views/preview/workspace.blade.php), porém,
reapresentava os três valores sempre em `input` de texto. O algoritmo de
[sanitização de `input type=text` no HTML Standard](https://html.spec.whatwg.org/multipage/input.html#text-(type=text)-state-and-search-state-(type=search))
remove CR e LF. Assim, a correção de outro campo poderia reenviar as palavras
unidas, embora o servidor tivesse mantido a tentativa completa.

## Correção delimitada

O diff de produto tem sete linhas na view: os três campos usam `textarea`
somente quando o valor reapresentado contém CR ou LF; valores de uma linha
continuam em `input`. IDs, labels, ajuda, ARIA, campos ocultos, CAS, contexto,
botões e indicação de tentativa não salva permanecem. Não há CSS novo, alteração
de parser, domínio ou limites, nem `maxlength`. A serialização dos campos de
descrição e benefícios não muda.

O ramo novo acrescenta um LF sentinela antes do texto escapado, conforme o
precedente do [formulário de parecer](../../apps/sites/resources/views/preview/review-form.blade.php).
O [processamento de `textarea` pelo HTML Standard](https://html.spec.whatwg.org/multipage/parsing.html#parsing-main-inbody)
descarta o primeiro LF; acrescentá-lo conserva uma quebra inicial pertencente
ao valor. CR e CRLF são normalizados para LF na interpretação do HTML. No envio
nativo `application/x-www-form-urlencoded`, a
[serialização do formulário](https://html.spec.whatwg.org/multipage/form-control-infrastructure.html#url-encoded-form-data)
representa as quebras como CRLF. Preservar conteúdo e separação das linhas não
significa conservar os mesmos bytes de finais de linha em todos os transportes.

## Evidência local confirmada

O grupo [PrivatePreviewWorkspaceViewTest](../../apps/sites/tests/Feature/PrivatePreviewWorkspaceViewTest.php)
passou inicialmente com **24 testes e 300 asserções**, usando
`--do-not-cache-result`. Dois casos novos cobrem os controles condicionais,
LF inicial, CR/LF/CRLF, escaping, mensagens acessíveis, contexto e CAS, além da
separação entre tentativa inválida e briefing salvo. O caso existente também
confere que os valores de uma linha continuam em `input`. Lint dos dois arquivos
e verificação do diff passaram.

O primeiro [CI Laravel 44366662690](https://github.com/rafhael100/imagine-sites/actions/runs/44366662690)
detectou uma diferença do libxml: o ambiente local retinha CR/CRLF no texto
interpretado, mas os dois jobs de CI os normalizavam para LF. Duas expectativas
dos testes dependiam indevidamente desse comportamento local.

A correção da prova confere separadamente os bytes escapados no HTML emitido e
o texto completo do DOM, normalizando somente seus finais de linha nos dois
lados. Conserva a verificação do LF sentinela e de todos os caracteres; a view
de produto não mudou. A classe focada passou depois com **24 testes / 306
asserções**. A prova de navegador continua independente desses testes libxml.

Antes desse ajuste de portabilidade, a suíte geral local havia passado uma vez
com **286 testes / 4358 asserções / 27 skips**, também sem cache de resultados;
o laboratório sintético passou com **32 checks**. A sintaxe JS e a revisão
independente dos três arquivos passaram. A nova execução de CI e o HTTP
próprio foram concluídos na HEAD final indicada abaixo.

## Prova HTTP confirmada

A [fixture existente](../../tests/fixtures/preview-http/brief-validation.cjs)
acrescenta **quatro verificações, com 460 HTTP totais confirmados**, conservando as
456 anteriores. O POST bruto contém marca com LF, chamada principal com CRLF e
chamada do botão com CR; somente a descrição de 601 caracteres é inválida. A
resposta 422 real é aberta em contexto sem JavaScript para conferir labels,
textareas, valores com LF no DOM, erro apenas na descrição e CAS 1. O texto
literal `</textarea><b data-multiline-probe>texto</b>` deve permanecer texto,
sem criar um nó correspondente.

A correção altera somente a descrição e reutiliza o envio positivo já existente
que cria a versão 2, incluindo os oito benefícios Unicode. A fixture confere o
POST nativo com CRLF, a reapresentação salva com LF, um GET posterior e os checks
anteriores de ausência de append nos envios inválidos e bytes históricos
intactos. O seletor do formulário passa a aceitar ambos os tipos de controle.
As duas capturas do formulário, em viewports 1440 e 390 pixels, foram produzidas
e conferidas visualmente. Mostram texto separado em linhas, markup literal,
mensagem de erro legível e controles contidos; as verificações de largura da
página e do formulário também passaram. A descrição longa permanece em seu
controle rolável, com valor completo confirmado pela fixture.

## Evidência final da #529

A [#529](https://github.com/rafhael100/imagine-sites/pull/529), HEAD
`d8a5630bf8957e238b71edf92b6e2a406950e49b`, concluiu **11/11 checks SUCCESS**.
A HEAD inicial `2c92d1f30488da6cb7144e49a5651e86bdce4c7e` é mantida no histórico;
somente testes e documentação mudaram no ajuste de portabilidade, sem alterar
a view de produto.

| Prova | Resultado confirmado |
| --- | --- |
| [HTTP 44368226611](https://github.com/rafhael100/imagine-sites/actions/runs/44368226611), job **135027630937** | **4 novas/460 totais**, incluindo os marcadores de benefícios Unicode e mensagens portuguesas; cleanup de app/proxy/banco e duas redes. |
| [Laravel 44368226606](https://github.com/rafhael100/imagine-sites/actions/runs/44368226606), jobs **135027633135 / 135027634111** | PHP 8.3/8.4: **286 testes/4364 asserções/27 skips**, e **286/4376/21** com opt-ins sintéticos. |

O artefato **14457901656**, `sites-preview-multiline-fields-preview-http`, foi
baixado e conferido: **10.263.767 bytes**, 39 arquivos, SHA-256
`716206ce6bf8b1b7a25a49bf4c5d123fee24f77aa044a95914cdda4e4c39aab7`.
O `result.json` registra 460 checks, origem
`synthetic_separate_laravel_nginx_pg18_tls`, `operational=false` e
`installed_on_vps=false`. As capturas revisadas são
`brief-multiline-desktop.png` e `brief-multiline-mobile.png`.

Esta entrega não executou novamente o registry completo, F383 ou o workflow de
containers. Seu HTTP próprio utilizou e removeu recursos descartáveis. Os dez
gates permanecem BLOCKED, sem merge ou implantação operacional.
