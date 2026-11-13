# F1-343 — Healthcheck e dependência do PostgreSQL sob contrato fechado

**Escopo:** somente laboratório descartável Docker/PostgreSQL 18 de CI, sem VPS Leaseweb ou dados de clientes.

## Por que acrescentar a verificação

O Compose pode executar código arbitrário durante um *healthcheck*, independentemente do comando principal do contêiner. As verificações F1-341/342 já impediam `entrypoint`, `command`, `env_file`, namespace de host e autenticação `trust`, mas não conferiam o script do healthcheck nem a condição de subida do serviço de testes. Um healthcheck alterado poderia executar um comando inesperado ou declarar a base pronta antes da configuração de acesso restrito.

## Política F1-343

A verificação `ci/f74_docker_lab_boundary.py` exige que:

- O PostgreSQL use exclusivamente `["CMD-SHELL","pg_isready -U postgres -d sites_lab"]`, intervalo 5s, timeout 3s e doze tentativas; sem `disable` ou campos imprevistos.
- O contêiner de testes não tenha healthcheck executável.
- O contêiner PostgreSQL não dependa de outros serviços.
- A aplicação dependa **somente** de `sites-lab-db` na condição `service_healthy`, sem dependência opcional, reinício automático ou serviço externo.

O fixture `self_test` foi atualizado e inclui mutações negativas para comando arbitrário, healthcheck desabilitado, intervalo anormal, healthcheck no contêiner de teste e dependência falsa ou opcional. O workflow F1-83 valida a configuração **renderizada** pelo Docker Compose **antes** de construir ou iniciar os contêineres.

## Limites explícitos

O healthcheck `pg_isready` verifica disponibilidade do PostgreSQL, não integridade, qualidade dos dados, privilégio do usuário de aplicação ou autenticação IAM do Portal. O workflow F1-84 continua exigindo teste real da role `siteslab` restrita no banco temporário, sem gravar registros permanentes. A política não modifica a VPS, rede, domínio, banco de produção ou qualquer serviço externo. A issue #281 e OP01–OP05 (#33–#38), com dez gates bloqueados, continuam pendentes.
