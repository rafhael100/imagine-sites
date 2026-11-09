# STATUS-ATUAL — Imagine Sites Engine

**Data de referência:** 2026-11-08. **Atual:** F1-05 laboratório SQL de isolamento em branch filha de F1-04, sem deploy.

| Recorte | O que existe | Homologação |
| --- | --- | --- |
| F1-01 PR #1 | PHP sintético: release/manifesto, publicação/entrega consultiva, prazo 432h | CI PASS, nenhuma operação real |
| F1-02 PR #2 | Gateway Portal DENY por padrão + mocks | CI PASS, nenhuma autoridade real |
| F1-03 PR #3 | Aplicativo Laravel 13 isolado em apps/sites; /up e 404 para operações; PostgreSQL só placeholder | CI PHP 8.3/8.4 PASS, sem banco conectado |
| F1-04 PR #4 | Contrato candidato CreateProject v1, schema JSON, idempotência em memória, mocks Portal↔Sites | CI PASS, sem HTTP real |
| F1-05 PR filho | PostgreSQL 18 descartável, RLS, revogação e unicidade com empresas fictícias | CI novo; acompanhar resultados no GitHub, não declarar aprovado antes |

Portal PR #101 constrói o lado sintético do CreateProject v1. Portal PR #94 preserva a arquitetura e gates em aberto.

**Ainda não existe** autenticação serviço-serviço real, conta real de cliente, deploy, banco Sites operacional, publisher, Cloudflare, CRM/Agentes integrados, APIs redundantes de leads nem geração de páginas. Não usar testes do CI como licença para produção.

**Restrições:** P0-DOC01–14, RF-PEND-01/RF-PEND-04, RV-CT e LGPD/segurança seguem abertos; AGI-11-RB01 DESABILITADO; sem merge/deploy/custos.
