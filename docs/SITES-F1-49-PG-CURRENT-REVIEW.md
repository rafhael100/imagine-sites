# F1-49 — Consulta de autorização atual em PostgreSQL isolado + nonce de revisão

Esta etapa liga o contrato F1-48 a uma fonte de estado no **PostgreSQL 18 descartável** usada apenas em CI. Ela NÃO consulta o Portal operacional, não autentica pessoas/serviços e não concede poderes de publicação ou aceite.

O esquema `f1-49-current-review-source.sql` cria dois tenants fictícios, roles de leitura separadas `sites_review_tenant_a` e `sites_review_tenant_b`, grants controlados pelo proprietário e políticas `FORCE ROW LEVEL SECURITY`. A role de um tenant não vê o outro, não pode modificar grants ou revisar status. A origem de estado é gravada/alterada apenas pelo owner do banco de testes, representando uma futura autoridade Portal em laboratório.

`LabPostgresCurrentPortalReviewSource` exige CLI, flag `SITES_F49_PG_LAB=1`, nome exato do banco descartável e role limitada. Executa consulta parametrizada e projeta o snapshot para `SnapshotBoundReviewAuthorityGateway`, usado pelo envelope F1-45 juntamente com o replay durável F1-47.

Suíte: sucesso de proposta informativa somente para tenant A; cross-tenant recusado por RLS; nonce aceito uma vez; alteração de status no banco para `revoked` impede nova proposta; nova revisão, novo digest, revogação do grant SQL, alteração indevida por runtime e modo sem flag também são negados. Os dados são sempre ficcionais.

**Lacuna de segurança mantida:** a consulta ao estado e o consumo do nonce são passos distintos; existe janela de TOCTOU de revogação. A HMAC v1 ainda **não incorpora autorizaçãoRevision na assinatura**; deve-se adotar versão assinada com epoch e barreira transacional/consulta autoritativa para uso real. Este PR não habilita APIs, autenticação, aceite, entrega, Cloudflare, DNS, VPS nem dados reais. Nenhum custo de fornecedor adicional está autorizado.

`main` e `dev` não mudam; gates P0/RF-PEND/RV-CT, OP01/OP03/OP05, LGPD e AppSec continuam BLOCKED. PR empilhado sobre F1-48 (#68), sem merges.
