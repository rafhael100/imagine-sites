# STATUS-ATUAL — Imagine Sites Engine

**09/11/2026 — F1-22** proteção sintética contra ressurgimento de grants em restore antigo, em branch filha de F1-21.

F1-19 PR #20: serviço Laravel outbox + PostgreSQL/RLS descartável CI PASS.
F1-20 PR #21: paridade do briefing legado e aceite de release SHA exato, CI PASS.
F1-21 PR #22: readiness BLOCKED e scanner read-only/deploy guard CI PASS.
F1-22: simulação de backup de cliente restaurado após revogação, com ledger separado do conteúdo restaurado, RLS e revision floor. CI a verificar.

**Não** é prova de recuperação real: ledger fica no mesmo PostgreSQL de testes. Em produção precisa de fonte externa monotônica fora do escopo do restore, credenciais e homologação.

Sem cliente, DNS, SSL, publicação Cloudflare, CRM, IA real, VPS, deploy/merge ou autorização de go-live. P0/RF-PEND/RV-CT/LGPD/AppSec continuam abertos. AGI-11-RB01 desabilitado.
