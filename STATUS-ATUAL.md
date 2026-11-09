# STATUS-ATUAL — Imagine Sites Engine

Referência 2026-11-08. F1-09, branch filha do F1-08.

F1-01..07: documentação, domínio, Laravel independente, contrato Portal-Sites, PostgreSQL/RLS em CI, render HTML sintético e prévia privada (PRs #1..7, CI PASS, todos draft).
F1-08: PostgreSQL 18 descartável para releases, aceites fictícios, provas de publicação e revogação (PR #8, CI PASS).
F1-09: máquina de estados de publicação puramente em memória; separa solicitação, execução, acknowledge do fornecedor, observação independente, falha e inconclusivo; sem rollback automático. CI a conferir no PR.

**Ainda não implementado:** S2S real, API/prova criptográfica, filas/outbox duráveis, CRM/captação, execução de IA, Cloudflare/DNS, storage real, backup, publicação real e entrega formal. P0/RF-PEND/RV-CT abertos, AGI-11-RB01 desabilitado. Sem merge/deploy/custos ou dados reais.
