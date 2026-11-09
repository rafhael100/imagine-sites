# SITES-F1-19 — Laravel/DB: comando e outbox em transação PostgreSQL descartável

**Escopo:** código Laravel de laboratório, acesso somente PHP CLI + `APP_ENV=testing` + `SITES_F1_19_PG_LAB=1`, conexão ao banco **efêmero** `sites_lab` e role restrita `sites_lab_tenant_a`.

- `ProjectOutboxLabService`: grava um comando fictício e seu evento correspondente na **mesma transação** usando a conexão PostgreSQL da aplicação Laravel.
- `LabProjectOutboxPgTest`: testes com empresas imaginárias de commit, rollback ao evento inválido, isolamento cross-tenant, unicidade do idempotency key, rejeição de owner e efeito de revogação. A role é simulada com `SET ROLE` partindo do owner **apenas** no CI, não uma solução de autenticação de produção.
- CI executa PHP 8.3/8.4, Laravel 13, PostgreSQL 18 com schema `f1-05` e `f1-15` **fora das migrations reais**. Nenhuma rota HTTP é adicionada.

Esse laboratório comprova que uma camada de aplicação Laravel consegue se comunicar com um banco separado e respeitar as políticas de RLS/atomicidade. Não comprova identidades reais Portal↔Sites, outbox dispatcher, auditoria, políticas LGPD, custo, concorrência global, backup/restauração, publicação, aprovação ou entrega oficial.

**Riscos abertos:** conexão de produção nunca pode usar superuser/role owner ou mapeamento de tenant controlado pelo cliente. É necessária autenticação serviço-serviço independente, credenciais/vault, RLS e grants homologados e transação outbox/idempotência com mecanismo de retorno/recuperação. Teste de revogação não substitui prova de restore anti-ressurreição. Nenhum dado real poderá entrar nessas tabelas sem migrações versionadas e aprovação.

P0/RF-PEND/RV-CT/AppSec/LGPD continuam abertos. Sem merges/deploys, Cloudflare, VPS, CRM, Agents ou compra. AGI-11-RB01 desabilitado.
