# Consulta exata do recibo nativo de projeto

**18/11/2026 — candidata sobre a #530, sem ativação operacional.**
A consulta recupera o recibo de uma solicitação já recebida pelo Sites a partir
do comando original. É útil quando a resposta ou o arquivo privado se perde e
a capacidade do receptor original já foi revogada. Um leitor com autorização
corrente pode obter o `siteRef` persistido sem repetir a operação de recebimento.

## Contrato de aplicação

[`StoredProjectIntakeReceiptReader`](../apps/sites/app/Integration/StoredProjectIntakeReceiptReader.php)
recebe uma conexão Laravel dedicada e oferece:

```php
public function readExact(SiteProjectCommand $command): array;
```

A entrada conserva os oito campos existentes: `schemaVersion`, `operationId`,
`tenantRef`, `orderRef`, `contractRef`, `contractRevision`, `productCode` e
`idempotencyKey`. O chamador precisa conhecer esse comando; não precisa fornecer
o `siteRef` que pode ter sido perdido. A revisão é um inteiro entre 1 e
2.147.483.647, e os produtos continuam `onepage` e `landing_page` no contrato Sites.
Essa cobertura não afirma que ambos já possuam um exportador oficial do Portal.

O serviço usa a PDO principal e uma transação independente somente leitura,
reaproveitando a fronteira transacional dos serviços nativos. Exige a sessão
previamente configurada com `default_transaction_read_only=on`, PostgreSQL 18,
UTF8, READ COMMITTED, `role=none` e exceções de PDO. Confere a mesma conexão e
o runtime antes e depois da consulta, com uma tentativa, `lock_timeout=1500ms`
e `statement_timeout=5000ms`. O chamador continua responsável por construir e
autenticar a conexão apropriada; esta classe não descobre endpoints ou credenciais.

A seleção precisa retornar exatamente uma linha com os nove campos SQL nativos.
Tipos, campos, UUID, digest e correspondência integral com o comando são
conferidos antes do resultado. `operationId` e `idempotencyKey` são comparados
explicitamente: o fingerprint semântico exclui essas duas identidades.

Ausência, seleção divergente, acesso recusado e erro de conexão retornam a mesma
`DomainException('Sites project intake receipt unavailable.')`, sem exceção
anterior ou diagnóstico SQL. O serviço não retorna um resultado parcial.

## Recibo preservado

O resultado tem os mesmos quinze campos do contrato consumido pelo Portal:

```text
schemaVersion, tenantRef, orderRef, contractRef, contractRevision,
productCode, operationId, idempotencyKey, siteRef, commandDigest,
status, state, authorityVerified, clientAccepted, canPublish
```

`schemaVersion` permanece `sites.project-intake.receipt.v1`, `status` é
`recorded`, `state` é `awaiting_official_authority` e os três indicadores são
`false`. Trata-se do recebimento histórico. Ele não identifica qual chamada
criou o registro, não decide se outra chamada deve ser repetida e não descreve
o estado comercial ou editorial atual do projeto.

Os quatro arquivos fixados pelo consumidor Portal permanecem inalterados:

- [Comando](../src/Integration/SiteProjectCommand.php).
- [Recorder](../apps/sites/app/Integration/PortalProjectIntakeRecorder.php).
- [SQL001 de recebimento](../contracts/portal-sites/001-project-intake.sql).
- [Schema do recibo nativo](../contracts/portal-sites/v1/project-intake-receipt.schema.json).

## API SQL e autorização de leitura

O [contrato aditivo SQL003](../contracts/portal-sites/003-project-intake-receipts.sql)
define `sites_core.read_portal_project_intake_receipt` com os mesmos oito
argumentos tipados do recebimento. Todos os seletores precisam corresponder ao
registro; não há seleção por último item, listagem ou descoberta de referências.

O tenant interno vem de `session_user` e de seu binding corrente `active` e
`can_read`. A referência Portal precisa coincidir com o mapeamento da organização.
O login precisa de EXECUTE exclusivo na nova função; `can_read` sozinho não é
uma concessão de acesso. A capacidade `can_receive_project` não é usada para
autorizar esta consulta. Não há epoch ou expiração no modelo nativo de binding.

O definer `sites_core_intake_reader` é NOLOGIN e possui somente SELECT nas três
tabelas necessárias: bindings, organizações e recibos. Três políticas próprias
mantêm FORCE RLS. A função verifica o perfil do chamador, exclusividade da API,
permissões do definer e integridade dos objetos utilizados. Não consulta o estado
mutável do projeto, contrato ou outbox para reescrever o significado do recibo.

### Momento da autorização

A função é `STABLE`: autorização e recibo são observados no mesmo snapshot,
estabelecido no início da instrução. Uma revogação confirmada antes de uma nova
consulta READ COMMITTED impede essa consulta. Uma revogação posterior ao início
não invalida retroativamente aquele snapshot; a leitura não retém um lock de
autorização até o fim da transação. Esse limite segue a
[semântica de volatilidade do PostgreSQL 18](https://www.postgresql.org/docs/18/xfunc-volatility.html).

## Migration e recuperação

A [migration 15](../apps/sites/database/migrations/2026_11_18_000015_add_portal_project_intake_reader.php)
instala somente a função, o definer, os grants internos e suas políticas.
Nenhum LOGIN ou binding ativo é criado. O rollback remove os objetos próprios
e preserva os contratos, projetos, eventos, recibos e bindings existentes.
A reaplicação não restitui EXECUTE a identidades que haviam recebido grants.

O leitor pode ser usado para apurar um recibo cujo arquivo não foi concluído
pela [CLI de recebimento](PROJECT-INTAKE-RECEIVER-CLI.md). Essa CLI continua
mantendo seus destinos e regras de replay. A consulta nova devolve o resultado
em memória; não cria transporte Portal, rota HTTP ou arquivo de recuperação.
Essas entradas terão de conservar a mesma autorização e o contrato privado.

## Evidência desta candidata

O grupo novo de aplicação confirmou **10 testes/341 assertions** em PHP 8.4.27,
usando resultados/PDO desconectados e o wrapper transacional real do Laravel.
Ele verifica contrato, cardinalidade, seleção, tipos, modo de leitura, troca
de PDO e falhas limitadas, sem simular autorização ou persistência PostgreSQL.
A suíte geral local confirmou **296 testes/4705 assertions/27 skips**, e os
**32 checks sintéticos** passaram. A revisão integral do PHP, SQL e migration
não encontrou bloqueadores; os quatro pins Portal permanecem idênticos.

A [fixture PostgreSQL](../tests/fixtures/project-intake-receipt/contract.php)
prevê **53 verificações próprias**, separadas das 65 anteriores, no
[workflow F383 existente](../.github/workflows/sites-f1-383-operational-schema.yml).
Reutiliza
recibos nativos já gravados e o receptor A já revogado, acrescentando leitores
separados e snapshots completos de estado. O ensaio anterior de resposta perdida
já contém um replay; a consulta nova deve demonstrar ausência de chamadas ao writer
e de novos registros durante sua própria execução. Isolamento, seletores,
revogação entre chamadas e down/up da migration terão evidência própria.
Os resultados PostgreSQL desta candidata permanecem pendentes de CI.
