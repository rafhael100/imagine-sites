# Recuperação privada do recibo de projeto

## Finalidade

`recover-project-intake-receipt.php` consulta um intake conhecido pelos oito campos
originais do comando e grava seu recibo nativo em um novo arquivo privado. Usa
uma identidade de leitura atual, separada do receiver histórico. Isso permite
apurar uma resposta perdida ou um arquivo incompleto sem conceder novamente a
capacidade de receber projetos.

A CLI usa o [leitor exato](PROJECT-INTAKE-RECEIPT-READER.md) da migration 15 e a
mesma [custódia de recibos](../src/Integration/PrivateProjectIntakeReceipt.php) do
receiver. O arquivo continua tendo os quinze campos nativos, incluindo `siteRef`,
`status=recorded`, `state=awaiting_official_authority` e três indicadores falsos.
Não é necessário conhecer o `siteRef` antes da recuperação.

## Entradas privadas

| Caminho fixo | Conteúdo e limite |
| --- | --- |
| `/run/secrets/sites_project_intake_recovery_target` | Configuração JSON, 1–16384 bytes |
| `/run/secrets/sites_project_intake_recovery_command` | Comando nativo original, 1–16384 bytes |
| `/run/secrets/sites_project_intake_recovery_reader` | Senha do leitor restrito, 16–4096 bytes |
| `/run/secrets/sites_project_intake_recovery_ca` | CA PEM revisada, 1–65536 bytes |
| `/run/sites-project-intake-recovered-receipts` | Diretório de saída preexistente, pertencente ao usuário efetivo, modo 0700 |

A [configuração de exemplo](../ops/release-candidate/project-intake-recovery.example.json)
contém dados fictícios de CI e exige substituição por um inventário revisado.
Possui exatamente `schema`, `target` e `receiptRef`, com
`schema=SITES_PROJECT_INTAKE_RECOVERY_V1`. O alvo contém exatamente `host`, `port`,
`database`, `databaseOid`, `login`, `serverAddress` e `caSha256`. `receiptRef` é um
UUID minúsculo novo; determina somente o nome do arquivo de saída.

O comando conserva `schemaVersion`, `operationId`, `tenantRef`, `orderRef`,
`contractRef`, `contractRevision`, `productCode` e `idempotencyKey`. Sua revisão é
um inteiro entre 1 e 2147483647. Chaves repetidas, campos extras e coerções de tipo
são recusados. O digest semântico não inclui `operationId` ou `idempotencyKey`;
esses dois seletores também são comparados individualmente pelo leitor.

Os quatro inputs são arquivos regulares de link único, modo 0400 ou 0600, de root
ou do usuário efetivo. A CLI verifica caminhos e ancestrais canônicos, identidade
e bytes antes e depois da operação. Não recebe caminhos, senha ou modo TLS como
argumentos ou campos extras. Não repara permissões ou substitui inputs.

## Plano e recuperação

```sh
php /opt/imagine/ops/release-candidate/recover-project-intake-receipt.php plan
```

O plano valida os inputs, o destino disponível e a identidade técnica da conexão.
Não reserva um arquivo nem consulta o binding privado ou o recibo. Um plano pode
ser produzido depois de revogação do binding; somente a consulta de recuperação
verifica a autorização corrente para aquele tenant e comando.

O compromisso é SHA256 da concatenação, nesta ordem:

1. `sites.project-intake-recovery.v1`, seguido por NUL.
2. JSON canônico dos sete campos de alvo, com `JSON_UNESCAPED_SLASHES`, seguido por NUL.
3. SHA256 dos bytes originais do comando, seguido por NUL.
4. Caminho completo do arquivo de saída.

Mudar somente o destino altera o compromisso. Alterar bytes do comando, inclusive
espaçamento, também o altera, mesmo quando o digest semântico permanece igual.
O hash confirmado deve vir da revisão do plano correspondente aos inputs atuais.

```sh
php /opt/imagine/ops/release-candidate/recover-project-intake-receipt.php recover \
  --commitment-sha256=<sha256-minúsculo-do-plano-revisado> \
  --authorize-recover=SITES_PROJECT_INTAKE_RECEIPT_ONLY_APPROVED
```

As duas opções são obrigatórias, aparecem uma vez e podem vir em qualquer ordem.
`--help` funciona antes do autoload. Não há retry automático ou alternativa de
escrita para uma seleção ausente ou recusada.

## Conexão e autorização

A conexão PDO é nova, não persistente e usa `sslmode=verify-full` com a CA fixa.
A CLI confere PostgreSQL 18, TLS, banco/OID, login, endereço, porta e PID. A sessão
e a transação permanecem somente leitura, com UTF-8, READ COMMITTED,
`search_path=pg_catalog`, RLS ativo e timeouts limitados. O serviço usa a mesma PDO
principal, uma transação própria e uma consulta exata, sem pool alternativo.

O login precisa ser restrito, ter USAGE no schema e EXECUTE exclusivamente na API
`read_portal_project_intake_receipt`. A função exige binding atual `active/can_read`
e organização correspondente. A capacidade `can_receive_project` não é necessária.
O receiver original pode estar revogado. Credenciais e grants devem existir antes
da execução; a CLI não os provisiona.

A função STABLE observa recibo e autorização no snapshot do início da instrução.
Uma revogação confirmada antes da próxima consulta a impede. A operação não reserva
a autorização até o fim da gravação do arquivo nem invalida retroativamente uma
leitura que já começou. O recibo histórico não certifica elegibilidade corrente
para publicar ou aceite comercial.

