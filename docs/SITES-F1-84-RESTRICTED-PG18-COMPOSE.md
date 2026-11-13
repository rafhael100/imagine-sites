# F1-84 — PostgreSQL não privilegiado no laboratório Docker

## Falha corrigida

O Compose F1-73/F1-83 usava `POSTGRES_USER=siteslab`, e a imagem oficial do PostgreSQL cria essa conta inicial com privilégio de superusuário. Embora não houvesse exposição de portas e todos os dados fossem fictícios, essa não era uma simulação adequada de **menor privilégio** para a aplicação.

## Alterações em código

- PostgreSQL 18 passa a ser inicializado por `POSTGRES_USER=postgres`, com `SITES_LAB_DB_ADMIN_PASSWORD` separada do segredo fictício de aplicação `SITES_LAB_DB_PASSWORD`. Nenhuma senha operacional é salva no Git.
- O arquivo versionado `ops/staging/postgres-init/01-create-lab-role.sh` é montado **somente leitura** em `docker-entrypoint-initdb.d`. A inicialização cria a role `siteslab` com `NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS`, sem grants persistentes de schema, com apenas CONNECT e TEMPORARY no banco fictício.
- O container de testes Laravel recebe apenas a senha do usuário restrito `siteslab`, nunca a senha do administrador. Os dois serviços permanecem internos, sem publicação de porta.
- `apps/sites/scripts/verify-isolated-db.php` é executado como processo CLI opt-in, com `SITES_F84_LAB=1`, e faz uma conexão PDO real do contêiner PHP ao serviço PostgreSQL. Confere versão 18, banco, role, IP remoto interno, ausência de superuser/createdb/createrole/bypassrls/CREATE no DB e schema público; faz apenas `CREATE TEMP TABLE`, INSERT, SELECT e ROLLBACK, sem escrita persistente.
- O guard `ci/f74_docker_lab_boundary.py` foi expandido para rejeitar role do app como superusuário, credenciais iguais, montagem diferente do init e perda da flag readonly. O workflow F1-83 executa o teste de banco antes do Laravel.

## Escopo e limites

É um laboratório GitHub Actions e futuro exercício em VPS autorizada; **não** é migração de PostgreSQL produtivo, IAM, rede operacional, auditoria independente, gestão real de segredos ou publicação Cloudflare. A senha de CI é um placeholder público, distinto do administrador, e deve ser substituída por credenciais gerenciadas fora do Git na futura infraestrutura. Para host real ainda faltam isolamento de projeto, limites de consumo, image pin por digest, backup/restore, política de rotação e revisão independente.

A garantia de menor privilégio é verificada **dentro do contêiner Laravel real**, não presumida apenas do arquivo Compose. Os dez gates de prontidão permanecem BLOCKED. Não houve deployment, domínio, servidor Leaseweb, dados pessoais ou despesa adicional.
