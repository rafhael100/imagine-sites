# Linhas vazias Unicode na entrada de benefícios

## Problema e recorte

A candidata `fix/sites-preview-benefit-blank-lines-2026-11-18` parte da
[#524](https://github.com/rafhael100/imagine-sites/pull/524), HEAD
`89d8a99b83d13fd1b5472b55d7f2891809d2cf81`, validada com 15/15 checks SUCCESS.

Ao colar oito benefícios com uma linha contendo apenas NBSP ou EM SPACE entre
eles, o parser anterior contava essa linha como um nono benefício e recusava o
salvamento. O caso foi reproduzido com o Validator e o middleware reais, usando
somente dados fictícios. Uma linha equivalente com espaços ASCII era ignorada.

[`PreviewBriefInput`](../../apps/sites/app/Preview/PreviewBriefInput.php) agora
descarta linhas inteiramente formadas pela propriedade Unicode `White_Space`
depois do `trim` já existente. A validação original de UTF-8, NUL e do campo
completo acontece antes do filtro. Linhas com conteúdo mantêm os bytes restantes,
inclusive NBSP e EM SPACE nas bordas ou entre palavras. U+200B e U+FEFF continuam
conteúdo; não são incluídos silenciosamente nessa classificação.

Os limites permanecem em um a oito benefícios, 180 caracteres por item e 1500
no campo completo. O filtro não permite contornar o teto bruto por meio de
linhas vazias. O contrato de `SiteBrief`, o JSON editorial da CLI e os demais
campos não foram alterados.

## Verificação e estado

Os testes PHP novos cobrem os dois produtos, oito itens com linhas brancas,
nove itens reais, lista inteiramente branca, teto bruto, preservação exata e
passagem pela pilha de middleware. A suíte geral local passou com **284 testes,
4280 assertions e 27 skips**, com `--do-not-cache-result`; os **32 checks do
laboratório sintético** também passaram. Os skips correspondem aos cenários
reservados ao ambiente de integração. Lint e sintaxe da fixture passaram.

A [jornada HTTP](../../tests/fixtures/preview-http/brief-validation.cjs) reutiliza
o projeto e a segunda revisão já previstos. O POST real sem JavaScript envia oito
itens intercalados com linhas NBSP/EM SPACE. Confere os oito textos no formulário
salvo e no HTML persistido, incluindo seus espaços Unicode e o digest da versão.
Uma única submissão negativa adicional envia nove itens reais; a prova confere
422 com erro específico, tentativa preservada, head 2, versão 3 ausente e bytes
intactos das versões 1 e 2.

São **quatro checks HTTP novos previstos / 451 totais**, ainda pendentes da
execução própria desta candidata. O [workflow isolado](../../.github/workflows/sites-preview-benefit-blank-lines.yml)
fixa a base e o HEAD da PR draft, reutiliza o laboratório Nginx/FPM/PostgreSQL18
com TLS e conserva o cleanup. A matriz PHP 8.3/8.4 da aplicação permanece no
workflow Laravel existente. Não há alteração do renderer, SQL, migrations,
registro de proveniência ou capacidades; as provas desses componentes na #524
continuam identificadas pelo respectivo HEAD.

A correção preserva o histórico e as recusas de acesso. Dez gates operacionais
continuam BLOCKED. Esta candidata não implica merge ou implantação.
