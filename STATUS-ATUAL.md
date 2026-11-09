# STATUS-ATUAL — Imagine Sites Engine

**Referência:** 2026-11-09 — F1-14 em branch filha de F1-13, sem merge/deploy.

**Base verificada:** F1-01..F1-13 em PRs encadeados e draft, com CI registrado PASS até #13 (SHA f887113069204591a361b1cd8773465da6493ed9). Portal PR #105 testou paridade da assinatura do CreateProject v1.

**F1-14:** adaptador `LabPostgresReplayStore` usa PDO em PostgreSQL 18 descartável com ROLE limitada, controle do nonce, TTL, assinaturas e testes de replay em conexões diferentes. CI deste recorte a verificar após push.

**Continua NÃO operacional:** sem autenticação S2S real, integração comercial/CRM, banco Sites permanente, aprovação de cliente, publicação Cloudflare, DNS, AI Agents, n8n ou VPS. Endpoint HTTP Laravel continua apenas em CLI testing com default DENY.

Gates P0-DOC01..14, RF-PEND-01/RF-PEND-04, contratos RV-CT, LGPD/AppSec abertos; AGI-11-RB01 desabilitado. Nenhum custo, merge ou deploy automático.
