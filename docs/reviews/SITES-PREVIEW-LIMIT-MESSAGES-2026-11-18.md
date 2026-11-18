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

## Jornada HTTP preparada

A [fixture](../../tests/fixtures/preview-http/brief-validation.cjs) reutiliza o
POST negativo de marca já existente e acrescenta somente quatro POSTs negativos
para os outros campos, no mesmo projeto e head 1. Os demais campos permanecem
válidos em cada tentativa. A mensagem exata, o único campo inválido, todos os
valores preservados, o CAS e o cartão salvo são conferidos.

O limite bruto de benefícios usa dois itens curtos separados por uma linha
interior de espaços. Assim, a recusa não depende de limite por item ou quantidade
de benefícios. As verificações posteriores já existentes exigem ausência da
versão 2 e bytes intactos do HTML original. Não há novo projeto ou envio positivo.

São **cinco checks novos previstos/456 HTTP totais**, mantendo os 451 anteriores;
execução própria ainda pendente do CI. O [workflow HTTP](../../.github/workflows/sites-preview-limit-messages.yml)
fixa a base/HEAD da PR draft e usa o laboratório existente com TLS e cleanup.
A matriz Laravel existente cobre PHP 8.3/8.4. Renderer, SQL, migrations, registro
e APIs não mudam; a evidência da integração na #526 permanece vinculada ao seu
HEAD. Os dez gates continuam BLOCKED, sem merge ou implantação.
