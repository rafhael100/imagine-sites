# F1-31 — Travessia sintética F1-27..30 para revisão no Imagine Portal

O teste `php tests/f31-portal-handoff.php` executa uma cadeia offline com dados **integralmente fictícios**: conteúdo estruturado (F1-29), renderer/QA (F1-27), reserva de créditos abstratos (F1-30), rascunho→revisão interna Imagine (F1-28), pacote privado `publishable=false` (F1-24) e proposta de revisão para o Portal.

## Controle adicional

`SyntheticReviewCandidateGuard::matchesLocalSnapshot` compara, com igualdade estrita (campos, ordem, tipo e valores), uma proposta com a revisão imutável em memória. Ela nega campos extras, mudança de empresa/site/release/digest, divergência de versão, alegação de aceite do cliente, `publishable=true`, bypass da autoridade Portal e reutilização após revisão.

**Atenção:** esta comparação só informa que a proposta coincide com um objeto de laboratório. **Não é assinatura/autenticação, autorização RBAC ou prova de aceite de cliente.** O teste demonstra que, mesmo após revisão interna, o fluxo com evidência oficial ausente é recusado por `PublicationPolicy` e `DeliveryPolicy` e o publisher fictício não é despachado.

## Antes da operação

Exigir evento do Portal autenticado, identidade de serviço, aceite expresso por titular, vínculo contrato/revisão, storage e replay duráveis, autorização por tenant, observador independente de publicação e evento oficial `ENTREGUE` **somente no Portal**. O motor de Sites não pode gerar esse evento.

Nenhuma chamada à VPS, Cloudflare, APIs pagas, clientes, CRM, DNS, tokens ou deploy. Bloqueios P0/RF-PEND/RV-CT, OP03/OP05, AppSec/LGPD e `config/operational-readiness.json` permanecem ativos; `AGI-11-RB01` continua desabilitado.
