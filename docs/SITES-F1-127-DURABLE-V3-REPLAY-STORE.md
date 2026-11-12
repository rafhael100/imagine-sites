# F1-127 — Impedir substituição do armazenamento durável de nonces no intake HMAC v3

## Problema

A F1-125 introduziu `LabPreflightNoPersistReplayStore`, que implementa a interface `ReplayStore` **apenas** para validar o HMAC e os campos do envelope v3 antes de consultar a fonte de autorização comercial. Essa implementação não registra nenhum nonce e retorna sucesso repetidamente enquanto o laboratório está habilitado.

A mesma interface aparece como dependência do `LabSignedProjectIntake`. Antes desta etapa, um chamador de laboratório que injetasse por engano o adaptador de pré-verificação — ou uma implementação fictícia `ReplayStore` que sempre retorna `true` — poderia executar o fluxo v3 **sem proteção durável contra repetição do nonce**. A idempotência de criação de projeto não substitui a rejeição de replay de mensagens assinadas.

## Mudança

Quando `SITES_F125_REQUIRE_COMMERCIAL_EPOCH_SIGNED=1`, o `LabSignedProjectIntake` agora exige que o objeto `$replay` recebido no construtor seja uma instância do adaptador restrito `LabPostgresReplayStore`. Caso contrário, retorna `lab_v3_durable_replay_store_required` **antes** de buscar chave, revisão comercial ou registrar qualquer nonce.

A implementação `LabPreflightNoPersistReplayStore` continua disponível **apenas dentro da etapa de pré-verificação**, que não faz a reserva do nonce. O segundo passe do verificador HMAC v3 continua usando a instância de armazenamento durável aprovada.

A regra é **deliberadamente específica ao laboratório PostgreSQL 18**; não é uma abstração final para suportar Redis, outro banco ou serviço de autorização em produção. A escolha operacional de armazenamento durável deve passar por projeto, revisão e validação independente.

## Testes

`tests/f111-pg-durable-signed-intake.php` inclui casos F1-127, na matriz PG18 PHP 8.3/8.4:
- substituir o storage durável pela classe de pré-verificação é recusado;
- injetar um `ReplayStore` fictício que aceita indiscriminadamente todos os nonces é recusado **sem sequer chamar seu método**;
- nenhuma consulta à fonte comercial ou mutação de projeto ocorre antes dessa recusa;
- a mesma mensagem continua disponível na base PostgreSQL real e é admitida uma vez, preservando idempotência;
- uma repetição em outra sessão PostgreSQL é negada, como esperado.

A verificação de classe não prova durabilidade de uma implementação futura fora deste laboratório, mas evita o erro concreto de configuração reconhecido nesta etapa.

## Limites operacionais

O sistema ainda usa identidades, permissões, HMAC e épocas fictícias; nenhum endpoint ou fonte oficial do Portal foi conectado. A role SQL de teste não deve ser exposta a clientes. As duas bases de nonce e projeto não compartilham transação distribuída; falhas após consumir o nonce exigem novo envelope. IAM real, vault, mTLS/OAuth, homologação HTTPS e auditoria AppSec independente permanecem necessários.

Sem merge dev/main, publicação, uso da VPS, DNS, Cloudflare, PII ou despesas. **10/10 gates de produção BLOCKED.**
