# SITES-F1-09 — Publicação simulada com prova independente e sem rollback automático

**Status:** modelo de estados PHP puro, apenas testes sintéticos. PR filho do F1-08; sem chamadas Cloudflare, GitHub, Workers, n8n ou qualquer provedor.

Sequência validada pelo modelo:
`not_requested → requested → dispatched → acknowledged → verified/inconclusive`.
Também pode ir para `failed` a partir de requested/dispatched/acknowledged. O retorno de um serviço ou de um job indicando 200/202 é no máximo **acknowledged**; só uma observação independente **do digest e release exatos**, com autorização vigente, pode chegar ao estado técnico **verified**.

A versão previamente observada (`previouslyObservedDigest`) fica distinta da tentativa corrente. Falha em B não autoriza rollback automático de A. O estado `verified` **não** permite declarar a entrega oficial; apenas o Portal pode emitir ENTREGUE após checklist e evidências completas.

**Segurança:** `Observation` modela campos de uma futura verificação independente, mas os campos bool `verifiedByIndependentObserver` e `authorizationCurrent` são fictícios. Nenhum atacante poderá informar essas flags para se autorizar; o serviço real deve obter assinatura/recibo verificável, validar issuer/audience, revisão, expiração, revogação, integridade do conteúdo servido e evidência do fornecedor. Tudo ainda bloqueado.

**Não implementado:** conectores Cloudflare/DNS, filas duráveis/idempotência cross-worker, observabilidade, retries seguros, artifact storage privado, auditoria ou publicação real.

**Gates:** P0-DOC01..14, P0-DOC03, RF-PEND-01/RF-PEND-04, RV-CT, LGPD e homologação seguem abertos. AGI-11-RB01 rollback automático permanece DESABILITADO. Não realizar merge/deploy automático.
