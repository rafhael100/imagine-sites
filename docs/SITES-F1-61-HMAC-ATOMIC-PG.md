# F1-61 — HMAC v2 verificada + revalidação atômica do Portal (laboratório)

F1-50 já validava o HMAC canônico de uma mensagem Sites→Portal, mas usava duas operações: consulta à fonte de autorização e gravação de nonce. F1-60 demonstrou uma função PostgreSQL que revalida autorização e consome nonce sob locks da mesma base. Este PR liga as duas provas em uma **única chamada PHP de laboratório**.

`LabAtomicReviewHandoffVerifier::verify` aceita apenas um PDO PostgreSQL de role restrita `sites_review_tenant_a/b`, banco descartável `sites_auth_replay_lab`, CLI e flags explícitas. Ele delega a validação criptográfica e de candidate ao código já testado `SyntheticReviewHandoffEpochEnvelope::verify`. Antes da chamada ao ledger, a fonte SQL consulta o estado corrente do recurso. Então um replay adapter privado chama `auth_replay.consume_current_review_v2` com exatamente empresa/site/workflow/revisão/release/digest, `authorizationRevision` assinada e nonce do MESMO envelope. O banco faz a **rechecagem final autoritativa** sob bloqueio compartilhado com operações de revogação e grava o nonce na mesma transação.

Testes PHP 8.3/8.4 em PostgreSQL 18 real descartável cobrem:
- mensagem HMAC válida, nonce único e resposta **informativa, não publicável**;
- HMAC adulterado e até payload falso com MAC artificialmente recalculada;
- cross-tenant e roles independentes;
- revogação concorrente entre a leitura simples e a barreira atômica;
- reativação exigindo nova revisão assinada, e rejeição de workflow alterado;
- ausência de flag de teste, owner privilegiado e replays.

Esta demonstração **não** autentica serviços reais, não oferece credenciais reais, não garante atomicidade entre os bancos independentes Sites/Portal, nem representa aceite do cliente. Para produção, a decisão final deverá residir em uma autoridade Portal autenticada com anti-replay durável; a interface interserviços será homologada após ambientes, segredos e contratos oficiais. Sem deploy, dados reais, Cloudflare, VPS, gastos, `main` ou `dev`.

Gates P0/RF-PEND/RV-CT, OP01/OP02, LGPD/AppSec e readiness permanecem BLOCKED.
