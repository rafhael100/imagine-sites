# F1-142 — Tenancy técnica derivada do login PostgreSQL (apenas laboratório)

## Problema resolvido no laboratório

A F1-140 permitia que a role CI `sites_f140_app` invocasse `try_claim` para UUID de qualquer organização. A F1-137 já mostrou que `app.tenant_id` é manipulável por quem executa SQL.

Este experimento F1-142 remove o EXECUTE da função sem escopo de ambas as roles de aplicação e expõe somente `try_login_scoped_claim`, que consulta uma tabela fechada de vínculo entre o login PostgreSQL autenticado (`session_user`) e a organização sintética. A role não pode ler ou alterar o mapeamento, tampouco selecionar a role da segunda organização. `SECURITY DEFINER` usa `search_path` fixo; a função original continua utilizável pelo dono da fixture no CI.

## Provas

O teste cria duas roles de login com senhas fictícias, duas empresas e uma submissão UUID igual para ambas. Cada login consegue registrar **apenas** o seu próprio claim sintético; tentativa de registrar pela outra organização é `DB_LOGIN_SCOPE_DENIED`, sem novo registro. A função original sem escopo e tabelas internas não são acessíveis. A matriz CI é PG18 e PHP 8.3/8.4.

## Limitações obrigatórias

Isso **não substitui IAM real** do Portal, identidade de cliente ou autenticação de serviço. Um administrador de banco pode modificar o mapeamento; a ligação de login de DB a empresa não resolve SaaS multiempresa com pool de conexões e credenciais compartilhadas. Não há consentimento, dados pessoais, recepção CRM, confirmação, auditoria operacional ou autorização de usuários. Não publicar o wrapper como API. Apenas fixtures descartáveis, sem deploy, custos, VPS ou merge `dev/main`.

OP04 (#36) e 10/10 gates de produção continuam bloqueados, pendente validação independente SEG/DBA/AppSec.
