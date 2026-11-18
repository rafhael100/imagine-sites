# Mensagens de tamanho do briefing em português

## Problema e correção

A candidata `fix/sites-preview-limit-messages-2026-11-18` parte da
[#526](https://github.com/rafhael100/imagine-sites/pull/526), HEAD
`2ffbcb5303afe12593e11a11a4a6dd45a175ac3f`, validada com 12/12 checks SUCCESS.

Os cinco campos do briefing eram recusados corretamente quando excediam seus
limites, mas a mensagem aparecia em inglês com o nome do campo em português.
O caso foi reproduzido com o Validator, tradutor e middlewares instalados,
mantendo apenas um campo inválido por tentativa e sem banco.

O Laravel 13.40.0 instalado procura a mensagem inline pela regra `max`; quando
seu valor é um array, seleciona o tipo `string`. A chave anterior `max.string`
não era encontrada nessa busca. A única mudança de produto em
[`PreviewBriefInput`](../../apps/sites/app/Preview/PreviewBriefInput.php) é:

```php
'max' => ['string' => 'Use até :max caracteres no campo :attribute.'],
```

| Campo | Limite preservado | Mensagem ao exceder |
| --- | ---: | --- |
| Marca | 120 | Use até 120 caracteres no campo marca fictícia. |
| Chamada principal | 180 | Use até 180 caracteres no campo chamada principal. |
| Descrição | 600 | Use até 600 caracteres no campo descrição. |
| Chamada do botão | 80 | Use até 80 caracteres no campo chamada do botão. |
| Campo completo de benefícios | 1500 | Use até 1500 caracteres no campo benefícios. |

A regra numérica de `expected_revision` mantém sua mensagem própria, inclusive
na revisão que já não admite sucessor. A mensagem de 180 caracteres por benefício
também permanece. Locale global, regras, limites, preservação da tentativa,
filtro de linhas vazias Unicode e contrato do domínio não foram alterados.

## Verificação local

Os cenários existentes passaram a conferir mensagens exatas para os cinco
limites, acentos/emoji/caracteres combinados, entrada no limite, um caractere a
mais e preservação pelo middleware. O grupo focado passou uma vez com **21
testes/367 assertions**. A suíte geral passou com **284 testes/4310 assertions/
27 skips**, usando `--do-not-cache-result`, e o laboratório sintético com **32
checks**. Lint PHP, sintaxe da fixture e revisão do diff também passaram.

## Jornada HTTP confirmada

A [fixture](../../tests/fixtures/preview-http/brief-validation.cjs) reutiliza o
POST negativo de marca já existente e acrescenta somente quatro POSTs negativos
para os outros campos, no mesmo projeto e head 1. Os demais campos permanecem
válidos em cada tentativa. A mensagem exata, o único campo inválido, todos os
valores preservados, o CAS e o cartão salvo são conferidos.

O limite bruto de benefícios usa dois itens curtos separados por uma linha
interior de espaços. Assim, a recusa não depende de limite por item ou quantidade
de benefícios. As verificações posteriores já existentes exigem ausência da
versão 2 e bytes intactos do HTML original. Não há novo projeto ou envio positivo.

A [#527](https://github.com/rafhael100/imagine-sites/pull/527), HEAD
`6ff2cabc0c32bcaa92de5b94f84c4cc3e7cdd167`, concluiu **14/14 checks SUCCESS**.
O [run HTTP 44360758121](https://github.com/rafhael100/imagine-sites/actions/runs/44360758121),
job **135001148995**, confirmou **cinco checks novos/456 HTTP totais**, mantendo
os 451 anteriores. Os containers de app, proxy e banco e as duas redes foram
removidos ao final. O [workflow](../../.github/workflows/sites-preview-limit-messages.yml)
fixa a base/HEAD da PR draft e usa o laboratório existente com TLS.

O artefato **14456250193**, `sites-preview-limit-messages-preview-http`, foi
baixado e conferido: **10.124.528 bytes**, 37 arquivos, SHA-256
`6e91601c3f391f7b6b39a233a62870e916b3841206e5bf173f80bc07f72a6b6f`.
Seu `result.json` informa 456 checks, origem
`synthetic_separate_laravel_nginx_pg18_tls`, `operational=false` e
`installed_on_vps=false`. A mudança não introduziu layout novo; não se atribui
a esta revisão uma nova inspeção visual das capturas.

Na [matriz Laravel 44360757901](https://github.com/rafhael100/imagine-sites/actions/runs/44360757901),
jobs **135001148241 / 135001148149**, PHP 8.3 e 8.4 confirmaram **284 testes /
4310 assertions / 27 skips** e **284 / 4322 / 21** com os opt-ins sintéticos.
O [F1-383 44360757993](https://github.com/rafhael100/imagine-sites/actions/runs/44360757993),
jobs **135001147443 / 135001148334**, confirmou 245 checks de briefing, 65 de
intake e 14 migrations, com rollback dos dois schemas e cleanup. O [ensaio de
containers 44360758038](https://github.com/rafhael100/imagine-sites/actions/runs/44360758038),
job **135001147730**, também concluiu o cleanup.

Renderer, SQL, migrations, registro e APIs não mudam; a evidência da integração
na #526 permanece vinculada ao seu HEAD. Não houve nova execução completa do
registry ou workspace nesta revisão. Os dez gates continuam BLOCKED, sem merge
ou implantação.
