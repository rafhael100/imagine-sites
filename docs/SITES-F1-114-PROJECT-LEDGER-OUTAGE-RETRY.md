# F1-114 — Contrato de recuperação após falha da admissão com nonce consumido

## Motivação

As etapas F1-110 a F1-113 encadeiam HMAC fictícia, controle de nonce em PostgreSQL18 e admissão de projetos em outra base descartável. Como esses registros não compartilham a mesma transação, existem dois resultados distintos que precisam ser documentados e testados:

- **Falha antes de consumir o nonce:** a mesma mensagem pode ser reutilizada após recuperar a base de nonces (F1-113).
- **Falha depois de consumir o nonce e antes de confirmar a admissão:** a mensagem original não pode ser reutilizada; a recuperação precisa de um **novo envelope, com novo nonce**, mantendo os mesmos identificadores e a chave idempotente do projeto.

## Prova automatizada

O teste `tests/f111-pg-durable-signed-intake.php` agora inclui um cenário F1-114, executado somente no PostgreSQL 18 descartável. Ele usa um novo PDO administrativo de laboratório para retirar temporariamente a permissão `SELECT` do login restrito `sites_f94_app` sobre `sites_project_admissions`, após as linhas de um projeto fictício já existirem.

O cenário exige que `LabSignedProjectIntake` valide o envelope, consuma o nonce no banco próprio e **negue** a leitura/transação posterior no banco do projeto. Restaura a permissão em bloco `finally` e comprova, em seguida:

1. Nenhuma nova linha foi gravada na admissão do projeto.
2. Tentar novamente o mesmo envelope assinado continua negado, pois o nonce já foi consumido no banco de replay.
3. Assinar o mesmo comando/idempotência com outro nonce permite apenas replay idempotente, sem duplicar projeto.

Os testes F1-114 rodam no workflow de PostgreSQL 18 descartável, nas versões PHP 8.3 e 8.4. Não existe nenhuma modificação de banco operacional.

## Limitações

É uma simulação controlada de perda de permissão de leitura, não uma garantia completa sobre falhas de rede, partições, indisponibilidade do PostgreSQL, replicação ou recuperação de desastres. O fluxo de produção exigiria uma política explícita de retries, erros seguros, identidade Portal, recuperação de chaves, armazenamento de nonce em alta disponibilidade e observabilidade; **não** há commit distribuído entre as bases.

O projeto permanece no laboratório CLI/testing com HMAC e fonte de autorização fictícias. Sem endpoint público, IAM operacional, CRM, aceite do cliente, publicação, merge, VPS ou custo. Todos os dez gates operacionais permanecem `BLOCKED`.
