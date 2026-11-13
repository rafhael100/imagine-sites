# F1-50 — Envelope de proposta v2 com revisão da autorização assinada

O formato v1 de F1-45 é um laboratório de integridade de revisão e **não traz** a revisão de autorização corrente. Uma mensagem v1 não consumida pode voltar a parecer válida após revogação + reativação, pois só traz tenant, site, workflow e SHA.

Este PR oferece uma versão **incompatível e explicitamente sintética** `sites.synthetic.review-handoff.v2`, que adiciona `authorizationRevision` ao envelope e aos bytes HMAC. O verificador exige que essa revisão seja **idêntica à versão da autorização atual** recuperada da fonte independente, além de validação do digest, release, empresa, estado, nonce e prazo. Se o status da fonte mudar para revoked e depois voltar a reviewable com revisão incrementada, a mensagem **antiga continua recusada**.

As checagens de dados e expiração foram reunidas em `SnapshotReviewBindingPolicy`; o gateway F1-48 passa a utilizar esse mesmo validador de snapshot, evitando dois conjuntos divergentes de regras.

Um JSON Schema v2 de referência documental e testes PHP 8.3/8.4 + Python verificam campos, cenário de revogação-reativação, digest, nonce, atraso, permissões falsas, cross-tenant, replay, falta de fonte atual e fluxo revisto.

**Atenção:** ainda existe risco de corrida revogação/nonce entre operações não transacionais; a fonte atual é de laboratório e a chave HMAC não autentica identidade de serviço. O assinante precisa receber a revisão autorizada por canal confiável, a consulta real precisa ser atômica ou protegida por fence durável e as políticas não fazem aceite expresso de cliente nem publicação. O v1 segue apenas em testes anteriores, não aprovado para produção. Não há integração com Portal real, banco de clientes, VPS, Cloudflare, gastos ou deploy.

`main`, `dev` e readiness permanecem inalterados. PR draft sobre #69, dependente de #68/#65/#64/#63; gates P0/RF-PEND/RV-CT/AppSec/LGPD abertos.
