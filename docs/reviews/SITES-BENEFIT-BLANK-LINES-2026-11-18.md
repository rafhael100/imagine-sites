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

As **quatro verificações HTTP novas passaram, com 451 checks totais**, na
execução própria identificada abaixo. O [workflow isolado](../../.github/workflows/sites-preview-benefit-blank-lines.yml)
fixa a base e o HEAD da PR draft, reutiliza o laboratório Nginx/FPM/PostgreSQL18
com TLS e conserva o cleanup. A matriz PHP 8.3/8.4 da aplicação permanece no
workflow Laravel existente. Não há alteração do renderer, SQL, migrations,
registro de proveniência ou capacidades; as provas desses componentes na #524
continuam identificadas pelo respectivo HEAD.

## Evidência final

A [#525](https://github.com/rafhael100/imagine-sites/pull/525) concluiu **14/14
checks SUCCESS** no HEAD `e5c30cd7157b97aabedaec009e1fca366b0d0335`.
O [HTTP 44356190255](https://github.com/rafhael100/imagine-sites/actions/runs/44356190255),
job **134984809450**, confirmou **4 novos/451 totais** e cleanup de app/proxy/db
mais as duas redes. A matriz [Laravel 44356189709](https://github.com/rafhael100/imagine-sites/actions/runs/44356189709),
jobs **134984805593 / 134984806287**, confirmou PHP 8.3/8.4 com **284/4280/27**
e **284/4292/21**. O [F383 44356189343](https://github.com/rafhael100/imagine-sites/actions/runs/44356189343),
jobs **134984804632 / 134984805625**, confirmou **245 briefing/65 intake**, batch
de 14 migrations, rollback dos dois schemas e cleanup. O [ensaio de containers
44356189780](https://github.com/rafhael100/imagine-sites/actions/runs/44356189780),
job **134984805785**, também passou e removeu seus recursos próprios.

Artefato **14453992417**, `sites-preview-benefit-blank-lines-preview-http`:
**37 arquivos**, ZIP de **10124349 bytes**, SHA-256
`1c030947b35ebd3cb113b1743bad5b6c73bf11b6c231e85b9c6eab154ecfe343`, conferido
após download. `result.json` confirmou 451 checks, origem sintética e flags
operacionais falsas. Não houve mudança de layout nem nova revisão visual das
capturas atribuída a esta correção. Registry/workspace completos não foram
reexecutados: suas provas permanecem identificadas pela #524.

A correção preserva o histórico e as recusas de acesso. Dez gates operacionais
continuam BLOCKED. Esta candidata não implica merge ou implantação.
