# Recebimento privado candidato de um projeto do Portal

**18/11/2026 — implementação candidata, sem ativação operacional.**
[`receive-project-intake.php`](../ops/release-candidate/receive-project-intake.php)
oferece uma entrada local para o
[`PortalProjectIntakeRecorder`](../apps/sites/app/Integration/PortalProjectIntakeRecorder.php)
existente. O comando registra uma solicitação pendente e conserva o recibo
nativo em arquivo privado. Não autentica aprovação comercial do Portal,
não transporta esse recibo para outro sistema e não libera produção ou publicação.

O [comando CreateProject](../src/Integration/SiteProjectCommand.php), o recorder,
o [contrato SQL](../contracts/portal-sites/001-project-intake.sql) e o
[schema do recibo nativo](../contracts/portal-sites/v1/project-intake-receipt.schema.json)
permanecem inalterados. O contexto e as limitações da recepção nativa estão em
[PORTAL-PROJECT-INTAKE.md](PORTAL-PROJECT-INTAKE.md).

## Entradas e destino fixos

Não há argumentos para escolher arquivos, endpoint ou diretório de saída.
A CLI lê quatro mounts privados:

| Caminho | Conteúdo | Limite em bytes |
| --- | --- | --- |
| `/run/secrets/sites_project_intake_receive_target` | Configuração exata com `schema`, `target` e `receiptRef` | 1–16.384 |
| `/run/secrets/sites_project_intake_receive_command` | JSON CreateProject original, com oito campos | 1–16.384 |
| `/run/secrets/sites_project_intake_receive_password` | Senha do receiver | 16–4.096 |
| `/run/secrets/sites_project_intake_receive_ca` | CA usada na conexão `verify-full` | 1–65.536 |

A configuração usa `schema: SITES_PROJECT_INTAKE_RECEIVER_V1`. O objeto
`target` contém exatamente `host`, `port`, `database`, `databaseOid`, `login`,
`serverAddress` e `caSha256`. `receiptRef` é um UUID textual minúsculo que
seleciona somente o nome do arquivo local; não é o `siteRef` retornado pelo SQL.
O [exemplo sintético](../ops/release-candidate/project-intake-receive.example.json)
contém OID e hash de CA ilustrativos, sem credencial ou servidor comprovado.

O comando mantém exatamente `schemaVersion`, `operationId`, `tenantRef`,
`orderRef`, `contractRef`, `contractRevision`, `productCode` e `idempotencyKey`.
O schema é a string `1`; `contractRevision` precisa ser um inteiro de
1 a 2.147.483.647. O parser compartilhado recusa chaves duplicadas, campos
extras, tipos incorretos e números com representação não canônica. A validação
de referências e produtos permanece em `SiteProjectCommand`: `onepage` e
`landing_page` são os produtos reconhecidos pelo contrato Sites. Isso não
afirma que o Portal já exporte ambos. `tenantRef` é a referência opaca da
organização Portal, e seu valor no JSON não concede autorização.

Os quatro arquivos devem ser regulares, com um único hardlink, caminho
canônico, modo 0400/0600 e dono root ou EUID, segundo o leitor privado existente.
As releituras comparam bytes, SHA-256 e identidade observada, incluindo inode,
modo, dono, tamanho, mtime e ctime. Atime não participa da comparação.
A senha admite apenas terminadores CR/LF no fim; depois de removê-los para
autenticação, deve continuar com ao menos 16 bytes, sem NUL ou quebras internas.
Os bytes originais do mount continuam sujeitos à comparação de custódia.

O diretório `/run/sites-project-intake-receipts` precisa existir, ser canônico,
pertencer ao EUID e ter modo 0700. Os ancestrais são verificados; links
simbólicos e componentes graváveis por terceiros são recusados, com a exceção
do ancestral root com sticky bit usada pelo padrão de custódia. A CLI não cria
diretórios nem corrige permissões. O destino é exclusivamente
`/run/sites-project-intake-receipts/<receiptRef>.json`, novo e com modo 0600.
Um destino já existente, inclusive vazio, link ou diretório, provoca recusa.

## Plano e confirmação explícita

```bash
php ops/release-candidate/receive-project-intake.php --help
php ops/release-candidate/receive-project-intake.php plan
php ops/release-candidate/receive-project-intake.php record \
  --commitment-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_SHA256 \
  --authorize-receive=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED
```

