# F1-117 — Época monotônica da chave de assinatura (anti-ABA, somente laboratório)

## Problema

A F1-116 revalida o segredo HMAC no início da admissão e imediatamente antes do COMMIT. Porém, comparar somente os bytes da chave pode ignorar um cenário **ABA**: a origem entrega a chave A, ela é rotacionada para B e, durante a mesma operação, volta a entregar exatamente A. O digest da primeira e da última leitura é igual, mas houve mudança no estado de confiança.

## Implementação candidata

- `PortalSigningKeyEpochSource::currentEpochFor(issuer, audience)` define uma **fonte independente** de revisão positiva da chave. Sua implementação padrão, `DenyingPortalSigningKeyEpochSource`, retorna `null` e nega qualquer admissão.
- `LabSignedProjectIntake` fixa uma revisão positiva antes de verificar a HMAC e de consumir o nonce. A ausência/erro da origem de revisão nega sem consumir nonce nem consultar banco de projetos.
- `LabPinnedPortalSigningKeyAuthorityGateway` consulta novamente **segredo e revisão**, antes da admissão e imediatamente antes do COMMIT. Divergência de bytes, revisão alterada, ausência ou erro negam. O `LabAuthorizedProjectAdmissionStore` existente faz rollback em caso de negativa.
- A revisão da chave HMAC é distinta da revisão de autorização por contrato/pedido da F1-105. Elas resolvem problemas diferentes e não substituem o IAM oficial.

## Testes

- `tests/f94-pg-authorized-project-admission.php`: uma rotação fictícia A → B → A na terceira consulta da chave eleva a época enquanto os bytes finais permanecem iguais. O pré-COMMIT nega e desfaz **ambas** as linhas de novo projeto e admissão. Uma nova operação com nonce fresco e revisão atual consegue criar uma única admissão.
- `tests/f111-pg-durable-signed-intake.php`: verifica fonte de revisão default DENY/indisponível antes de consumir nonce, cenário ABA em replay idempotente com duas bases PostgreSQL 18 descartáveis, nonce persistido antes do rollback e recuperação com nonce novo e revisão atual.
- O workflow `sites-f1-94-project-admission.yml` executa ambas as suítes em PHP 8.3 e 8.4, com validação sintática das classes adicionadas.

## Limites e pendências

Isto só detecta mudança de revisão **observável dentro da operação**. Não demonstra monotonicidade real da origem, que hoje é uma fixture em memória. Se a origem da revisão voltar para um valor antigo após rollback de backup, for comprometida ou apresentar dados atrasados, não existe garantia distribuída de revogação. Uma mudança ocorrida depois da última consulta também não é capturada.

**O envelope assinado sintético ainda não carrega uma revisão de chave autenticada.** Portanto, se uma chave antiga for restaurada **antes** do início da operação, esta verificação, por si só, não identifica que a mensagem foi assinada sob uma geração anterior. A solução operacional precisará vincular `keyId`/época autenticados ao envelope e à identidade de serviço e usar fonte durável/monotônica externa ao domínio de backup do Sites, além de mTLS/OAuth e vault com rotação.

Nenhum cliente, credencial operacional, endpoint público, deploy, merge ou serviço pago é envolvido. **10/10 gates de produção permanecem BLOCKED.**