Fora do CI, a entrada exige `APP_ENV=staging`, ausência do opt-in de CI e banco
`sites_staging`. Isso não instala nem autoriza o uso em staging por si só. A porta
configurada precisa coincidir com a porta observada pelo servidor.

## Arquivo e saídas públicas

`recover` reserva exclusivamente
`/run/sites-project-intake-recovered-receipts/<receiptRef>.json` em modo 0600.
Mantém o descritor, revalida a reserva e os inputs, lê o recibo, confere novamente
conexão e custódia, grava JSON compacto com LF, sincroniza e relê os bytes.
Usa o helper existente sem alterar seu contrato. Arquivos, diretórios e links já
presentes no destino são recusados e conservados.

| Operação | Saída pública exata |
| --- | --- |
| `plan` | Oito campos: `schema=SITES_PROJECT_INTAKE_RECOVERY_PLAN_RESULT_V1`, `status=planned`, `commitmentSha256`, `commandSha256`, `commandDigest`, `authorityVerified=false`, `clientAccepted=false`, `canPublish=false` |
| `recover` | Dez campos: os mesmos campos, com `schema=SITES_PROJECT_INTAKE_RECOVERY_RESULT_V1`, `status=recovered`, e os campos adicionais `receiptSha256` e `sizeBytes` |

Stdout não contém referências privadas, comando, senha, CA ou diagnóstico PDO.
Falhas retornam código 1, stdout vazio e somente `PROJECT_INTAKE_RECOVERY_DENIED`
em stderr. Podem deixar uma reserva vazia, um arquivo parcial ou um recibo completo;
esses destinos permanecem para apuração e nunca são apagados ou sobrescritos pela
CLI. A recuperação consulta SQL; não acrescenta projetos, eventos ou recibos ao
banco. A existência de um arquivo não deve ser inferida somente pela resposta
pública, e uma resposta perdida não exige repetir a operação no mesmo destino.

A custódia verifica caminhos e identidade por observações sucessivas e mantém o
FD do arquivo reservado. Essas verificações não constituem uma operação `openat`
atômica sobre toda a árvore. O diretório privado e seus ancestrais continuam parte
da fronteira de acesso do ambiente.

## Prova isolada

O caminho de CI exige `GITHUB_ACTIONS=true`, `APP_ENV=testing` e
`SITES_PROJECT_INTAKE_RECOVERY_CLI_CI=1`; limita o alvo a `localhost:55449`, banco
`postgres`, endereço observado `127.0.0.1` e logins `pirr_a_reader/pirr_b_reader`.
OID e hash da CA continuam obrigatórios. A fixture só é acionada com a cadeia
anterior, incluindo o opt-in de recebimento de projetos.

O [workflow dedicado](../.github/workflows/sites-project-intake-recovery-cli.yml)
reutiliza o laboratório TLS e os três recibos do receiver, preservando os dez
projetos e as verificações anteriores. Aplica temporariamente a migration 15,
exercita a CLI com leitores próprios e a remove antes de continuar os demais
contratos. O mapa devolvido é decodificado dos três arquivos recuperados e
conferido contra o native15 original; seus siteRefs alimentam os snapshots,
renderização e registros posteriores. A fixture confirmou **30 checks novos/1208
registry totais**, preservando os **29 do receiver** e **119 próprios da composição**. Os arquivos e recibos do receiver permanecem intactos. O cleanup possui
recibo de ownership próprio e valida o inventário completo antes de remover
somente os caminhos declarados da recuperação.

O caso `lost` já teve replay explícito no predecessor. A nova prova verifica uma
leitura independente depois da revogação do receiver, sem outra chamada ao writer
durante a recuperação. O offline confirmou **83 checks**, sem simular sucesso SQL. A suíte geral
confirmou **296 testes/4705 assertions/27 skips**, e os **32 checks sintéticos**
passaram. A prova CLI própria da #532 está registrada abaixo.

Os quatro pins consumidos pelo Portal permanecem intactos. Transporte Portal,
produtor oficial de briefing, aceite, publicação e dez gates operacionais
continuam com suas evidências próprias.

## Evidência final da #532

A [#532](https://github.com/rafhael100/imagine-sites/pull/532), HEAD
`6da154611d082f7f7fe4aab87f17c9857403ed02`, concluiu **12/12 checks SUCCESS**.
O [registry 44382215425](https://github.com/rafhael100/imagine-sites/actions/runs/44382215425),
jobs **135077280635 / 135077280347**, confirmou em PHP 8.3/8.4 **30 novas/1208
totais**, **83/77/123 checks offline**, a continuidade pelos três recibos
recuperados e zero append durante a consulta. A migration 15 foi removida antes
de retomar a composição; seu rollback anterior de catorze migrations permaneceu
intacto. Cleanup da recuperação antes do receiver, demais auxiliares e clusters
inativos confirmados nos dois jobs.

O [Laravel 44382215237](https://github.com/rafhael100/imagine-sites/actions/runs/44382215237),
jobs **135077279527 / 135077278881**, confirmou **296/4705/27** e **296/4717/21**
nas duas versões. Não houve nova execução HTTP, F383 ou workflow de containers.
O [ensaio posterior com o consumidor nativo Portal](reviews/SITES-PORTAL-INTAKE-RECOVERY-2026-11-18.md)
foi validado na #533 em PHP 8.3/8.4 com **13 checks Sites, 20 Portal e 216
assertions nativas**, estado final, rollback15 e cleanup. É uma prova separada:
não altera esta CLI nem o total 1208.