`--help` funciona antes do autoload e não acessa os mounts. `plan` não aceita
flags. `record` exige as duas opções exatas, uma vez cada, em qualquer ordem.
Não há retry automático ou comando de sobrescrita.

O plano valida os inputs e a disponibilidade do destino, abre uma nova PDO em
modo somente leitura e observa o alvo e a capacidade efetiva da conexão.
Não chama o recorder, não consulta o vínculo privado de tenant e não reserva
o nome do arquivo. Um plano bem-sucedido não garante que o comando será aceito
quando o SQL revalidar esse vínculo.

Os três hashes têm funções distintas:

| Campo | Compromisso |
| --- | --- |
| `commandSha256` | SHA-256 dos bytes exatos do JSON original, incluindo whitespace, `operationId` e `idempotencyKey` |
| `commandDigest` | `SiteProjectCommand::semanticFingerprint()`, sobre os seis campos semânticos do contrato; exclui `operationId` e `idempotencyKey` |
| `commitmentSha256` | Alvo canônico, hash do comando bruto e caminho completo do recibo, com domínio próprio |

O compromisso é calculado exatamente como:

```text
SHA256(
  "sites.project-intake-receiver.v1" + NUL
  + JSON(target canônico, JSON_UNESCAPED_SLASHES) + NUL
  + commandSha256 + NUL
  + caminho completo do recibo
)
```

O target canônico segue a ordem dos sete campos indicada acima. Nenhum JSON
do comando é recanonizado para calcular `commandSha256`. Alterar o destino,
login, OID, CA ou bytes do comando exige um compromisso correspondente.
O compromisso não é uma identidade autenticada do Portal, um lock ou uma
reserva de capacidade futura.

## Escrita e recibo privado

Depois de conferir o compromisso e observar a sessão somente leitura,
`record` reserva o arquivo com criação exclusiva e retém seu descritor aberto.
Relê os quatro inputs e confere a reserva antes de habilitar escrita apenas
naquela PDO. Observa novamente a mesma identidade e chama
`PortalProjectIntakeRecorder::record` uma única vez, em uma conexão Laravel
direta, com query log desativado e reconexão recusada. O recorder continua
responsável por sua transação independente, de uma tentativa, e pela
revalidação SQL do binding `active` com `can_receive_project`.
Esta entrada não acrescenta epoch ou expiração a esse modelo de autorização.

Após o retorno do recorder, a CLI confere conexão, sessão, transação encerrada
e inputs. O helper
[`PrivateProjectIntakeReceipt`](../src/Integration/PrivateProjectIntakeReceipt.php)
valida os quinze campos nativos, comparando explicitamente o contexto do
comando, inclusive `operationId` e `idempotencyKey`; o digest semântico sozinho
não substitui essa comparação. O recibo privado contém exatamente:

```text
schemaVersion, tenantRef, orderRef, contractRef, contractRevision,
productCode, operationId, idempotencyKey, siteRef, commandDigest,
status, state, authorityVerified, clientAccepted, canPublish
```

`schemaVersion` é `sites.project-intake.receipt.v1`, `status` é `recorded` e
`state` é `awaiting_official_authority`. Os três booleanos são `false`.
Não se acrescentam timestamp, `created`, `canAcceptRealOrders` ou compromisso
da CLI ao contrato pinado pelo Portal.

O helper escreve JSON compacto com `JSON_UNESCAPED_SLASHES` e um LF final,
limitado a 4.096 bytes. Usa somente o descritor reservado, verifica escrita
completa, flush, fsync, leitura de retorno, identidade do arquivo e ancestrais.
Fecha o descritor antes da resposta pública; a CLI ainda confere os inputs e
a identidade da sessão antes de emitir stdout.

## Resposta pública e falhas

O sucesso é uma única linha JSON, com exit 0. Não inclui referências, login,
senha, caminhos, comando bruto ou recibo privado.

| Operação | Schema | Campos, na ordem |
| --- | --- | --- |
| `plan` | `SITES_PROJECT_INTAKE_RECEIVER_PLAN_RESULT_V1` | `schema`, `status`, `commitmentSha256`, `commandSha256`, `commandDigest`, `authorityVerified`, `clientAccepted`, `canPublish` |
| `record` | `SITES_PROJECT_INTAKE_RECEIVER_RESULT_V1` | Os mesmos oito campos, seguidos de `receiptSha256` e `sizeBytes` |

