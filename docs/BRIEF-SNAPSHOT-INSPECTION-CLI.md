# Consulta privada de um snapshot exato de briefing

**Estado em 18/11/2026:** implementação candidata isolada na branch
`feature/sites-brief-snapshot-inspection-2026-11-18`. A consulta reutiliza o
armazenamento e o leitor existentes; não acrescenta SQL, migration, serviço de
domínio ou transporte Portal. Sua execução PostgreSQL/TLS própria ainda depende
do CI. Não há ativação operacional.

A base é a [#527](https://github.com/rafhael100/imagine-sites/pull/527), HEAD
`6ff2cabc0c32bcaa92de5b94f84c4cc3e7cdd167`, que concluiu **14/14 checks SUCCESS**.
Sua prova de mensagens de limite confirmou 5 checks novos/456 HTTP totais;
essa evidência e a da cadeia de recebimento #526 permanecem separadas do novo
inspector. [Evidência final da base](reviews/SITES-PREVIEW-LIMIT-MESSAGES-2026-11-18.md).

## Para que serve

Uma resposta da [CLI de recebimento](BRIEF-SNAPSHOT-RECEIVER-CLI.md) pode se
perder depois de o banco ter armazenado o snapshot. Esta consulta permite
apurar a seleção original por um reader atualmente autorizado, sem repetir o
receiver ou reativar a identidade que fez a gravação.

O comando chama uma vez
[`StoredBriefSnapshotReader::readExact`](../apps/sites/app/Integration/StoredBriefSnapshotReader.php),
que lê os dois JSONs originais pela função
`sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)`. O leitor
revalida o perfil candidato completo com
[`BriefSnapshotInput`](../src/Production/BriefSnapshotInput.php) e confronta os
quatro seletores antes de devolver o objeto interno. A CLI expõe somente hashes,
revisões e flags técnicas. O corpo do briefing permanece privado.

Esta consulta confirma uma seleção técnica armazenada e válida para o perfil
suportado pelo leitor. Não autentica o produtor Portal, a aprovação declarada
no snapshot, pagamento ou aceite do cliente. Também não executa o renderer,
registra artefato, altera revisão editorial ou reserva autorização futura.

## Comandos e três entradas fixas

```bash
php ops/release-candidate/inspect-brief-snapshot.php --help
php ops/release-candidate/inspect-brief-snapshot.php inspect
```

`--help` termina antes de carregar o autoloader ou acessar entradas privadas e
banco. `inspect` é a única operação de leitura. Não recebe argumentos de
seleção, senha, DSN, SQL, arquivo alternativo ou confirmação de escrita.

| Arquivo privado | Conteúdo e limite |
| --- | --- |
| `/run/secrets/brief_snapshot_inspection` | Configuração da seleção exata, entre 1 e 16.384 bytes. |
| `/run/secrets/sites_brief_snapshot_inspection_reader` | Senha do reader, entre 16 e 4.096 bytes antes da validação de formato. |
| `/run/secrets/sites_brief_snapshot_inspection_ca` | Certificado CA, entre 1 e 65.536 bytes, sem chave privada. |

A configuração contém exatamente seis campos:

| Campo | Regra |
| --- | --- |
| `schema` | `SITES_BRIEF_SNAPSHOT_INSPECTION_V1`. |
| `target` | Exatamente `host`, `port`, `database`, `databaseOid`, `login`, `serverAddress` e `caSha256`, conforme `ReleaseArtifactConnection::target`. |
| `siteRef` | UUID canônico minúsculo do projeto conhecido. |
| `snapshotRef` | UUID canônico minúsculo do snapshot original. |
| `briefRevision` | Inteiro JSON canônico de 1 a 2.147.483.647, sem coerção de string, decimal ou expoente. |
| `manifestSha256` | SHA-256 minúsculo dos bytes exatos do JSON original do snapshot. |

O [exemplo de configuração](../ops/release-candidate/brief-snapshot-inspection.example.json)
contém placeholders deliberadamente inválidos. Não representa um endpoint ou
credencial existente. A validação usa as primitivas compartilhadas de JSON;
chaves extras, duplicadas ou tipos incompatíveis são recusados antes de abrir
a conexão.

`manifestSha256` identifica o JSON do snapshot, incluindo seus espaços e
quebras de linha originais. Não é o hash desta configuração de inspeção nem o
`commitmentSha256` da CLI de recebimento. O compromisso da escrita inclui o
alvo observado e os hashes dos dois JSONs; a consulta usa a identidade imutável
do snapshot. Não há recanonização ou substituição pela revisão mais recente.

## Apuração após uma resposta perdida

Conserve em ambiente privado o manifesto original enviado ao receiver. Dele
vêm `siteRef`, `snapshotRef` e `briefRevision`; o SHA-256 de seus bytes originais
fornece `manifestSha256`. Prepare a configuração de inspeção com esses quatro
valores e o alvo de um reader separado, atualmente autorizado.

Um resultado `inspected` permite confrontar o `payloadSha256` com o corpo
original conservado pelo chamador. O resultado não identifica qual tentativa
de comando gravou a linha e não indica que aquela tentativa criou algo novo.
Uma revisão posterior não altera a seleção histórica.

A consulta não precisa do login ou da atividade atual do receiver histórico.
O SQL verifica a autoridade corrente do reader, seu tenant e as permissões
efetivas. Revogar o receiver não apaga o snapshot; revogar o reader impede suas
consultas seguintes. O manifesto informado pelo chamador nunca concede acesso
a outra empresa.

Uma recusa não demonstra que a gravação anterior deixou de acontecer. Ela
também pode refletir seleção divergente, autorização revogada, custódia
alterada, falha de conexão ou conteúdo armazenado que o perfil completo do
leitor recusa. Não há retry automático ou incremento de revisão para tentar
resolver essa ambiguidade.

Depois da apuração, o [registro composto existente](STORED-BRIEF-REGISTRATION-CLI.md)
pode consumir a seleção por seu próprio plano e suas identidades separadas.
O resultado desta inspeção não substitui esse plano, seu compromisso ou os
controles de escrita. A [inspeção de proveniência do artefato](BRIEF-ARTIFACT-INSPECTION.md)
continua sendo uma consulta distinta, aplicável à associação já registrada.

## Custódia, identidade e leitura

Os três arquivos passam pelo leitor privado existente. Precisam ter caminho
absoluto canônico, ser arquivos regulares com um único hardlink, modo 0400 ou
0600 e proprietário admitido pelo leitor: root ou o EUID do processo. Links
simbólicos, arquivos inseguros e limites excedidos são recusados.

Cada leitura compara identidade, tamanho e tempos de modificação ao redor da
leitura protegida. A CLI conserva bytes e hashes e relê integralmente os três
inputs antes da conexão, antes da consulta e antes de liberar stdout. Não
altera permissões, repara arquivos ou remove inputs. As verificações detectam
mudanças observadas; não constituem uma travessia atômica de pathnames nem
reservam os arquivos depois da última conferência.

A PDO é nova e não persistente, com `sslmode=verify-full`, CA pinada por SHA,
`gssencmode=disable`, timeout de conexão e `ERRMODE_EXCEPTION`. A sessão fica
em `read committed`, UTF-8, `search_path=pg_catalog`, `row_security=on` e
`default_transaction_read_only=on`. Ela nunca é habilitada para escrita.

O guard existente observa banco, OID, login, endereço, porta, TLS e capacidade
exclusiva `read_portal_brief_snapshot` antes e depois da leitura, exigindo a
mesma identidade e sessão. A conexão Laravel é construída diretamente sobre
essa PDO, com query log desabilitado e reconexão recusada. Não há boot HTTP ou
leitura por réplica. O serviço abre sua própria transação somente leitura,
faz uma tentativa e verifica a mesma PDO até encerrar a transação. Os timeouts
internos continuam em 1.500 ms para locks e 5.000 ms para statements.

Em CI, exige `GITHUB_ACTIONS=true`, `APP_ENV=testing` e
`SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1`. O alvo fica limitado a
`localhost:55449/postgres`, endereço observado `127.0.0.1` e logins sintéticos
`bsi_a_reader` ou `bsi_b_reader`. Fora do CI, o código exige `APP_ENV=staging`,
banco `sites_staging`, `GITHUB_ACTIONS` diferente de `true` e ausência do opt-in
de laboratório. Essas condições não criam credenciais ou autorização
operacional. O runtime PHP precisa ser de 64 bits.

## Resultado público e recusa

O sucesso emite uma linha JSON, com exatamente dez campos nesta ordem:

| Campo | Valor |
| --- | --- |
| `schema` | `SITES_BRIEF_SNAPSHOT_INSPECTION_RESULT_V1`. |
| `status` | `inspected`. |
| `manifestSha256` | Hash dos bytes originais do snapshot selecionado e revalidado. |
| `payloadSha256` | Hash dos bytes originais do corpo Onepage armazenado. |
| `briefRevision` | Revisão imutável do snapshot, como inteiro. |
| `sourceVersion` | Contador agregado declarado pela origem, como inteiro; não é uma revisão editorial. |
| `authorityVerified` | `false`. |
| `clientAccepted` | `false`. |
| `canPublish` | `false`. |
| `canAcceptRealOrders` | `false`. |

Não há `recordedAt`: o leitor existente devolve os dois JSONs e não fornece
esse timestamp. A CLI não inventa um horário nem acrescenta outra consulta
para obtê-lo. Referências de projeto/snapshot, tenant, operação, idempotência,
contrato, autor, epoch, corpo do briefing e compromisso de escrita não são
emitidos. Não há arquivo de saída, listagem ou busca de seleção aproximada.

Uma recusa capturada retorna exit 1, stdout vazio e somente
`BRIEF_SNAPSHOT_INSPECTION_DENIED` seguido de LF no stderr. Seleção desconhecida,
empresa diversa, reader revogado, perfil inválido e falhas de TLS ou custódia
compartilham essa resposta, sem cadeia de exceções ou diagnóstico SQL privado.
Uma interrupção do processo ou perda da resposta pode não fornecer um resultado
observável confiável.

## Validação da candidata

**Validação local concluída:** 56 checks offline de ajuda e recusas antecipadas,
lint PHP, forma do exemplo JSON e conferência de whitespace/diff. Os subprocessos
do teste mantêm autoload, arquivos privados e banco inacessíveis; não simulam
sucesso SQL. A revisão independente do wrapper não identificou bloqueadores.
Também passaram a suíte geral **284 testes/4310 assertions/27 skips** e os
**32 checks sintéticos** de `tests/run.php`; esses resultados locais não são
uma execução da nova fixture PostgreSQL/TLS.

O ensaio PostgreSQL/TLS reutiliza snapshots históricos elegíveis, incluindo o
snapshot recebido com stdout descartado, com leitores próprios e nenhum novo
append. A fixture prevê 26 asserções novas e 1.149 totais no registry. Esses
números ainda são previsões: a execução própria em CI está pendente.

O [contrato da fixture](../tests/fixtures/brief-snapshot-inspection/contract.php)
consulta A e B1/B2/B3 antes das negativas. Confirma a revogação prévia do receiver
de B2/B3; verifica os quatro seletores, empresa, OID/endereço, CA distinta com
fingerprint coerente, custódia, exclusividade de capability e revogação do
reader atual. Compara todas as linhas, catálogo e inputs; a única alteração
posterior ao setup é a revogação explícita do novo reader A. O reader B continua
elegível. Não cria snapshot, artefato, decisão editorial ou associação.

O [helper de custódia](../tests/fixtures/brief-snapshot-inspection/cli-paths.py)
reserva somente os três paths ausentes, conserva recibo privado por descritor
e verifica o inventário inteiro antes de remover os objetos próprios. Seu
cleanup antecede o do receiver. O hook executa após as 29 checks e a restauração
de inputs do receiver, sem acrescentar as novas verificações ao contador dele.
O [workflow dedicado](../.github/workflows/sites-brief-snapshot-inspection.yml)
usa a matriz PHP 8.3/8.4, guard de draft/base/head e 17 opt-ins do laboratório;
exige o marcador `BRIEF SNAPSHOT INSPECTION CI PASS: 26 assertions;` e o total
1.149. Nenhum resultado desse job é declarado antes de sua execução.

A consulta não cria transporte oficial Portal, materiais reais, provisionamento,
grants operacionais, publicação, pagamento, aceite ou evento `ENTREGUE`. Os dez
gates permanecem bloqueados e AGI-11-RB01 continua desativado.
