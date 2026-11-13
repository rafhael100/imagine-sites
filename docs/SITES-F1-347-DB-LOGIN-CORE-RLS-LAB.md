# F1-347 — isolamento por login autenticado do PostgreSQL no core Sites (experimento CI)

**Estado:** experimento em PostgreSQL 18 descartável. Não é implementação produtiva de SaaS multiempresa, não substitui o IAM Portal e não autoriza migrações/contas reais.

## Motivação

A F1-346 provou que as políticas atuais de `sites_projects` e `sites_drafts` baseadas em `current_setting('imagine_sites.tenant_ref')` podem ser burladas por um agente que tenha a mesma role SQL de aplicação e permissão para executar `SET LOCAL`. Uma variável de sessão indicada pelo cliente é contexto, não identidade autenticada. O objetivo deste experimento é testar uma alternativa com vínculo **do próprio login autenticado** no PostgreSQL.

## O que o teste realmente faz

No banco disposable `sites_f93_lab` já criado pela migração Laravel F1-93, o script `tests/f347-pg-login-scoped-core-rls.php` prepara duas roles SQL fictícias independentes (`sites_f347_a` e `sites_f347_b`) sem `BYPASSRLS` e sem permissões de DDL. Uma tabela privada `sites_f347_login_map` associa `session_user` à respectiva empresa fictícia.

Uma função SQL `SECURITY DEFINER` com `search_path=pg_catalog` fixo consulta essa associação privada, e duas políticas RLS temporárias de laboratório usam a função em `USING` e `WITH CHECK` em lugar da GUC.

O teste confirma que:

- Cada login vê somente os projetos e rascunhos de sua empresa, sem acesso de leitura/alteração à tabela que define a associação.
- Uma conexão da empresa A que tente definir a GUC como empresa B **não passa a ver nem inserir dados de B**. O inverso vale para a empresa B.
- Tentativas `SET ROLE` para outra empresa são negadas pelo PostgreSQL.
- Quando o administrador desativa a associação da empresa A, **a conexão já existente** deixa de ver registros, inclusive se tentar definir a GUC antiga. A conexão de B continua isolada.
- Todos os INSERTs usados para testar limites são revertidos; o banco fictício mantém somente as linhas criadas pela F1-93.

O workflow `sites-f1-93-pg-schema.yml` executa esse experimento **depois** da prova negativa F1-346, nas versões PHP 8.3/8.4 contra PostgreSQL 18 real, com usuário e senha de teste públicos e descartáveis.

## Limitações e decisão arquitetural pendente

A proposta usa **uma role de login por empresa**, o que não é automaticamente adequado para pool compartilhado de conexões nem para um SaaS com milhares de clientes. A tabela de associação é controlada por um administrador de banco de dados no teste: não há prova de identidade do usuário do Portal, assinatura S2S, delegação, consentimento, autorização por serviço, gestão real de chaves, recuperação de credenciais nem auditoria de produção. Se o aplicativo puder usar a role de outra empresa ou a role dona do esquema, a fronteira deixa de proteger.

**Não aplicar essas políticas diretamente em produção** sem desenho e revisão independente de DBA, AppSec e arquitetura Portal↔Sites. Ainda é necessário projetar autenticação do Portal, revogação sob transações concorrentes, pool de conexões, transações de publicação e retenção/backup. A issue de banco #34 e de IAM #33 continuam P0, com `config/operational-readiness.json` bloqueado e dez gates falsos. Nenhum deploy, VPS, Cloudflare, cliente, dado pessoal ou custo foi realizado.