O status público é `planned` ou `recorded`, respectivamente. As três flags
permanecem falsas. `receiptSha256` e `sizeBytes` descrevem os bytes completos
do arquivo, incluindo o LF final. `recorded` não distingue criação de replay:
o recibo nativo é estável para o replay exato que o SQL autoriza.

Uma recusa capturada retorna apenas `PROJECT_INTAKE_RECEIVE_DENIED` em stderr,
exit 1 e stdout vazio. **SQL e arquivo não formam uma transação atômica.**
Falha de verificação, escrita, fsync, fechamento ou resposta pode ocorrer
depois de um commit. Uma reserva vazia, arquivo parcial ou recibo completo
pode permanecer. Interrupção do processo ou perda da resposta pode não
fornecer um resultado observável confiável.

A CLI nunca apaga, renomeia, substitui ou repara esse arquivo, e não repete o
recorder. Preserve comando, configuração e resultado privado para apuração.
Ausência de stdout ou um erro não prova ausência do intake. Uma eventual
repetição manual exata deve considerar o estado já registrado e a autorização
corrente; não gere outra identidade do comando para tentar contornar um
resultado incerto. Um novo `receiptRef` muda o compromisso e o destino, mas
não altera as regras de replay do SQL. Esta CLI não oferece lookup de intake.

As verificações de pathname detectam substituições observadas e comparam o
arquivo com o descritor retido. PHP não fornece aqui uma travessia atômica
por `openat`; não há promessa de atomicidade de caminhos nem rollback de
arquivo e banco.

## Ambiente e fronteira da prova

Fora do CI, o contrato continua restrito a `APP_ENV=staging`, sem
`GITHUB_ACTIONS=true` ou opt-in de CI, e banco `sites_staging`. Isso não instala
nem autoriza uma execução operacional. No laboratório, são exigidos
`GITHUB_ACTIONS=true`, `APP_ENV=testing` e
`SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1`, com `localhost:55449/postgres`,
endereço observado `127.0.0.1` e somente os logins sintéticos
`pir_a_receiver` ou `pir_b_receiver`.

A PDO nova usa `sslmode=verify-full`, hash da CA conferido, GSS desativado e
timeouts limitados. O observador exige PostgreSQL 18, UTF8, READ COMMITTED,
`search_path=pg_catalog`, `row_security=on`, ausência de transação externa,
modo de erro por exceção e identidade sem superpoderes. O único EXECUTE
efetivo permitido entre as APIs de `sites_core` e `sites_publication` é
`record_portal_project_request(text,text,text,text,text,integer,text,text)`,
sem grant option. A nova enumeração é fechada; o manifesto não escolhe SQL.
Nenhum grant, role, migration ou atributo de binding é criado por esta CLI.

Os testes offline separados verificam argumentos, recusas anteriores à
conexão e custódia em diretório temporário próprio. Eles não provam commit
SQL. A execução local única confirmou **77 checks de CLI** e **123 checks de
custódia do recibo**, sem simular sucesso SQL ou autoridade. O lint dos três
arquivos PHP da implementação e a conferência dos quatro pins Portal também
passaram. A fixture de integração deve comprovar recepção real, isolamento,
replay, revogação, preservação do recibo e falha de persistência após commit.
O resultado PostgreSQL desta candidata permanece pendente de CI; a evidência
anterior da base não substitui essa prova.

O [workflow dedicado](../.github/workflows/sites-project-intake-receiver-cli.yml)
fixa a base #529 no HEAD `d8a5630bf8957e238b71edf92b6e2a406950e49b` e executa
PHP 8.3/8.4 com os dezoito opt-ins do laboratório existente. A fixture prevê
**29 checks novos/1178 totais**. Substitui três chamadas de intake já previstas
pelos recibos obtidos na CLI; os dez projetos e os 119 checks próprios da
composição permanecem. A resposta B é descartada antes de recuperar seu arquivo.
O caso de arquivo perdido observa uma chamada real bloqueada pelo lock nativo,
substitui sua reserva e libera o lock antes do timeout. Depois confere o commit
nas quatro tabelas, a preservação dos dois arquivos e o mesmo `siteRef` em um
replay explícito para novo destino. O helper remove apenas seus inputs e nomes
de recibo declarados, depois de validar todo o inventário. Essas são condições
da prova preparada, ainda sem resultado PostgreSQL atribuído a esta candidata.
