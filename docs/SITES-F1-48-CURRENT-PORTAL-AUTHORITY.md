# F1-48 — Fonte independente e atual para autorizar proposta de revisão

Uma HMAC válida **não é permissão**, e o estado informado no envelope não pode determinar a autorização. Esta etapa implementa um modelo de contrato para recuperar um **snapshot autoritativo atual** de fonte independente do Portal, usado apenas na decisão de encaminhar uma proposta informativa de revisão. Não implementa conexão real, IAM ou sessão.

Classes: `CurrentPortalReviewSnapshot` (empresa, site, fluxo, revisão, release, digest, versão de autorização, situação e validade até 30s), `CurrentPortalReviewSnapshotSource` (somente interface), `DenyingPortalReviewSnapshotSource` (padrão nega), e `SnapshotBoundReviewAuthorityGateway` (compara campos exatos, estado vigente e tempo com relógio do processo). O verificador F1-45 valida assinatura e snapshot local antes do gateway e consome nonce **somente depois** de passar nele.

Testes exigem snapshot independente para admitir **somente** a proposta em laboratório; verificam cross-tenant, cross-site, fluxo, release, revisão, hash, revogação, suspensão, resposta indisponível, snapshot vencido ou do futuro, replay, alteração de rascunho e tentativa de elevar flags de publicação/aceite. O teste usa fonte em memória *somente para exemplificar*; uma fonte real deverá usar identidade de serviço autenticada e autorização de cliente/contrato atual.

**Atenção:** a versão `authorizationRevision` está no snapshot confiável, mas ainda não existe um fence operacional que garanta atomicidade entre consulta e consumo do nonce. Esta implementação **não é resistente a toda corrida de revogação** e não pode ser usada como gateway de produção. Outbox, revogação, idempotência e monotonicidade no armazenamento autoritativo precisam de homologação posterior.

Mantém gates P0/RF-PEND/RV-CT, segurança e LGPD abertos; `main`, `dev`, VPS, Cloudflare, API real, credenciais e dados pessoais intocados. PR draft empilhado sobre #65.
