# STATUS-ATUAL — Imagine Sites Engine

Referência 2026-11-08. F1-12 PostgreSQL replay fence em laboratório, branch filha de F1-11.

F1-08: PostgreSQL 18 descartável, release/aceite/observação e revogação (PR #8, CI PASS).
F1-09: máquina de estados de publicação, prova independente sintética, sem rollback (PR #9, CI PASS).
F1-10: duas bases de captação fictícias, regras de vínculo CRM, T0+432h (PR #10, CI PASS).
F1-11: HMAC CreateProject v1, issuer/audience, TTL e negação padrão de replay; paridade validada com fixture do Portal (PR #11, CI PASS). Portal PR #105 assina fixture equivalente, CI PASS.
F1-12: schema descartável para replay fence durável com RLS/GRANT mínimo e concorrência, CI a verificar.

**Limites:** nenhum banco persistente de clientes, chave de produção, API HTTP real, homologação de CRM, IA/n8n, Cloudflare, DNS, publicação, VPS, backup/restore ou deploy. Contratos P0/RF-PEND/RV-CT permanecem abertos; AGI-11-RB01 desabilitado. Todos PRs draft sem merges.
