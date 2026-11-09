# STATUS-ATUAL — Imagine Sites Engine

**09/11/2026 — integração de desenvolvimento e entrega de critérios de homologação.**

- `dev` consolidou F1-01..22 via PRs #24–#29, mantendo `main` intacta.
- [PR #30](https://github.com/rafhael100/imagine-sites/pull/30) — F1-23, classes reais candidatas Portal↔Sites testadas entre repositórios, PHP 8.3/8.4 CI PASS e merge controlado em `dev`.
- [PR #31](https://github.com/rafhael100/imagine-sites/pull/31) — F1-24, pacote de prévia privado, hash e `publishable=false`, PHP/Laravel CI PASS e merge controlado em `dev`.
- [PR #32](https://github.com/rafhael100/imagine-sites/pull/32) — F1-25, T0 do banco, destino CRM imutável e purge de metadados fictícios em PostgreSQL efêmero, CI PASS e merge controlado em `dev`.
- [PR #40](https://github.com/rafhael100/imagine-sites/pull/40) — F1-26: rotação sintética de chave com carência de até 120s, DENY de chave anterior e replay compartilhado. **CI PHP 8.3/8.4, Laravel e safety guard PASS em `e33a4af`; merge controlado em `dev` no commit `82e63a1`.** Não é identidade operacional nem fecha OP01.
- [PR #42](https://github.com/rafhael100/imagine-sites/pull/42) — F1-27, auditoria offline de HTML sintético Onepage/Landing, bloqueio de conteúdo ativo/externo e negativos após recálculo de hashes. **CI PHP 8.3/8.4, Laravel, F1-24 e safety guard PASS.** Sem publicação; OP03/OP05 permanecem abertos.
- [PR #43](https://github.com/rafhael100/imagine-sites/pull/43) — F1-28, fluxo sintético de produção/revisão Imagine e proposta não publicável ao Portal. PHP 8.3/8.4, Laravel e safety guard PASS, integrado em `dev` (`e4b631d`). **Não concede identidade/aceite ou autorização de produção.**
- [PR #44](https://github.com/rafhael100/imagine-sites/pull/44) — F1-29, estrutura estrita para proposta textual fictícia de IA com marca/produto definidos fora do gerador. CI PHP 8.3/8.4, Laravel e safety guard PASS; integrado em dev (`3fab91d`). Sem executar IA real.
- [PR #45](https://github.com/rafhael100/imagine-sites/pull/45) — F1-30, créditos abstratos e reservas idempotentes por tenant/operação com negação por quota. CI PHP 8.3/8.4, Laravel e safety guard PASS; merge em `dev` (`6048da6`). **Não é controle de custos reais.**
- [PR #46](https://github.com/rafhael100/imagine-sites/pull/46) — F1-31, teste offline encadeado F1-27..30 e validação estrita de proposta de revisão Portal. Sem aceite oficial, publicação ou ENTREGA. CI PHP 8.3/8.4, Laravel e safety guard PASS; integrado em `dev` (`025707d`).
- **F1-32 em validação:** histórico append-only de produção/revisões em PostgreSQL de laboratório, com versionamento, RLS, relógio do banco e recusa de edições após revogação. Aguardar CI antes de integrar em `dev`; não é persistência operacional nem autorização real.
- [SITES-HOMOLOGATION-ENTRY](docs/SITES-HOMOLOGATION-ENTRY-2026-11-09.md) — matriz de entrada e bloqueadores com issues #33–#38.

**Ainda não há ambiente operacional Sites publicado.** P0/RF-PEND/RV-CT/AppSec/LGPD, contratos financeiros, identidade, Cloudflare/DNS, banco produtivo, geração real por IA/n8n, captação/CRM fisicamente redundante e backups/restore continuam não homologados.

**Gate exclusivo do responsável:** conexão autorizada de ambiente Leaseweb + Cloudflare e domínio técnico de teste, com gestão segura de credenciais/quotas, antes de qualquer homologação real. Não inserir credenciais no GitHub público ou neste chat.

`config/operational-readiness.json` continua com gates BLOCKED. `AGI-11-RB01` DESABILITADO; sem promoção de `dev` para `main`, deploy, DNS de cliente ou dados reais.
