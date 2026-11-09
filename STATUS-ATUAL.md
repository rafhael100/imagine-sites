# STATUS-ATUAL — Imagine Sites Engine

Data 2026-11-08, F1-10 branch filha de F1-09. PRs anteriores #1..#9 continuam draft sem merges, CI sintético aprovado nos SHAs verificados.

F1-08: PostgreSQL real descartável, release/aceite/observação fictícios isolados (CI PASS).
F1-09: prova simulada da publicação e transições de estados sem rollback automático (CI PASS).
F1-10: dois serviços PostgreSQL descartáveis no mesmo runner; ingress com vínculo CRM versionado, T0+432h, unicidade, bloqueio de reenvio e teste de fallback fictício. CI a conferir.

**Ainda bloqueado:** publicação real, proveniência dos recibos, autenticação S2S, direitos/revogação de clientes, retenção de dados reais, captação redundante física, confirmação do CRM, agentes e VPS. P0/RF-PEND/RV-CT/ANPD seguem abertos, AGI-11-RB01 desabilitado. Sem merges/deploy/custos adicionais.
