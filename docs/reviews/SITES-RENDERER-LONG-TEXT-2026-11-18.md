# Texto completo nas novas páginas geradas

## Estado e problema

Candidata em `fix/sites-renderer-long-text-2026-11-18`, construída sobre a
[#522](https://github.com/rafhael100/imagine-sites/pull/522), HEAD
`0b735a1794ee3bb965e5a05d5d738083ddac9544`, com 12/12 checks aprovados.
A [#524](https://github.com/rafhael100/imagine-sites/pull/524) concluiu **15/15 checks
SUCCESS** no HEAD `89d8a99b83d13fd1b5472b55d7f2891809d2cf81`, com a prova própria
PostgreSQL/HTTP descrita a seguir.

[`SiteBrief`](../../src/Production/SiteBrief.php) aceita marca com 120 caracteres,
descrição com 600, CTA com 80 e cada benefício com 180. Palavras e URLs literais
contínuas são conteúdo válido. Antes deste ajuste, somente os títulos do
[`StaticSiteRenderer`](../../src/Production/StaticSiteRenderer.php) possuíam
quebra explícita. As outras caixas podiam exceder a largura disponível no
celular, mesmo com o briefing dentro de seus limites.

Essa conclusão anterior veio da leitura do código. A tentativa de abrir o
Chromium local abortou por `socket() failed: Operation not permitted` antes de
carregar qualquer página. Não se atribui a essa tentativa uma medição de layout,
captura ou reprodução visual. A fixture HTTP verifica as páginas reais no CI.

## Alteração de comportamento

O renderer acrescenta somente três declarações:

| Elemento | Alteração | Finalidade |
| --- | --- | --- |
| `body` | `overflow-wrap: anywhere` | Permitir que o texto herdado quebre quando uma palavra ou URL não cabe na linha. |
| `.brand` | `min-width: 0` | Permitir redução da marca como item flex. |
| `.btn` | `max-width: 100%` | Conter a caixa do CTA na largura disponível. |

Não há truncamento, reticências, ocultação de overflow ou remoção de texto.
O HTML dos dois produtos foi comparado antes/depois e permaneceu idêntico fora
do bloco `style`: conteúdo, estrutura, links de âncora, marcação acessível e CSP
foram preservados. URLs do briefing continuam texto literal, sem se tornarem
links externos ou chamadas de rede.

## Novos bytes e versões históricas

A mudança de CSS altera os bytes e, portanto, o digest do HTML gerado. Os
[exemplos correntes](../../contracts/portal-sites/candidates/brief-production-v1/README.md)
foram regenerados pelo renderer real, com resultado conferido em duas gerações
independentes:

| Vetor corrente | Bytes | SHA-256 |
| --- | ---: | --- |
| HTML sintético | 5223 | `ab999fc8c61d4f22ab250c6d5d86442a0ceca24659d1f4173db3580e4694740e` |
| Plano sintético | 840 | `742647a821be501c3c1d58153fd93150ecfd82d134473a96b4761529c700c276` |

No plano, somente `expectedArtifact.sha256` e `expectedArtifact.sizeBytes`
foram alterados. Corpo rico, manifesto do snapshot, entrada editorial e recibo
de snapshot conservam os bytes anteriores. As referências de execução da #517
mantêm explicitamente o HTML original de 5167 bytes e seu digest, acessíveis no
[commit histórico](https://github.com/rafhael100/imagine-sites/tree/c53eac9eb8060a62eb060e68f3bc5b2956dbbdff/contracts/portal-sites/candidates/brief-production-v1/examples).

`PrivatePreviewWorkspace::view()` continua lendo o HTML armazenado e conferindo
seu digest; a comparação também trabalha com versões persistidas. O registry,
a inspeção histórica e a preparação/verificação de pacotes não reescrevem os
artefatos por causa desta alteração. Não se instala CSS adicional ao consultar
uma versão antiga, nem se transfere uma aprovação para um digest novo.

Para gerar novamente, `BriefRenderPlanInput::renderEditorial()` exige a
expectativa exata do renderer corrente. Um plano com o digest antigo continua
sendo material histórico e é recusado como entrada de uma nova geração com
bytes diferentes. Um novo plano produz seu próprio compromisso. Não existe
adaptação silenciosa ou seleção automática do template anterior.

## Prova isolada

A ampliação de [`inert-text.cjs`](../../tests/fixtures/preview-http/inert-text.cjs)
reutiliza os dois projetos e as revisões existentes da jornada Onepage/Landing
Page. A revisão posterior contém tokens ASCII contínuos nos limites e URLs
literais extensas. O preenchimento e o envio passam pelo formulário real com
JavaScript desativado.

A jornada verifica a própria página de HTML em 320, 390 e 1440 pixels, incluindo
largura da página, caixas e conteúdo textual completo. Conserva a comparação
entre os bytes da resposta, o digest exposto no histórico e o HTML que o
navegador recebeu. Os testes anteriores de conteúdo inerte, escopo e ausência de
requests externos continuam presentes; as capturas pertencem à prévia real.
Nenhum CSS é reescrito em memória para simular um resultado anterior. As oito
verificações novas passaram no CI e elevaram a jornada de 439 para **447 checks**.

O [workflow próprio](../../.github/workflows/sites-renderer-long-text.yml) fixa
draft, repositório, branch, base e checkout do HEAD revisado. Executa o workspace
em um cluster descartável e o pipeline completo do registry/proveniência em
outro, com as quinze opções explícitas da base. A jornada HTTP usa o laboratório
já existente com Nginx/FPM/PostgreSQL separados e verificação de TLS antes do
navegador. Identidade errada é recusada antes de DDL; cleanup permanece obrigatório.
A fixture de snapshots atualizada também é coberta pelo workflow F383 existente.

| Verificação local concluída | Resultado |
| --- | --- |
| Renderer puro | 27 checks. |
| Renderização local | 137 checks. |
| Proveniência | 109 checks. |
| Registro anterior | 271 checks. |
| PHPUnit padrão | 279 testes / 4250 assertions / 27 skips, com `--do-not-cache-result`. |
| Lint, diffs e geração do vetor | PASS; somente o CSS e a expectativa correspondente alteraram bytes. |
| Layout local | Não executado: o navegador não abriu. |
| PostgreSQL/HTTP próprios desta candidata | PASS no run 44352671761, conforme evidência abaixo. |

## Evidência final da #524

| Execução | Resultado próprio confirmado |
| --- | --- |
| [Run 44352671761](https://github.com/rafhael100/imagine-sites/actions/runs/44352671761), jobs 134972259966 / 134972259276 | PHP 8.3/8.4: **429 workspace + 1094 registry/proveniência**, arquivos privados removidos e clusters próprios inativos. |
| Mesmo run, job 134972260095 | **8 novos / 447 HTTP totais**, dois produtos em 320/390/1440 px; containers app/proxy/db e redes privada/ingress removidos. |
| [F383 44352671538](https://github.com/rafhael100/imagine-sites/actions/runs/44352671538), jobs 134972257509 / 134972258209 | PHP 8.3/8.4: **245 briefing / 65 intake**, batch de **14 migrations**, rollback dos dois schemas e cleanup do serviço. |
| [Laravel 44352671587](https://github.com/rafhael100/imagine-sites/actions/runs/44352671587), jobs 134972257829 / 134972257267 | PHP 8.3/8.4: padrão **279/4250/27**, sintético **279/4262/21**. |

Artefato **14447704981**, `sites-renderer-long-text-preview-http`: **37 arquivos**,
ZIP de **10134212 bytes**, SHA-256
`d9e410e55714651afc29d5f96e0fcf1dd977b8196f3d01fe9963054453a0fcea`, conferido após
download. `result.json` registra 447 checks, origem sintética e flags operacionais
falsas. As seis capturas pertencem às páginas reais; a revisão visual das quatro
capturas em 320 e 1440 px confirmou conteúdo contido e quebra integral nos dois
produtos. A prova geométrica também cobre 390 px. Essa evidência pertence ao CI,
sem substituir a limitação da tentativa local descrita acima.

Os negativos lexicais do plano agora derivam seu tamanho corrente e exigem uma
substituição real antes de testar duplicata, número decimal, expoente ou string.
Assim continuam isolando a representação inválida, em vez de depender de um
literal de tamanho que deixou de existir. Essa atualização conserva os 109
checks anteriores.

A correção não modifica validação de entrada, serviços de autorização, SQL,
migrations, leitura histórica ou decisões editoriais. Os dez gates continuam
BLOCKED e não há merge ou implantação operacional nesta entrega.
