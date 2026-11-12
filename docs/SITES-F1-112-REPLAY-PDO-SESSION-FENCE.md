# F1-112 — Validar novamente o contexto da conexão de nonce a cada consumo

## Problema comprovado

O adaptador F1-14 `LabPostgresReplayStore` validava, ao ser instanciado, a base PostgreSQL e a role restrita `sites_auth_runtime`. Entretanto, uma instância já criada poderia continuar sendo reutilizada após `RESET ROLE` (passando à role proprietária), após desligar a flag de laboratório, ou dentro de uma transação aberta pelo chamador. Nesse último caso, um `ROLLBACK` posterior removeria o registro do nonce que já havia sido considerado utilizado, comprometendo a prova de proteção contra replay entre processos.

## Correção

A cada chamada `rememberOnce()`, antes de qualquer `INSERT`, o adaptador agora:

1. Exige execução CLI, `APP_ENV=testing` e `SITES_F1_14_PG_LAB=1` tanto ao criar o adaptador quanto no momento de cada consumo.
2. Rejeita conexões já dentro de transações administradas pelo chamador; o nonce precisa ser persistido em sua própria operação autocommit na base descartável.
3. Consulta novamente `current_database()`, `current_user` e as propriedades de `pg_roles`, exigindo base `sites_auth_replay_lab`, role `sites_auth_runtime` e ausência de SUPERUSER/BYPASSRLS/CREATEDB/CREATEROLE/LOGIN.
4. Falha fechado, retornando `false`, se a consulta de contexto, a execução SQL ou os requisitos anteriores falharem; segue aplicando o registro INSERT-only, RLS e chave única por emissor/nonce.

## Evidências automatizadas

- `tests/f14-pg.php`: uma instância do adaptador já existente recusa consumo após desativar a flag, dentro de transação e após alterar a role para proprietária. Cada tentativa rejeitada é confirmada por uma conexão independente que consegue registrar aquele nonce ainda disponível.
- `tests/f111-pg-durable-signed-intake.php`: a mesma sequência é executada através do fluxo completo de assinatura HMAC fictícia, nonce e admissão de projeto; nenhuma rejeição cria novas linhas na base de projetos. A segunda conexão consegue concluir apenas um replay idempotente com o mesmo comando e nonce que não foi consumido.
- A matriz `sites-f1-14-php-pdo-replay.yml` e a matriz de admissão `sites-f1-94-project-admission.yml` cobrem PHP 8.3 e 8.4 com bancos PostgreSQL 18 descartáveis.

## Limitações

As verificações são de **laboratório**, não certificação de IAM operacional. Uma role privilegiada que controla o banco pode modificar permissões, esquema ou dados, e a verificação de contexto também não equivale a uma garantia de ausência de mudanças posteriores. Nonces e admissões em duas bases não compõem transação distribuída. O armazenamento não tem HA, backups, retenção operacional, assinatura de produção ou identidade verificada de cliente/Portal.

Não há endpoint público, deploy, merge em dev/main, dados de cliente, alteração da VPS ou novas despesas. Os dez gates operacionais permanecem `BLOCKED`.
