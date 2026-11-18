# Recuperação privada de recibo de solicitação técnica — Imagine Sites

**Candidata isolada de 18/11/2026.** O diário já oferece consulta do histórico
pelos cinco seletores originais na PR #536. Esta entrega acrescenta uma CLI
somente leitura para recuperar o `operationRef` quando o arquivo de recibo
da solicitação original se perdeu após o commit. **Não faz reenvio, publicação
ou nova tentativa.**

## Entrada

```bash
php ops/publication-candidate/recover-request.php plan
php ops/publication-candidate/recover-request.php recover \
  --plan-sha256=SHA256_DO_PLANO_REVISADO \
  --authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED
```

A CLI utiliza **somente três entradas privadas fixas**:

| Montagem | Conteúdo |
| --- | --- |
| `/run/secrets/publication_request_recovery` | Envelope original de pedido (16.384 bytes) |
| `/run/secrets/sites_publication_request_recovery_reader` | Senha de um **leitor independente**, modo 0400/0600 (16–4096 bytes) |
| `/run/secrets/sites_publication_request_recovery_ca` | CA para PostgreSQL verify-full, com SHA-256 fixado (até 65.536 bytes) |

O manifesto reutiliza o schema **`SITES_PUBLICATION_REQUEST_V1`** e os
campos `schema`, `target`, `siteRef`, `releaseRef`,
`expectedBindingRevision`, `portalAcceptanceRef`, `idempotencyKey`,
`receiptRef`. O target deste comando deve apontar para um **leitor de
recuperação** (não o writer do comando original). O nome `receiptRef`
identifica exclusivamente o **novo recibo de recuperação** e não é o
caminho do recibo perdido. Nenhum caminho, chave, login, tenant ou UUID
alternativo é aceito na linha de comando.

A validação e a custódia usam os parsers e o leitor de arquivos existentes:
bytes, hash, metadados e identidade conferidos antes e depois. O banco é
PostgreSQL18, conexão nova, `sslmode=verify-full`, CA observada, OID/endereço/
porta de servidor pinados, `search_path=pg_catalog`, READ COMMITTED,
`row_security=on` e `default_transaction_read_only=on`. O login só
pode executar `sites_publication.read_request_operation(uuid,text,bigint,text,text)`;
nenhuma tabela, API mutável, outro reader ou grant é adquirido.

## Planejar e recuperar

`plan` não consulta o diário nem reserva arquivo: valida entradas,
identidade do banco e a disponibilidade do caminho de saída, devolvendo
`schema`, `status`, `planSha256`, `expectedBindingRevision` e dois
indicadores falsos. O `planSha256` vincula o envelope completo, incluindo
o target, nome do arquivo e os cinco seletores, por domínio separado do
plano de **solicitação**. Não é credencial nem reserva de autoridade.

`recover` exige a confirmação literal e o hash do plano atual. O arquivo
é reservado com `x+b` exclusivo, permissão 0600 e descriptor mantido. A
única leitura SQL (`PgPublicationJournal::readRequest`) retorna o snapshot
histórico persistido, com os mesmos 24 campos. A CLI verifica a identidade,
permite apenas a projeção pública limitada e grava no novo arquivo:

```json
{"schema":"SITES_PUBLICATION_REQUEST_RECOVERY_RECEIPT_V1","planSha256":"<64-hex-lowercase>","operationRef":"<uuid-privado>"}
```

Esse exemplo descreve **formato**, não uma operação existente. O diretório
de saída é exclusivamente `/var/lib/imagine-sites/publication-recoveries`,
preexistente, privativo (0700) e separado das solicitações originais.
Não se cria diretório automaticamente. O arquivo é sincronizado, relido e
conferido; nunca sobrescrito, removido ou reparado em falha.

O resultado em stdout contém `schema`, `status=recovered`,
`planSha256`, estado técnico, revisão, tentativa, revisão do vínculo,
digest do artefato, digest do recibo técnico, datas e
`canPublish=false`/`publicationVerified=false`. O `operationRef`,
`tenantRef`, referências de pedido/contrato/aceite, chave idempotente,
referência do provedor, HTML e senha **não são mostrados**.

A reserva e a escrita do arquivo não são uma transação conjunta com
a consulta. Um erro pode deixar uma reserva vazia ou um recibo parcial/
completo. Não se elimina nem reenvia automaticamente. Falha: exit 1,
stdout vazio e apenas `PUBLICATION_RECOVERY_DENIED` em stderr.
Não encontrar uma operação não prova que a solicitação anterior falhou.

## Segurança e validação

Somente `APP_ENV=staging`, sem GitHub Actions e usando banco
`sites_staging`, ou o laboratório `GITHUB_ACTIONS=true` +
`APP_ENV=testing` + `SITES_PUBLICATION_REQUEST_READER_CI=1` +
`SITES_PUBLICATION_REQUEST_RECOVERY_CI=1`,
com alvo `localhost:55449/postgres` e principal sintético restrito,
são candidatos de execução. Essa guarda não cria credenciais nem poderes.

Os testes offline cobrem recusa de parâmetros, ambiente, parsing original,
isolamento da resposta e gravação de **arquivo temporário sintético** em
recibo exclusivo, preservando o contrato antigo. O workflow próprio
executa em PHP 8.3/8.4. Uma prova PostgreSQL18 real, com leitor ativo,
revogação e perda de resposta, é necessária para afirmar homologação
completa da nova CLI; os testes da #536 validam o **leitor SQL**, mas não
substituem essa jornada do novo wrapper.

## Composição PostgreSQL executável

A branch `integration/sites-request-recovery-composition-2026-11-18`, sobre a
#539 validada, preserva o serviço, os schemas, argumentos e projeções **6/13/3**
publicados na #538. O ajuste de produto está restrito ao ramo CI: exige também
a flag do leitor e admite os leitores próprios `pjrrc_a_reader` e
`pjrrc_b_reader`, mantendo `pjrr_a_reader`. Staging conserva seu comportamento.

A [fixture completa](../tests/fixtures/publication-request-recovery/contract.php)
executa depois dos **1241 checks anteriores** e do rollback16. Instala
temporariamente a mesma migration16 e usa três operações já existentes, sem
chamar o writer ou acrescentar operação/evento. Compara o resultado público
com o snapshot nativo, descarta uma resposta e lê o UUID do arquivo privado
para alimentar a CLI de inspeção com outra identidade autorizada.

O [workflow dedicado](../.github/workflows/sites-request-recovery-composition.yml)
prevê **38 novos /1279 totais** em PHP 8.3/8.4, snapshots finais, down16 e
cleanup limitado aos recursos do laboratório. Os **63 checks offline** e os
**32 checks de domínio** passaram localmente depois dos ajustes CI. **A prova
PostgreSQL desta composição ainda está pendente.** [Casos, custódia e limites](reviews/SITES-PUBLICATION-RECOVERY-COMPOSITION-2026-11-18.md).

**Fora do escopo:** aceitar pedidos comerciais reais, autenticação Portal,
provider, publicação/rollback, integração n8n, deploy VPS, DNS, pagamento,
aceite do cliente, `ENTREGUE`, novas migrations, grants ou alterações dos
dez gates bloqueados. A mudança fica em PR draft sem merge/deploy automático.
