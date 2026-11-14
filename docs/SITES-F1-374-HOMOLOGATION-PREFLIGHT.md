# F1-374 — Diagnóstico de homologação do Imagine Sites, exclusivamente leitura

O comando `php artisan sites:homologation-preflight` gera um relatório JSON com resultados `verified` ou `blocked` e uma lista de bloqueios; **retorna código de saída 1 intencionalmente**, porque os gates comerciais, de segurança e infraestrutura ainda não foram satisfeitos.

Este comando **não cria tabelas, não altera contratos, não gera chaves, não libera rotas, não publica sites e não faz deploy**. Executa somente verificações configuracionais e, em ambiente `staging` com banco realmente configurado, consultas `SELECT` para verificar versão do PostgreSQL, identidade do banco, role SQL sem privilégios administrativos e `pg_stat_ssl` da própria conexão. Não imprime hostname, banco, usuário, senha, chaves, DSN, exceções de conexão ou conteúdo de clientes.

## Comportamento seguro

- Só considera `APP_ENV=staging` um ambiente de ensaio; outras configurações ficam `blocked`
- Exige `APP_DEBUG=false`, flags sintéticas desligadas, APP_KEY com formato e diversidade de bytes adequados; não confunde o formato com a procedência da chave
- Verifica a forma de uma URL HTTPS não local, **sem afirmar** que um certificado ou DNS foi validado
- Para banco com hostname de placeholder `sites-database-not-provisioned`, recusa até a tentativa de conexão; nenhuma credencial ou query de banco é disparada
- Em banco real previamente provisionado, observa apenas resultados de SELECT: PostgreSQL 18, banco esperado, role sem `rolsuper`, `rolcreatedb` ou `rolcreaterole`, e transporte criptografado da sessão
- Exceções de conexão são capturadas sem seu texto, impedindo a divulgação de detalhes de infraestrutura em logs
- Os gates `portal_service_identity`, `commercial_contract`, `independent_revocation`, `backup_restore`, `customer_approval`, `release_publisher` e `appsec_lgpd` permanecem **obrigatoriamente bloqueados**. Nenhuma variável ou resultado `/up` transforma esses critérios em aprovados

## Evidência

A suíte Laravel `HomologationReadinessTest` cobre cenário sem banco, conexão simulada válida sem liberar compra, role superusuária, TLS inexistente, chave fictícia, erro com senha fictícia e retorno sanitizado. O workflow dos contêineres F1-373 também executa o comando no PHP-FPM por `docker compose exec` e exige `status=blocked`, `can_accept_real_orders=false` e `can_publish=false`, sem revelar a chave pública falsa do CI.

## Limites

O relatório não é uma autoridade criptográfica, não implementa autenticação S2S, não valida contratos e pagamento, não verifica o provedor de backups ou o estado real do DNS. Resultados positivos em checagens isoladas não constituem aprovação. Antes de qualquer operação, são necessários revisões DB/AppSec/LGPD, identidades, transações, backups independentes e aceite formal do responsável.

Não executar Docker Compose da F1-373 como site público, e não publicar o output se for enriquecido futuramente com dados de infraestrutura. **PR DRAFT/NO-GO.**
