# F1-342 — variáveis PostgreSQL e destino de banco do laboratório sob allowlist estrita

**Implementação de defesa em profundidade exclusivamente no Compose descartável CI, NÃO homologação de produção.**

Além de negar namespaces, processos personalizados, montagem extra e permissões elevadas (F1-341), a verificação F1-74 passa a exigir **conjunto exato de variáveis de ambiente** para os contêineres PostgreSQL e testes Laravel.

O contêiner PostgreSQL pode receber somente `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` e `SITES_LAB_DB_PASSWORD` para o banco sintético. Em particular, adicionar `POSTGRES_HOST_AUTH_METHOD=trust` ou `POSTGRES_INITDB_ARGS=--auth-host=trust` falha antes de criar qualquer contêiner; essas flags poderiam contornar o requisito de senha no laboratório.

O contêiner Laravel aceita somente `APP_ENV=testing`, `APP_DEBUG=false`, `GITHUB_ACTIONS` com `true/false`, `DB_CONNECTION=pgsql`, `DB_HOST=sites-lab-db`, `DB_PORT=5432`, `DB_DATABASE=sites_lab`, `DB_USERNAME=siteslab` e senha efêmera distinta da senha superuser `postgres`. Variáveis extras, DB externo, conexão `sqlite`, segredo não previsto ou app em `production` são recusados pelo guard.

O workflow F1-83 continua a interpretar o **Compose renderizado** (`docker compose config --format json`) e roda o guard antes do build; há testes adversariais de onze mudanças indevidas, somados aos negativos F1-74/341. Nenhuma senha real é armazenada no repositório; as credenciais do workflow são placeholders públicos descartáveis.

Não declarar o PostgreSQL do cliente, a VPS contratada, gestão de segredos, IAM, RLS, backup de dados reais ou serviços externos como homologados. O escopo de `SITES` continua laboratório; o projeto mantém branch protections #281, OP01–OP05 #33–#38 e dez gates operacionais `BLOCKED` pendentes.
