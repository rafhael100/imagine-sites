# F1-93 — Primeira migração Laravel com PostgreSQL 18 e RLS por empresa

## Objetivo

Até esta etapa o repositório continha **laboratórios SQL**, mas ainda não uma migração Laravel real de projetos e rascunhos técnicos. A F1-93 acrescenta `apps/sites/database/migrations/2026_11_10_000001_create_sites_core.php`, executada com `php artisan migrate` sobre um PostgreSQL 18 descartável, separado de todos os bancos do Portal/CRM e sem dados pessoais.

## Modelo inicial

- `sites_projects`: chave composta (`tenant_ref`, `site_ref`), tipo `onepage` ou `landing_page`, ciclo de vida restrito e data de criação.
- `sites_drafts`: identificadores de empresa/site, revisão, fluxo, release e dois hashes SHA-256 (HTML e dossiê). Chaves compostas, unicidade da revisão e release por projeto e FK explícita ao projeto da mesma empresa.
- `sites_drafts.review_state` aceita somente `pending_internal` ou `rework_requested`; não existe `customer_approved` ou `publishable` na tabela. A decisão oficial pertence ao Portal e terá persistência/autorização própria.
- Ambas as tabelas têm `ENABLE ROW LEVEL SECURITY` e `FORCE ROW LEVEL SECURITY`. Políticas usam `NULLIF(current_setting('imagine_sites.tenant_ref', true),'')`, que deve ser definido pelo **serviço autenticado** via `SET LOCAL` somente dentro da transação. Sem contexto, o banco nega acesso aos registros.
- Um trigger de PostgreSQL recusa `UPDATE` e `DELETE` em `sites_drafts`, inclusive para o operador administrador; correções precisam de nova revisão e eventos auditáveis.

## Segurança e limitação importante

O contexto `imagine_sites.tenant_ref` **não autentica empresa**: o parâmetro PostgreSQL personalizado pode ser alterado por um usuário SQL que tenha acesso direto ao banco. Portanto, **esse RLS só é uma camada adicional** quando o acesso SQL é totalmente controlado pelo serviço com identidade e autorização reais do Portal, sem SQL arbitrário de clientes ou agentes, credencial não privilegiada, transação isolada e pool sem vazamentos de sessão. A F1-93 não fecha o gate de identidade/revogação.

A migração só aceita `APP_ENV=testing`, `SITES_F93_SCHEMA_LAB=1` e conexão PostgreSQL. Em produção ela falha até existir uma promoção explícita aprovada. `down()` recusa apagar tabelas, evitando rollback destrutivo sem procedimentos de recuperação validados.

## CI e evidências esperadas

O workflow `sites-f1-93-pg-schema.yml` executa PHP 8.3 e 8.4 com imagem PostgreSQL 18 descartável. Primeiro demonstra que `artisan migrate` falha com o opt-in desligado, depois aplica a **migração Laravel real** e cria um usuário de teste `sites_f93_app` sem superuser, bypass RLS, DDL, CREATEDB ou CREATEROLE e com somente `SELECT`/`INSERT` nas duas tabelas. O teste independente via PDO verifica:

- versão 18, existência e força das políticas RLS;
- leitura sem tenant = nenhum registro, e isolamento entre contextos de empresas A e B;
- recusa de escrita cross-tenant e digest inválido, duplicação de revisão;
- trigger append-only recusando UPDATE/DELETE;
- isolamento do contexto SET LOCAL após COMMIT/ROLLBACK;
- `migrate:rollback` não apaga dados.

## O que permanece

Ainda são necessários: vínculo oficial e revogação do Portal, aprovação humana auditável, outbox e eventos, retenção LGPD, storage segregado de artifacts, bancos operacionais com backup/restore real, controle de consultas, segurança independente, VPS e domínio de homologação. Nenhuma publicação, migração em ambiente do cliente, alteração de main/dev, dado pessoal, credencial operacional ou gasto é autorizado. Os dez gates permanecem BLOCKED. Este é o **primeiro alicerce real de migration** com teste PG18, ainda restrito a laboratório.
