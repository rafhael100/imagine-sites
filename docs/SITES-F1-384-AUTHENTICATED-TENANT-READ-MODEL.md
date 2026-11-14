# F1-384 — Leitura segura de projetos por identidade autenticada no PostgreSQL

**Objetivo:** entregar uma camada *operacional-shaped*, versionada e reutilizável para o futuro painel do cliente, sem abrir um endpoint público nem aceitar um identificador de empresa fornecido pelo navegador.

## Arquivos implementados

A migration `apps/sites/database/migrations/2026_11_14_000002_add_tenant_bound_read_api.php` estende o esquema `sites_core` da F1-383 com `db_login_bindings` (login PostgreSQL → UUID da empresa, com `active` e `can_read`, ambos falsos por padrão) e função `sites_core.read_my_site_projects()`. A função retorna até 100 projetos da empresa autenticada com estado, produto e versão editorial corrente, sem referências de contrato, chaves, pagamento, dados pessoais ou credenciais.

O controle de empresa vem de **`session_user` retornado pelo servidor PostgreSQL**, sem aceitar `tenantRef`, ID de cliente, GUC ou header. A função recusa superusers, roles com `BYPASSRLS`, contas capazes de criar DB/roles e logins inexistentes ou revogados. Não existe fallback para leitura global.

A função usa `SECURITY DEFINER`, porém pertence a uma role dedicada **`sites_core_read_definer` sem LOGIN, sem privilégios administrativos, sem BYPASSRLS e com SELECT restrito a três tabelas**. As três políticas de RLS habilitadas são `FOR SELECT TO sites_core_read_definer`; todas as demais tabelas continuam `FORCE RLS` sem políticas. Nenhum `GRANT EXECUTE` é concedido a PUBLIC ou a um papel de aplicativo conhecido pela migration. A ativação de um papel real exigirá uma decisão DBA explícita, criá-lo de modo restrito, vincular a organização previamente verificada e conceder `USAGE` do schema mais `EXECUTE` da função apenas a ele.

`apps/sites/app/ReadModels/TenantSiteStatusReader.php` encapsula a leitura numa classe Laravel. Não recebe identificadores de empresa de entrada; converte o resultado SQL numa estrutura mínima, valida os estados e mantém `publishable=false`. Erros SQL são convertidos em mensagem sanitizada, sem expor consulta, host, login ou identidade de outra empresa.

## Validação (PostgreSQL18 efêmero, PHP 8.3/8.4)

O workflow de migração e rollback F1-383 inclui a F1-384 e executa:
- `artisan migrate` das duas migrations em um banco descartável;
- inspeção de `FORCE RLS`, policies restritas e absence de grants de aplicação;
- cliente SQL não autenticado/bind inexistente rejeitado mesmo com EXECUTE concedido;
- duas contas reais do PostgreSQL de **teste** mapeadas a empresas distintas que recebem exclusivamente seus projetos;
- tentativas de SELECT/UPDATE direto e `SET ROLE` negadas;
- `SET app.tenant_id` tentando se passar por outra empresa sem efeito;
- revogação do binding SQL e do privilégio `can_read` interrompendo as leituras;
- superusuário impedido de utilizar a função como se fosse cliente;
- PHPUnit Laravel lendo via `TenantSiteStatusReader` com as duas contas;
- `artisan migrate:rollback --batch=1` em banco inteiramente descartável, verificando a remoção do schema.

## Limites para conclusão comercial

Essa arquitetura pode servir para o status do projeto no painel do cliente, **mas não identifica o usuário da web**. Não se deve criar um role SQL por cliente ou habilitar HTTP antes de definir e revisar IAM Portal → identidade SQL, conexões/segredos e estratégia de pool multiempresa; o modelo de role por empresa é apenas uma referência técnica testada, não a decisão definitiva de escala. A migration não cria identidades, passwords, contratos, pagamentos, aceite, trabalho em produção, publicação ou backups.

É necessária revisão de DBA/AppSec sobre o uso de `SECURITY DEFINER`, a administração das bindings e a forma de propagar a identidade real do Portal. A migration será **DRAFT/NO-GO até essa revisão**; não aplicar no banco Leaseweb, nem copiar logins ou passwords dos testes. Não houve merge, deploy, domínio ou custo operacional.
