# STATUS-ATUAL — Imagine Sites Engine

**Data:** 2026-11-08. **F1-07** branch filha do F1-06, sem deploy ou merge.

F1-01 PR #1: manifesto/aceite lógico, janela T0+432h, idempotência fictícia (CI PASS).
F1-02 PR #2: gateway default DENY e testes adversariais (CI PASS).
F1-03 PR #3: Laravel 13 próprio, rotas operacionais 404, banco placeholder (CI PASS).
F1-04 PR #4: CreateProject v1 e mock de autorização Portal; contraparte no Portal PR #101 (CI PASS).
F1-05 PR #5: PostgreSQL 18/RLS em CI descartável com revogação e unicidade (CI PASS).
F1-06 PR #6: Onepage/Landing determinísticas em HTML sem JS, sem formulários, SHA-256 de release (CI PHP 8.3/8.4 PASS).
F1-07: Artisan `sites:preview-fixture` somente APP_ENV=testing + flag explícita, saída privada descartável. CI Laravel/testes a verificar.

**Pendentes:** geração IA real, versões persistidas, cliente/contrato/autorização real, serviços HTTP, Portal-Sites operacional, CRM/captação, Cloudflare/DNS, deploy, backups, segurança homologada e dados reais. Gates P0/RF-PEND/RV-CT seguem abertos, AGI-11-RB01 desabilitado.
