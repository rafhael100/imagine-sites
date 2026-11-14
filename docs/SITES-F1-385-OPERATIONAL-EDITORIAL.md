# F1-385 — Transições editoriais versionadas na base operacional do Imagine Sites

**Entrega:** migration Laravel 13 PostgreSQL18 e serviço Laravel para registrar rascunhos, enviar para revisão, solicitar retrabalho e aprovar internamente, **sem autorização de cliente e sem publicação**.

## Como funciona

`apps/sites/database/migrations/2026_11_14_000003_add_editorial_revision_api.php` utiliza a tabela versionada `sites_core.editorial_revisions` da F1-383 e cria a tabela `sites_core.editorial_operator_bindings`. O DBA deve associar uma conta PostgreSQL autenticada e restringida a um único `tenant_id` e um dos deveres `editor` ou `reviewer`. O campo `active` começa **false**. A migration **não cria contas com senha nem concede EXECUTE a PUBLIC**.

A função SQL `sites_core.advance_editorial_revision(site_uuid, expected_revision, action, sha256)` só responde a uma conta SQL elegível e ativa. Ela valida o login autenticado `session_user`, rejeita superuser e `BYPASSRLS`, confere o vínculo empresa e a existência de projeto da empresa no banco. A implementação `SECURITY DEFINER` é propriedade de uma role dedicada `sites_core_edit_definer`, sem login, administração ou BYPASSRLS. Permissões das tabelas são exclusivamente `SELECT` do read model e `INSERT` em revisões, com políticas RLS restritas à role NOLOGIN; **nenhum operador recebe SELECT/UPDATE/DELETE direto na tabela**.

As alterações de estado registram **uma nova linha por revisão**, sem atualizar ou destruir revisões antigas. A chave única `(tenant_id,site_id,revision)` impede que duas transições concorrentes publiquem a mesma revisão, e o parâmetro `expected_revision` rejeita versões antigas.

| Ação | Quem pode executar | Pré-condições | Próximo estado |
|---|---|---|---|
| `register` | Editor | Projeto da empresa já admitido; versão inicial 0 | `draft` |
| `revise` | Editor | Draft com digest alterado e versão corrente | `draft` |
| `submit` | Editor | Draft com digest **igual ao conteúdo revisado** | `internal_review` |
| `rework` | Revisor | Revisão interna, digest exato e versão corrente | `draft` |
| `approve_internal` | Revisor | Revisão interna, digest exato e versão corrente | `awaiting_portal_acceptance` |

A classe `apps/sites/app/Editorial/EditorialRevisionWriter.php` fornece o ponto de integração do Laravel com essa função, sem receber `tenantRef`, papel do operador ou identidade HTTP. Respostas são limitadas a versão, estado, hash e `clientAccepted=false`/`publishable=false`.

## Cenários adversariais exercitados no CI

A matriz PHP 8.3/8.4 + PostgreSQL18 descartável instala as três migrations e usa contas de editor e revisor realmente autenticadas no banco **apenas para ficção**. Os testes cobrem Onepage aprovada internamente (revisões 1,2,3), Landing devolvida para retrabalho (1..6), alteração de digest, versões antigas, impedimento de autoprovação, acesso a outra empresa, revogação de função, recusa de login de DBA, `SET ROLE` proibido, ausência de privilégio direto e manutenção do histórico imutável (9 revisões).

Também valida que nem o SQL privilegiado consegue marcar `client_accepted=true` por alteração direta, porque a constraint da F1-383 bloqueia; publicação e worker continuam desabilitados. A classe Laravel recebe teste integrado no mesmo PostgreSQL18 usando logins separados para editor/revisor. Todas as migrations são revertidas por `artisan migrate:rollback --batch=1` no banco descartável.

## Acesso operacional ainda bloqueado

O vínculo `session_user` → empresa e dever editorial é um desenho **para revisão DBA/AppSec**, não decisão definitiva para escala SaaS. Não ativar em ambiente público sem ligação ao IAM/MFA real do Portal, definição de gestão de credenciais e pooling SQL, armazenamento versionado dos bytes HTML, aprovação expressa do cliente e do Portal, identidade S2S, backup/restauração, observabilidade, LGPD e pipeline de publicação com rollback. Um digest aprovado internamente **não comprova consentimento do cliente**.

**DRAFT/NO-GO. Sem merge, deploy, VPS, alteração DNS, credenciais reais, transação financeira ou gasto novo.**
