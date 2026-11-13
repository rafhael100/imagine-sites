# F1-85 — Autoload de Application e Persistence integrado ao Laravel

O projeto já possui classes em `src/Application` e `src/Persistence`, incluindo o ciclo de revisão e comprovação PostgreSQL das etapas F1-79..82, mas o `apps/sites/composer.json` não tinha as duas raízes registradas no autoload PSR-4. Elas eram acessíveis aos testes PHP avulsos por um autoloader manual, porém não estavam resolvidas pelo aplicativo Laravel sem cargas específicas.

A F1-85 adiciona ao Composer **somente** os namespaces `Imagine\\Sites\\Application\\` e `Imagine\\Sites\\Persistence\\`, sem novas dependências, migrations, rotas ou mudanças de credencial. O teste Feature do Laravel confere que as classes são carregadas pelo Composer a partir dos caminhos esperados, que os provedores de autoridade Portal e CRM continuam no padrão `DENY`, que `/up` funciona e que rotas operacionais HTTP permanecem inexistentes.

É uma correção estrutural de integração no aplicativo, não a liberação de nenhum serviço. O bloqueio operacional, o controle de aprovação do cliente, as credenciais externas, o banco de produção, a VPS e os dez gates `BLOCKED` continuam sem alteração. O autoload não autoriza instanciar serviços de laboratório em ambiente operacional.
