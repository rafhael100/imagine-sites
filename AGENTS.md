# AGENTS.md — Imagine Sites Engine

Escopo: motor independente para Onepages e Landing Pages, produzidos e gerenciados pela Imagine. Cliente continua no Portal, sem editor self-service.

## Gates obrigatórios
1. Trabalhar em branch isolada com PR draft. Sem merges/deploys automáticos, gastos, VPS, Cloudflare/DNS, APIs reais, segredos ou dados de clientes sem gate específico.
2. Preservar legado Node/SQLite do Portal; extrair regras/casos de teste sem copiar SQL ou acoplar runtime legado.
3. Cada produto mantém banco próprio. APIs versionadas e autenticadas com autorização por recurso; tenant informado por usuário/IA nunca constitui autorização.
4. Portal possui contrato, aceite expresso do cliente e evento oficial ENTREGUE; Sites possui release e publicação técnica; CRM possui confirmação de lead.
5. Os booleanos do domínio sintético não verificam assinaturas, identidade, RLS ou revogação: não podem ser usados como autorização real.
6. AI Agents não autoaprovam publicação. AGI-11-RB01 rollback automático permanece DESABILITADO. Ações críticas seguem alçadas específicas.
7. Captação requer vínculo validado nas duas APIs, storage independente por rota, T0 durável, prazo inicial 72h e total 432h sem reset; não reenviar após vencimento.
8. Nunca enviar PII, briefing privado, tokens, segredos e artefatos confidenciais ao GitHub público ou a logs.
9. Testes negativos: cross-tenant, revogação, release diferente da aprovada, replay, provider 202 sem release observada, restore com dados expirados.
10. P0-DOC01..14, RF-PEND-01, RF-PEND-04 permanecem abertos até evidência própria.

**Estado F1-94:** o motor PHP 8.3+ e o Laravel já existem, com migrações de projetos, revisões e admissão idempotente em PostgreSQL 18. Tudo funciona **somente em testes descartáveis**, com `APP_ENV=testing`, `SITES_F93_SCHEMA_LAB=1` e `SITES_F94_PROJECT_ADMISSION_LAB=1` nos ambientes correspondentes. O serviço de admissão exige um gateway Portal e nega por padrão; os testes usam gateway fictício. Nunca rodar migração operacional ou habilitar IAM/webhooks sem autorização específica. `SET LOCAL imagine_sites.tenant_ref` não autentica empresa: exige identidade real do Portal e ausência de SQL arbitrário. Usar `php tests/run.php`, os testes Laravel e os workflows de PostgreSQL 18. Registrar SHAs/evidência e pendências em STATUS-ATUAL.md.
