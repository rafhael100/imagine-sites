# STATUS-ATUAL — Imagine Sites Engine

Data 2026-11-08. F1-11 laboratório de assinatura de comandos e replay. PRs #1–#10 permanecem draft; CI sintético prévio PASS.

F1-08 release/aceite/observação Postgres RLS em CI descartável (PASS).
F1-09 estados de publicação simulados, sem rollback automático (PASS).
F1-10 dois bancos de captação PostgreSQL no mesmo runner, com vínculo de CRM e T0+432h (PASS).
F1-11: HMAC-SHA256 v1 para comandos CreateProject, TTL máximo 120s, emissor/audiência, nonce e negação padrão de replay; **somente fixture com chave pública fictícia e store em memória**. CI a conferir.

Não existe integração S2S operacional nem identidade real, aprovação, pagamento, AI Agents, cloud, monitoramento ou lead pessoal. P0, RF-PEND, RV-CT e LGPD continuam abertos, AGI-11-RB01 desabilitado, sem merges/deploy/custos.
