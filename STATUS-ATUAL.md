# STATUS-ATUAL — Imagine Sites Engine

**Referência:** 2026-11-08. Branch F1-04 filha de F1-03, sem deploy.

F1-01: PHP puro com regras sintéticas de manifesto/publicação, entrega técnica consultiva, janela T0+432h e idempotência em memória. PR #1 draft, CI PASS.
F1-02: gateway de autoridade Portal negando por padrão e mock positivo estritamente de teste. PR #2 draft, CI PASS.
F1-03: app Laravel 13 independente em `apps/sites`, /up bootstrap, 404 para rotas comerciais, PostgreSQL placeholder. PR #3 draft, CI PHP 8.3/8.4 PASS no SHA a3565976b396ea24c98d00e302d77513f8dc94a1.
F1-04: contrato candidato Portal->Sites CreateProject v1, schema JSON, DTO de campos estritos, gateway DENY padrão, registry de idempotência somente sintético, testes adversariais. CI a conferir.

**Faltam:** autenticação real, Laravel conectado ao domínio, banco/migrations, projetos, publicações, Cloudflare, DNS, CRM, APIs de captação redundantes, dados de cliente e homologações.

**Gates:** P0-DOC01–14, RF-PEND-01/RF-PEND-04, RV-CT e segurança/privacidade em aberto. AGI-11-RB01 desabilitado. Sem merges/deploy/custos.
