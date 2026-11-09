# STATUS-ATUAL — Imagine Sites Engine

**09/11/2026 — integração de desenvolvimento e entrega de critérios de homologação.**

- `dev` consolidou F1-01..22 via PRs #24–#29, mantendo `main` intacta.
- [PR #30](https://github.com/rafhael100/imagine-sites/pull/30) — F1-23, classes reais candidatas Portal↔Sites testadas entre repositórios, PHP 8.3/8.4 CI PASS e merge controlado em `dev`.
- [PR #31](https://github.com/rafhael100/imagine-sites/pull/31) — F1-24, pacote de prévia privado, hash e `publishable=false`, PHP/Laravel CI PASS e merge controlado em `dev`.
- [PR #32](https://github.com/rafhael100/imagine-sites/pull/32) — F1-25, T0 do banco, destino CRM imutável e purge de metadados fictícios em PostgreSQL efêmero, CI PASS e merge controlado em `dev`.
- [PR #40](https://github.com/rafhael100/imagine-sites/pull/40) — F1-26: rotação sintética de chave com carência de até 120s, DENY de chave anterior e replay compartilhado. **CI PHP 8.3/8.4, Laravel e safety guard PASS em `e33a4af`; merge controlado em `dev` no commit `82e63a1`.** Não é identidade operacional nem fecha OP01.
- [PR #42](https://github.com/rafhael100/imagine-sites/pull/42) — F1-27, auditoria offline de HTML sintético Onepage/Landing, bloqueio de conteúdo ativo/externo e negativos após recálculo de hashes. **CI PHP 8.3/8.4, Laravel, F1-24 e safety guard PASS.** Sem publicação; OP03/OP05 permanecem abertos.
- **F1-28 em homologação de código:** fluxo sintético de rascunho → revisão interna Imagine → proposta não publicável de revisão Portal, com limite de tentativas, revisões imutáveis e nenhum aceite autogerado. Aguardando CI/merge em dev; sem fechar OP03/OP05.
- [SITES-HOMOLOGATION-ENTRY](docs/SITES-HOMOLOGATION-ENTRY-2026-11-09.md) — matriz de entrada e bloqueadores com issues #33–#38.

**Ainda não há ambiente operacional Sites publicado.** P0/RF-PEND/RV-CT/AppSec/LGPD, contratos financeiros, identidade, Cloudflare/DNS, banco produtivo, geração real por IA/n8n, captação/CRM fisicamente redundante e backups/restore continuam não homologados.

**Gate exclusivo do responsável:** conexão autorizada de ambiente Leaseweb + Cloudflare e domínio técnico de teste, com gestão segura de credenciais/quotas, antes de qualquer homologação real. Não inserir credenciais no GitHub público ou neste chat.

`config/operational-readiness.json` continua com gates BLOCKED. `AGI-11-RB01` DESABILITADO; sem promoção de `dev` para `main`, deploy, DNS de cliente ou dados reais.
