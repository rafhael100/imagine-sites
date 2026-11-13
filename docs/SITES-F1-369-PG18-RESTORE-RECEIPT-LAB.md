# F1-369 — Restauração do banco de recibos em terceira instância PostgreSQL 18

**DRAFT / NO-GO.** Experimento exclusivamente no GitHub Actions, com dados, bancos e senhas inteiramente fictícios. Não implementa backup produtivo, plano de recuperação de desastre, TLS/mTLS, Portal real ou entrada em operação.

## Cenário

A F1-368 provou que uma resposta HTTP perdida depois de um COMMIT não cria duas entregas após reiniciar o processo receptor. Ainda faltava provar o comportamento após **restaurar a base que guardava os recibos**, e não apenas reiniciar o processo.

A F1-369 adiciona uma terceira instância PostgreSQL 18 descartável, separada das duas anteriores:

1. O banco Sites (porta 5432) possui admissões, nonce e outbox; o Portal fictício original (porta 5433) mantém os recibos.
2. O trabalhador envia um novo evento; o Portal grava o recibo e confirma COMMIT, mas responde HTTP 503 por falha simulada de resposta. O Sites **não envia ACK**.
3. O CI gera um backup lógico **real** da base Portal original com `pg_dump -Fc` executado pelo cliente PostgreSQL 18 em Docker. O arquivo temporário contém apenas registros fictícios e não é publicado como artefato.
4. O CI importa o backup para uma **terceira instância** PostgreSQL 18 (porta 5434), em banco vazio, com `pg_restore --single-transaction --exit-on-error`. A importação é verificada por SQL, incluindo a correspondência do evento cujo ACK ainda está pendente.
5. O contêiner original do Portal é parado. Um receptor HTTP local é iniciado novamente, desta vez com um DSN **fixo e restrito** ao banco de restauração, habilitado somente sob duas flags de CI.
6. O worker lê a operação pendente no Sites, vence seu lease fictício, reclama com token novo, reenvia a mensagem ao receptor e recebe `already_recorded` assinado. O segundo banco não cria outro registro, rejeita digests divergentes e o Sites só então confirma o outbox.

## Garantias demonstráveis no laboratório

- Restauração SQL de uma cópia lógica anterior ao retry, em serviço PostgreSQL 18 diferente
- Preservação da chave idempotente `tenant_ref + operation_ref` e do SHA-256 do evento
- Reação correta à ausência de recibo apesar de COMMIT na fonte
- Recebimento idempotente pelo banco restaurado sem banco de origem disponível
- Bloqueio de ACK com lease antigo, ACK duplicado e conteúdo alterado
- Nenhuma admissão fictícia sem evento outbox
- Trava de execução por `APP_ENV=testing`, `GITHUB_ACTIONS=true`, flag F1-369 e alvos de banco com nomes/portas esperados

## O que isso não prova

Todos os três contêineres e o backup temporário estão no **mesmo runner do GitHub Actions**. Esta não é uma política real de retenção ou backup fora da região, nem comprova RPO/RTO, restauração pós-desastre, integridade de storage externo, chaves em secret manager, autenticação oficial do Portal, conciliação de pagamentos, privacidade, IAM, publicação e tráfego HTTPS. O servidor PHP embutido, os bancos/contas e os HMACs são fixtures. A fonte de revogação independente continua pendente, assim como as migrações produtivas, revisão DBA/AppSec e gates de homologação.

Nenhuma operação de backup, remoção, indisponibilidade ou deploy toca a VPS Leaseweb, bases reais, Cloudflare, DNS ou `main`/`dev`: o único `docker stop` mira o contêiner temporário do job.
