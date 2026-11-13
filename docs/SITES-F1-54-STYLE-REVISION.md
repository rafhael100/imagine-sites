# F1-54 — Trocar o tema invalida a revisão anterior

O Imagine Sites oferece Classic, Modern e Warm para Onepage e Landing Page. Uma troca de estilo altera os bytes HTML e o SHA-256 da release, mesmo com a cópia textual idêntica. **A versão previamente revisada não pode passar a representar o novo tema.**

A classe `SyntheticStyleRevisionPlan::begin` modela somente o início de um *novo rascunho*, sem IA real, Portal, autenticação, fila ou publicação. Ela exige snapshot anterior no estado `awaiting_portal_acceptance`, retorno pela função de revisão interna, `expectedRevision` exata, origem de bytes compatíveis com `SiteBrief`, tema novo, referência de release nova e pacote inert/nonpublishable com hash correto.

O retorno é um workflow `drafting` com `artifactDigest=null`, um HTML novo e a referência nova. **Não existe aprovação automática:** o editor/agente deve submeter o draft, e a função `imagine_reviewer` (apenas rótulo de teste, não identidade autenticada) precisa aprovar novamente o hash antes de produzir qualquer candidato informativo ao Portal. O Portal mantém o aceite expresso do cliente e evento oficial `ENTREGUE`.

Testes negativos e positivos para Onepage/Landing e Modern/Warm verificam invalidação do candidato antigo no snapshot atual, no-op, digest/brief adulterado, release reaproveitada, agente tentando aprovar, versão obsoleta, limite de revisões e ausência de promoção de `publishable`.

**Limitações:** snapshots em memória são imutáveis, mas sua versão “atual” real ainda depende de armazenamento autoritativo e de revogação transacional entre serviços. A camada F1-50 continua experimental. Sem dados reais, SaaS, gastos, VPS, DNS, Cloudflare ou deploy. PR draft em cima de #74; gates P0, RF-PEND, RV-CT, AppSec/LGPD e readiness BLOCKED.
