# STATUS-ATUAL — Imagine Sites Engine

**Atualizado em:** 09/11/2026 — integração supervisionada concluída exclusivamente na branch `dev`.

## Integração de desenvolvimento efetivamente realizada

A branch `dev` foi criada a partir do commit `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e` da `main` e recebeu cinco merges controlados, com commits preservados e CI verificado:
- PR #24 — F1-01 a F1-04, SHA merge `1084c0abe12e1c597335ab87d47426ca19cc80f0`.
- PR #25 — F1-05 a F1-09, SHA merge `bd94cb87abca06a2e61d3f247bb6ed0b319ea9e1`.
- PR #26 — F1-10 a F1-13, SHA merge `d4b7c94c6c39ab86cd9c8ed38c1886e8d614bf87`.
- PR #27 — F1-14 a F1-17, fontes #15–#18, SHA merge `6050e853390b15786d37fddd5628b162b2d87480`.
- PR #28 — F1-18 a F1-22, SHA merge `1f90019a367248681ab90d2549e65ffe970dddec`.

**Resultado:** código selecionado de 22 PRs originais incorporado em `dev`. O tree SHA após o quinto merge foi confirmado igual ao HEAD do PR #23: `7b74a6c96956591c27c471bd9782eb2bc8c8017f`. O `main` permaneceu em `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.

**CI:** em cada integração, os checks aplicáveis de PHP, Laravel e laboratórios PostgreSQL/contratos passaram antes do merge. Após o quinto merge, os workflows `Sites Engine - testes sinteticos (sem deploy)`, `F1-03 Laravel isolado - sintético sem deploy` e `F1-21 CI-only operational safety guard` tiveram sucesso no push de `dev`.

O PR #14 permanece **DRAFT e NÃO integrado** por ser uma implementação alternativa concorrente do replay F1-14; o caminho selecionado nesta integração foi o PR #15. Os PRs originais #1–#13 e #15–#23 continuam abertos em draft para rastreabilidade, sem merge na `main`. Não efetuar merge deles novamente na `main`.

## Escopo do software consolidado

Motor Sites independente em Laravel 13, geração de HTML Onepage/Landing de fixtures, SHA-256 de release, contratos candidatos Portal↔Sites, guarda de autorização DENY por padrão, replay HMAC e nonce em laboratório, RLS PostgreSQL descartável, comando/outbox, simulações de publicação/prova, captação com duas bases de testes, paridade de briefing do Portal legado, controle de restore/revogação e gates de prontidão.

**Todos os mecanismos mais sensíveis seguem exclusivamente em laboratório.** Não há criação/publicação de site para cliente, credencial real, domínio, login do Portal validado, aprovação real, CRM/AI Agents/n8n operacionais, API pública, banco de produção, persistência de leads pessoais ou restauração real.

## Gates de produção não homologados

P0-DOC01–14, P0-DOC03, RF-PEND-01/RF-PEND-04, RV-CT, LGPD/AppSec, pagamentos/autoridade, domínio, segurança de APIs e custos continuam ABERTOS. `config/operational-readiness.json` mantém todos os gates BLOCKED. AGI-11-RB01 (rollback automático) DESABILITADO.

Nenhum deploy na VPS/Cloudflare, custo adicional, dados reais ou alteração de `main` ocorreu neste ciclo.

**Detalhes, evidências e próximas exigências:** `docs/SITES-DEV-CONSOLIDATION-2026-11-09.md`.
