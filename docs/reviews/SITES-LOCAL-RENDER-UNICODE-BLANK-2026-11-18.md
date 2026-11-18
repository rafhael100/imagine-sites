# Renderização local: recusa de texto composto somente por espaços Unicode

## Defeito reproduzido

O [formato de entrada local](../../ops/release-candidate/README.md#briefing-format-and-local-paths)
exige texto não vazio em `brand`, `headline`, `description`, `callToAction` e
em cada item de `benefits`. Porém, a verificação anterior de
[SiteBrief](../../src/Production/SiteBrief.php) usava `trim()` com o conjunto
padrão do PHP para identificar valores em branco. Esse conjunto não inclui
NBSP (`U+00A0`) ou EM SPACE (`U+2003`).

A reprodução em memória chamou `LocalSiteRendering::render()` com os demais
campos válidos e substituiu separadamente os quatro textos e a lista de
benefícios:

| Entrada do campo ou do único benefício | Resultado anterior |
| --- | --- |
| Espaço ASCII, tabulação e CR/LF | `LOCAL_RENDER_DENIED` |
| Somente NBSP + EM SPACE | Artefato aceito |

Assim, a entrada local podia produzir marca, chamada, descrição, botão ou item
de benefício sem conteúdo além de espaços. A reprodução não usou banco,
navegador ou dados reais.

## Correção na entrada local

[LocalSiteRendering](../../src/Production/LocalSiteRendering.php) acrescenta
uma verificação após a construção válida de `SiteBrief` e antes da chamada ao
renderer. Cada um dos quatro textos e cada benefício precisa conter ao menos
um caractere fora da propriedade Unicode `White_Space`. A expressão
`\A\p{White_Space}*\z` verifica o valor inteiro; uma falha de avaliação também
resulta na recusa limitada já existente, `LOCAL_RENDER_DENIED`, sem causa
anexada ou conteúdo em stdout.

A classificação usa a propriedade PCRE, como o filtro de linhas do
[briefing web](../../apps/sites/app/Preview/PreviewBriefInput.php). A
[fonte Unicode 17.0](https://www.unicode.org/Public/17.0.0/ucd/PropList.txt)
lista 25 code points em `White_Space`. ZWSP (`U+200B`) e FEFF (`U+FEFF`) não
fazem parte dessa propriedade e continuam preservados, inclusive isolados.

A regra nova não faz trim, normalização ou remoção de itens. Espaços nas
bordas ou no interior de texto com conteúdo permanecem byte a byte. Um
benefício branco junto de benefícios válidos recusa a entrada completa;
nenhum item JSON é descartado silenciosamente. Tipos, limites, UTF-8 e NUL
continuam sendo verificados pelas regras existentes antes deste novo guard.

`SiteBrief`, o renderer, os vetores e os leitores de histórico/comparação não
mudam. Esses leitores continuam reconstruindo versões persistidas pelo mesmo
contrato anterior. O novo critério pertence à entrada de renderização local,
também usada por `BriefRenderPlanInput::renderEditorial()`; não acrescenta
autoridade, escrita SQL, publicação ou um novo transporte.

## Provas focadas observadas

| Verificação | Resultado |
| --- | --- |
| [Serviço offline](../../tests/local-site-rendering-offline.php) | **159 checks PASS**: 137 anteriores e 22 novos |
| [CLI local com arquivos temporários próprios](../../tests/local-site-rendering-cli-offline.php) | **97 checks PASS**: 87 anteriores e dez novos |
| Lint dos três arquivos PHP e verificação do diff | **PASS** |

O serviço cobre NBSP, EM SPACE e uma mistura dos 25 code points da fonte
Unicode, além de um item branco junto de outro válido e uma lista com somente
um benefício branco. Os positivos com ambos os produtos comparam HTML,
tamanho e SHA-256 com o renderer direto, preservando espaços Unicode nas
bordas/interior, ZWSP e FEFF isolados, CRLF, texto decomposto e escaping.

A CLI reutiliza os positivos existentes com conteúdo Unicode significativo.
Os cinco negativos novos usam arquivos privados válidos e alteram somente o
campo correspondente. Exigem erro limitado, stdout vazio, ausência de
`index.html` e preservação exata do input e de um arquivo independente. Os
testes usam apenas o diretório temporário criado pelo próprio processo e
executam sua limpeza; não usam mounts em `/run`, credenciais ou PostgreSQL.

Cada script focado foi executado uma vez após o fechamento do código.
A suíte geral confirmou **296 testes/4705 assertions/27 skips** com cache de
resultados desativado, e os **32 checks sintéticos** obrigatórios passaram.
**O CI desta candidata ainda está pendente.** Não houve
execução local de PostgreSQL ou navegador.
