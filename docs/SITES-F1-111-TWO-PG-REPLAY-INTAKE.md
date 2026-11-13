# F1-111 — Verificação integrada com duas bases PostgreSQL 18 descartáveis

## Objetivo

A F1-110 encadeia assinatura fictícia, prevenção de replay, autorização candidata e admissão transacional de projetos, mas seu primeiro teste usa um controle de nonce **em memória**. A F1-111 valida a mesma cadeia contra o mecanismo `LabPostgresReplayStore` já desenvolvido em F1-14, comprovando que um segundo processo não consegue reaproveitar a mesma mensagem enquanto o projeto é gravado em outra base.

## Implementação do laboratório

O workflow `.github/workflows/sites-f1-94-project-admission.yml` passa a criar, no **mesmo serviço PostgreSQL 18 descartável de CI**, duas bases com finalidades distintas:

1. `sites_f94_lab` — projeto e admissão do motor Sites, com RLS e login restrito `sites_f94_app`.
2. `sites_auth_replay_lab` — registros únicos e append-only de nonces, com schema `labs/security/f1-12-nonce-schema.sql`, role `sites_auth_runtime` sem LOGIN e permissão somente INSERT.

Em `tests/f111-pg-durable-signed-intake.php`, duas conexões independentes fazem `SET ROLE sites_auth_runtime` dentro da base descartável. O adaptador F1-14 rejeita qualquer conexão privilegiada ou outra base/role. Cada instância de `LabSignedProjectIntake` usa seu `ReplayStore` PostgreSQL e a mesma conta de aplicação restrita para a admissão de projeto.

A suíte comprova: flag desativada, primeiro nonce permitido, segundo processo recusando o mesmo nonce, nova mensagem assinada produzindo replay idempotente sem duplicar projeto, adulteração de empresa rejeitada antes de consumir o nonce, revogação de autoridade fictícia, nonce consumido mesmo quando a operação é negada, recusa de outra empresa/pedido e novo nonce após restabelecer a autorização.

## Garantias e limites

A unicidade de nonce vale entre conexões PostgreSQL **enquanto o banco descartável do teste existe**. Essa prova não equivale à implantação de um armazenamento durável, replicado, monitorado e com backups. O nonce é consumido antes da transação de admissão em outra base: falha da admissão exige **um novo nonce assinado**; não há transação distribuída.

Os testes utilizam senha pública fictícia, role com `SET ROLE` a partir do administrador somente no CI, assinaturas HMAC fictícias e gateway de autorização de pedido em memória. Não existe integração real com IAM do Portal, controle de segredo, endpoint HTTP, cliente, aceite, plano comercial, CRM ou publicação. A GUC tenant do PostgreSQL não é uma identidade confiável quando existe acesso SQL arbitrário.

**10/10 gates operacionais BLOCKED.** Sem merge, deploy, VPS, Cloudflare, domínio, dados reais ou custo novo.
