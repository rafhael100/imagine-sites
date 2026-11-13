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
- [PR #48](https://github.com/rafhael100/imagine-sites/pull/48) — F1-32, histórico append-only PostgreSQL 18 descartável, RLS, versão/digest, revogação sintética e relógio DB. **CI PHP/Laravel, RLS, PostgreSQL e safety guard PASS; integrado em `dev` (`d15514a`).** Não é persistência operacional.
- [PR #49](https://github.com/rafhael100/imagine-sites/pull/49) — F1-33, outbox de proposta de revisão Portal com digest/revisão exatos, ACL append-only e negativas. **CI Postgres/PHP/Laravel/safety guard PASS após correção de teste; integrada em `dev` (`8562009`).** Sem dispatcher.
- [PR #50](https://github.com/rafhael100/imagine-sites/pull/50) — F1-34, view com `security_invoker`/RLS que oculta propostas de revisão obsoletas e preserva histórico append-only. **CI PostgreSQL 18, RLS, Laravel e safety guard PASS; merge em `dev` (`4329440`).** Não é dispatcher nem autorização oficial.
- [PR #52](https://github.com/rafhael100/imagine-sites/pull/52) — F1-35, claim único SQL de proposta de revisão atual, com RLS, versão, digest, revogação e unicidade. **5 workflows CI PASS; integrado em `dev` (`7cd20e8`).** Sem dispatch real nem aceite.
- [PR #53](https://github.com/rafhael100/imagine-sites/pull/53) — F1-36, créditos fictícios em Postgres descartável com quota transacional e teste concorrente de duas conexões. CI PostgreSQL, PHP/Laravel e safety guard PASS; merge em `dev` (`c9a14fe`). Não é gasto real.
- [PR #54](https://github.com/rafhael100/imagine-sites/pull/54) — F1-37, comando Laravel CLI de teste gera Onepage/Landing fictícias, pacote privado e proposta Portal com digest exato, sem aceitação nem publicação. CI PHP 8.3/8.4, Laravel, bundle e safety guard PASS; merge em `dev` (`a8d26fc`).
- [SITES-HOMOLOGATION-ENTRY](docs/SITES-HOMOLOGATION-ENTRY-2026-11-09.md) — matriz de entrada e bloqueadores com issues #33–#38.

## 13/11/2026 — persistência em banco descartável, sem integração operacional

- [PR #444](https://github.com/rafhael100/imagine-sites/pull/444) — F1-360: admissão SQL ligada a tenant autenticado, idempotência, transação atômica com outbox e rollback; F1-361: worker SQL restrito, lease, reentrega e ACK por token; F1-362: código candidato do Portal e do Sites com provas assinadas chega ao PostgreSQL real, negando tenant divergente. **CI PostgreSQL18 PHP8.3/8.4 PASS** [run #43512452420](https://github.com/rafhael100/imagine-sites/actions/runs/43512452420). Todos em banco efêmero.
- F1-363: nonce de replay persistente entre workers, na mesma transação da admissão/outbox, **CI PostgreSQL18 PHP8.3/8.4 PASS** [run #43513143395](https://github.com/rafhael100/imagine-sites/actions/runs/43513143395).
- **F1-364** — novo componente LabTransactionalSignedIngressV1 de laboratório encapsula verificação de assinatura e atestação, nonce durável e admissão/outbox na mesma transação, com COMMIT/ROLLBACK controlado. Testes de duas contratações, replay, conflito entre tenant assinado e login SQL, falha de outbox e bloqueio por flag/nested transaction: **CI PostgreSQL18 PHP8.3/8.4 PASS** [run #43514102206](https://github.com/rafhael100/imagine-sites/actions/runs/43514102206). Não é endpoint operacional.
- A F1-362 apenas utiliza código candidato pinado do Portal e do PR #443; nenhum serviço HTTP real, pagamento, credencial operacional, fonte independente de revogação, backup/restore ou ambiente homologado foi ativado. PR #444 segue **DRAFT/NO-GO**, com main/dev/VPS intactos, 10/10 gates ainda falsos.

## 13/11/2026 — fluxo HTTP bidirecional em laboratório, sem VPS

- [PR #445](https://github.com/rafhael100/imagine-sites/pull/445), F1-365: comando fictício assinado pelo código candidato Portal atravessa HTTP 127.0.0.1 até trabalhador Sites PHP separado, com nonce/admissão/outbox no PostgreSQL18. **CI PHP8.3/8.4 PASS** [#43516241272](https://github.com/rafhael100/imagine-sites/actions/runs/43516241272).
- [PR #446](https://github.com/rafhael100/imagine-sites/pull/446), F1-366: outbox do Sites para mock HTTP do Portal, MAC de recibo, ACK por lease só após confirmação válida, falhas offline/HTTP503/recibo falso. **CI PASS** [#43516901253](https://github.com/rafhael100/imagine-sites/actions/runs/43516901253).
- **F1-367** no mesmo PR #446: dois contêineres PostgreSQL18 descartáveis e independentes por instância, um do Sites e outro do receptor fictício Portal. Recibo só é assinado depois de COMMIT do receptor; testes de rollback, reentrega, conflito e deduplicação. **CI PHP8.3/8.4 PASS** [#43517567819](https://github.com/rafhael100/imagine-sites/actions/runs/43517567819).
- **Sem promoção operacional:** as provas são somente entre processos/contêineres efêmeros do GitHub; não são as instâncias Portal/Sites/CRM da VPS, não usam pagamento, IAM ou vault reais e não testam DR externo. PRs empilhados #444→#445→#446 permanecem DRAFT/NO-GO, sem merge/deploy. Os dez gates continuam bloqueados.

## 13/11/2026 — recuperação após perda HTTP, sem integração operacional

- [PR #448](https://github.com/rafhael100/imagine-sites/pull/448), F1-368: receptor Portal de teste confirma um recibo em PG18 independente mas simula perda da resposta HTTP. Sites conserva o evento sem ACK, o CI reinicia o processo receptor e outro trabalhador recupera o evento por estado persistido com novo lease; Portal retorna recibo assinado `already_recorded`, sem duplicar, e só então Sites confirma. **PG18 PHP 8.3/8.4 PASS** [run #43519387668](https://github.com/rafhael100/imagine-sites/actions/runs/43519387668).
- Os PRs #444 → #445 → #446 → #448 são candidatos de laboratório empilhados; todos seguem **DRAFT/NO-GO**. Isto não comprova falha de banco, restauração de backup, IAM Portal S2S, pagamento, privacidade, DNS ou HTTPS real. `main`, `dev`, VPS e Cloudflare não foram alterados; dez gates ainda BLOCKED.

**Ainda não há ambiente operacional Sites publicado.** P0/RF-PEND/RV-CT/AppSec/LGPD, contratos financeiros, identidade, Cloudflare/DNS, banco produtivo, geração real por IA/n8n, captação/CRM fisicamente redundante e backups/restore continuam não homologados.

**Gate exclusivo do responsável:** conexão autorizada de ambiente Leaseweb + Cloudflare e domínio técnico de teste, com gestão segura de credenciais/quotas, antes de qualquer homologação real. Não inserir credenciais no GitHub público ou neste chat.

`config/operational-readiness.json` continua com gates BLOCKED. `AGI-11-RB01` DESABILITADO; sem promoção de `dev` para `main`, deploy, DNS de cliente ou dados reais.
