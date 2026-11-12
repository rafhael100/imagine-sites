# F1-232 — bloqueio do alvo das migrations PostgreSQL de laboratório

**Motivação:** as migrations de laboratório F1-93 e F1-94 restringiam `APP_ENV=testing`, flags e driver PostgreSQL. A F1-93 não exigia nome específico de banco; a F1-94 exigia `sites_f94_lab`, mas ainda não validava endereço, porta, usuário nem execução no GitHub Actions. Uma flag esquecida em outro ambiente não deveria permitir DDL acidental.

## Implementação

`LabMigrationTargetFenceV1::assertAllowed()` é uma política pura (sem SQL/conexão) chamada **antes de qualquer DDL** pelas duas migrations. Ela exige simultaneamente:

- processo `PHP_SAPI=cli`, Laravel em `testing`, opt-in explícito da migration e `GITHUB_ACTIONS=true`;
- driver efetivo `pgsql`, endereço efetivo `127.0.0.1`, porta `5432` e login de administração `postgres` (apenas credenciais fictícias do CI);
- alvo exato: **F1-93** admite somente `sites_f93_lab` ou `sites_f94_lab` (porque F1-94 instala primeiro as tabelas da F1-93); **F1-94** admite exclusivamente `sites_f94_lab`.

Os dados usados vêm da **conexão Laravel efetiva** (`DB::connection()->getConfig()` e `DB::getDatabaseName()`), e não de um parâmetro informado pelo cliente. A exceção é genérica, sem imprimir valores de DSN, host, usuário ou senha.

## Evidências automatizadas

- `php tests/f232-pg-migration-target-fence.php` cobre alvos válidos e rejeita banco CRM, Portal, `postgres`, nomes de desenvolvimento, flags ausentes, execução fora do Actions, host externo, outras portas, outros usuários e driver inadequado.
- `synthetic-php.yml` executa o teste puro em PHP 8.3 e 8.4.
- `sites-f1-93-pg-schema.yml` e `sites-f1-94-project-admission.yml` tentam executar a migration real com `GITHUB_ACTIONS=false`, **mesmo mantendo flags sintéticas ativas**, e verificam que as tabelas esperadas continuam inexistentes.
- As próprias pipelines continuam aplicando a migration com os parâmetros exatos do banco descartável e testando RLS/idempotência, para evitar quebrar os laboratórios legítimos.

**Limitação explícita:** qualquer variável de ambiente de um processo pode ser falsificada por quem já administra a máquina. Esse gate **não comprova** que um host/banco seja descartável e não substitui permissões PostgreSQL sem DDL, rede isolada, CI protegido, revisão, controle de acesso ou autorização de migração. `main`, `dev`, produção, VPS e Cloudflare permanecem inalteradas; nenhum dado real é utilizado.
