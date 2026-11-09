# STATUS-ATUAL — Imagine Sites Engine

**Referência:** 2026-11-09, F1-15 em branch filha do F1-14, sem merge/deploy.

F1-01–F1-13: motor independente Laravel 13 inicial, contrato e testes mockados, PostgreSQL/RLS em banco descartável, geração estática sintética, publicação simulada, captação dupla fictícia e HTTP interno de teste; PRs dependentes e draft, CI PASS nos SHAs verificados.

F1-14: adaptador PDO de replay fail-closed em PostgreSQL descartável, com segundo CI PASS após correção de `ON CONFLICT` sem SELECT privilegiado; PR #15 draft.

F1-15: schema e testes de comando/outbox atômicos em `sites_lab`, RLS tenant, revisão de contrato, idempotência única e revogação. CI a verificar.

**Falta** autenticação S2S operacional, banco Sites e migrations produtivas, integração comercial, publisher Cloudflare, captação real com CRM e redundância física, geração por Agents, backups/restore, políticas LGPD e autorização de go-live.

P0/RF-PEND/RV-CT/AppSec abertos, AGI-11-RB01 desabilitado; não há merge, deploy, DNS, clientes reais, segredos ou custos autorizados nesta cadeia.
