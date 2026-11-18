# Composição da recuperação privada da solicitação

## Objetivo e base

A [#538](https://github.com/rafhael100/imagine-sites/pull/538) publicou o serviço
e a CLI de recuperação sobre o leitor exato da #536. Seus **63 checks offline**
passaram em PHP 8.3/8.4, mas não executaram a nova jornada PostgreSQL. A branch
`integration/sites-request-recovery-composition-2026-11-18` acrescenta essa
prova sobre a [#539](https://github.com/rafhael100/imagine-sites/pull/539), HEAD
`9f9b19adc21944165e70062017471ede1657975b`, validada com **12/12 checks SUCCESS**.

O produto publicado foi preservado: manifesto `SITES_PUBLICATION_REQUEST_V1`,
`planSha256`, argumentos `--plan-sha256` e `--authorize-recovery`, projeções
plano6/resultado13/recibo3 e diretório `publication-recoveries`. O compromisso
continua sendo domínio + NUL + JSON canônico. O único ajuste de produto é no
ramo CI: exige a flag20 junto da21 e admite os dois leitores sintéticos
próprios, mantendo o login anterior. Não muda serviço, helper de produto,
staging, SQL ou migrations.

## Histórias reutilizadas

A fixture executa depois dos **1241 checks anteriores** e do `down16` da prova
do leitor. Não chama `request`, não inicia tentativa e não acrescenta operação,
evento, revisão editorial, binding ou projeto. Cria somente suas duas
identidades leitoras no banco descartável, antes de capturar a baseline.

| Caso existente | O que a composição confere |
| --- | --- |
| Tenant A, chave `journal-idem-request-postcommit` | Recupera o snapshot inconclusivo com writer histórico já revogado; conserva o sentinel, a reserva original destacada e os recibos anteriores. |
| Tenant A, chave `journal-idem-request-a` | Recupera o estado acknowledged atual, com os mesmos campos do snapshot nativo. |
| Tenant B, chave `journal-main` | Usa o leitor próprio de B e mantém o isolamento entre os dois tenants. |

A história pós-commit já teve um replay explícito na fixture #505. Este ensaio
não a apresenta como uma operação nunca recuperada: demonstra uma nova leitura
autorizada, sem repetir o writer e preservando todas as evidências anteriores.

## Prova executável

A [fixture](../../tests/fixtures/publication-request-recovery/contract.php)
prevê **38 verificações novas**, acrescidas às 1241 da cadeia:

- Observa o laboratório PostgreSQL18/SCRAM/TLS, a PDO nativa compartilhada e a
  custódia dos inputs. Captura linhas de negócio e catálogo antes do `up16`.
- Executa a CLI real para plano e recuperação dos três casos. Compara a ordem
  e os valores exatos das projeções **6/13/3**, incluindo um hash calculado
  independentemente da implementação de produto.
- Usa outro destino e descarta stdout da recuperação. Lê `operationRef` do
  novo recibo privado e entrega somente esse UUID à CLI `inspect.php`, com
  `pj_request_a_reader`, sua API e autorização próprias. Confere os quatorze
  campos públicos do inspector contra o snapshot original.
- Recusa autorização ausente, hash errado, plano de outro destino, arquivo já
  existente, OID/endereço divergentes, CA não confiável mesmo com fingerprint
  correspondente, inputs legíveis por terceiros, hardlink e duas APIs de
  leitura concedidas ao mesmo login.
- Confere ausência de correspondência, cross-tenant e revogação do leitor A.
  O plano continua sendo uma observação de conexão, não uma reserva de
  autorização. As leituras recusadas podem conservar reserva privada vazia.
  O leitor B continua funcionando depois da revogação de A.
- Restaura inputs, compara o histórico completo, confere ausência de dados
  privados em stdout e executa `down16`, devolvendo o catálogo anterior.

O histórico de negócio deve permanecer igual; somente a revogação intencional
de um dos dois readers novos é considerada na comparação final. Os três
inputs do inspector retornam aos mesmos bytes, inode e modo. Os timestamps
alterados pela escrita temporária desses inputs e do diretório do hardlink
são tratados explicitamente; não se normalizam linhas ou bytes de negócio.

## Custódia e integração

O [helper de laboratório](../../tests/fixtures/publication-request-recovery/cli-paths.py)
exige GitHub Actions, UID root, as flags de registry, leitor e recuperação e um diretório temporário
próprio. Prepara os três inputs fixos e um diretório vazio 0700, com recibo
privado das identidades reservadas. Cleanup só admite os nove nomes declarados
e recusa troca de inode. Não adota diretórios ancestrais ou arquivos antigos.

O hook antecipado dentro da fixture do leitor foi removido, restaurando seus
bytes à #536. A nova chamada ocorre somente depois de seu retorno e rollback,
com contagem estrita **38/1279**. O runner mantém **1241** quando apenas a
flag20 está ligada. O workflow usa a HEAD exata da PR draft e a base #539
fixada, não cancela uma execução iniciada e exige os marcadores de cleanup.
Subprocessos da nova fixture têm prazo de quinze segundos e saída limitada.

## Evidência e limites

Localmente passaram **63 checks offline** do produto publicado e os **32 checks
de domínio** obrigatórios. Lint PHP, AST do helper, Bash e diffcheck passaram.
As revisões independentes conferiram compatibilidade de protocolo, ordem de
integração e preservação da custódia. Na preparação local, **38 novos /1279
totais eram contadores previstos**; a execução real e sua evidência seguem
abaixo. Não houve PostgreSQL local.

A prova exercita a recuperação de um ponteiro histórico; não autentica origem
Portal, pagamento, aceite ou publicação observada. Ausência de recibo ou
resposta não autoriza repetir o writer. Os dez gates continuam BLOCKED,
AGI-11-RB01 desabilitado e a entrega permanece em PR draft.

## Evidência final da #540

O commit de implementação `1c9b4d46949b907c225b1a0cc42e10c572b44e33` da
[#540](https://github.com/rafhael100/imagine-sites/pull/540) confirmou **12/12
checks SUCCESS**. O [registry 44403773102](https://github.com/rafhael100/imagine-sites/actions/runs/44403773102),
jobs [PHP 8.3](https://github.com/rafhael100/imagine-sites/actions/runs/44403773102/job/135153431208)
e [PHP 8.4](https://github.com/rafhael100/imagine-sites/actions/runs/44403773102/job/135153430438),
confirmou **63 offline**, **33 do leitor** e **38 novos /1279 registry totais**.
Os 1241 checks predecessores permaneceram completos. O marcador final só é
emitido depois das comparações de arquivos/linhas/catálogo e do rollback16.

Ambos os logs confirmam o teste negativo de troca de inode, o cleanup próprio
`RECOVERY CLI PATHS CLEANUP OK` e o cluster descartável inativo. Os helpers
anteriores também concluíram sua limpeza. O [Laravel 44403773083](https://github.com/rafhael100/imagine-sites/actions/runs/44403773083),
jobs **135153429401 / 135153430221**, confirmou **296 testes/4705 assertions/27
skips** e **296/4717/21** em ambas as versões. A revisão local final conferiu
**749 arquivos**, cinco blocos Bash, **188 links locais** e diffcheck.

A consolidação documental seguinte registra esta evidência sem modificar o
código, os testes, o workflow ou os contratos do commit comprovado acima.
