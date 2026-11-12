# F1-118 — Assinar a revisão da chave no envelope candidato v2

## Problema

A F1-117 fixa uma revisão monotônica independente para a chave HMAC durante a operação, inclusive para detectar A→B→A **durante** a transação. Porém, o envelope de assinatura v1 não informa autenticadamente a revisão em que foi emitido. Se a chave A for restaurada antes de iniciar uma nova operação, a assinatura v1 antiga pode passar pela verificação com os mesmos bytes, embora a revisão da chave já seja outra.

## Implementação exclusivamente sintética

A classe `SyntheticVersionedSignedCommand` introduz um envelope HMAC **v2**, de estrutura estrita, com os campos em ordem exata: `schemaVersion`, `issuer`, `audience`, `issuedAt`, `expiresAt`, `nonce`, `payload`, **`keyEpoch`** e `mac`.

- O HMAC SHA-256 cobre o campo `keyEpoch`, o comando, o emissor/audiência, o nonce e o prazo. Uma tentativa de alterar a revisão do envelope invalida o MAC.
- O verificador exige que `keyEpoch` seja inteiro positivo e igual à revisão corrente obtida independentemente pela F1-117. **Revisão antiga é recusada antes de registrar o nonce**.
- Após a MAC v2 ser validada, o código reconstrói internamente uma representação v1 para reutilizar suas verificações estritas do comando, de prazo e de unicidade de nonce. Isso não significa que o envelope v1 recebido pelo cliente seja aceito como v2.
- `LabSignedProjectIntake` usa o novo verificador **somente** quando `SITES_F118_REQUIRE_EPOCH_SIGNED=1`. Sob essa opção, um envelope antigo v1 é negado. Quando a opção não está ativa, preserva-se exclusivamente a compatibilidade dos testes laboratoriais pré-existentes, que usam v1.

## Testes integrados

- `tests/f94-pg-authorized-project-admission.php`: exige que o modo v2 recuse v1; realiza A→B→A antes de iniciar a operação; comprova que o envelope com revisão antiga falha, mesmo com segredo original restaurado. Um novo envelope v2 com revisão atual e nonce ainda livre é admitido somente como replay idempotente. Alterar `keyEpoch` no envelope também falha.
- `tests/f111-pg-durable-signed-intake.php`: repete os mesmos casos usando armazenamento real de nonce e admissão em duas bases PostgreSQL 18 **descartáveis**, duas conexões independentes, negação antes de consumir nonce e proibição de novo uso após consumo. Matriz PHP 8.3/8.4 pelo workflow F1-94.

## Limitações importantes

O envelope v2 é um **protótipo local não homologado pelo Portal**. Não é o contrato oficial `CreateProject` nem foi emitido por uma identidade operacional. O repositório Portal atualmente usado nos testes cross-repo ainda produz o envelope v1 sintético; adotar v2 de fato exigirá versionamento e testes de interoperabilidade entre os **dois repositórios atuais**.

Ainda não existe origem de revisão de chave realmente monotônica, vault/secret manager, mTLS/OAuth, assinatura assimétrica, aceitação oficial de contrato pelo cliente ou revogação distribuída. O código não garante que a origem de chave não seja falsificada/atrasada nem que nenhuma revogação ocorra depois do COMMIT. O modo legado v1 continua disponível **somente em laboratório** para não romper testes históricos, portanto não deve ser promovido a produção.

Nenhuma infraestrutura, credencial real, cliente, merge, deploy, VPS ou custo está envolvido. **10/10 gates operacionais permanecem BLOCKED.**
