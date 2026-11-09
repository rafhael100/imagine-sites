# STATUS-ATUAL — Imagine Sites Engine

**Referência:** 2026-11-08. Branch F1-03 filha de F1-02, sem deploy.

F1-01: PHP sintético release/manifesto, publicação/entrega consultiva,
T0+72/432h, ledger idempotência em memória; PR #1 draft e CI PASS no
SHA 101e416b081a9f0a1d825a635df934527bc21ddd.

F1-02: autoridade Portal por gateway que nega por padrão;
mock/testes sintéticos adversariais; PR #2 draft e CI PASS no
SHA 5e2f57ce13cf759a58fdef324839920f5ffe9013.

F1-03: fundação Laravel 13 isolada em apps/sites, /up, 404 para rotas
de negócio, PostgreSQL apenas placeholder. CI PHP 8.3/8.4 **a verificar**.
Nenhuma integração real, credenciais, DB, clientes, APIs ou deploy.

P0-DOC01..14, RF-PEND-01, RF-PEND-04, RV-CT e homologações
seguem abertos. Rollback AGI-11-RB01 desabilitado.
Portal/CRM/Agents/Performance main não alteradas por essa migração.

Próximo: F1-04 protocolo Portal↔Sites e mocks; teste real somente após gates.
