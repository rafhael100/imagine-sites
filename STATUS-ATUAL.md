# STATUS-ATUAL — Imagine Sites Engine

**Data de referência:** 2026-11-08. **Recorte atual:** F1-06 renderizador estático de laboratório, branch filha do F1-05, sem deploy.

F1-01 PR #1: regras de manifesto, publicação/entrega consultiva, T0+432h, dedup sintética (CI PASS).
F1-02 PR #2: gateway Portal com default DENY e testes mockados (CI PASS).
F1-03 PR #3: Laravel 13 próprio, PostgreSQL placeholder, /up e rotas operacionais fechadas (CI PASS).
F1-04 PR #4: contrato Portal->Sites CreateProject v1, schema JSON, fonte DENY, testes fictícios (CI PASS); contraparte Portal PR #101 (CI PASS).
F1-05 PR #5: PostgreSQL 18 descartável, RLS, revogação, unicidade e isolamento com dados fictícios (CI PASS). Sem migrations operacionais.
F1-06: `src/Production` renderiza HTML estático seguro e determinístico de Onepage/Landing a partir de fixture falsa, mantém SHA-256 e comparação de manifesto. CI F1-06 a conferir após push.

**Nenhuma** API real, integração Portal-Sites operacional, AI Agents, CRM, captação, Cloudflare, DNS, domínio, backup, agente com acesso externo ou deploy. Sem merge automático, custos ou dados reais. Gates P0-DOC01–14, RF-PEND-01/RF-PEND-04 e RV-CT continuam abertos. AGI-11-RB01 desabilitado.
