# F1-115 — Chave de assinatura Portal fixada fora do chamador (laboratório)

## Problema e correção

A F1-110 recebia `$syntheticKey` como argumento em `LabSignedProjectIntake::admitSigned`. Isso permite que um chamador do método apresente **sua própria chave de verificação** junto com uma mensagem assinada por ela, sem um vínculo independente com o Portal. Mesmo sendo apenas laboratório, esse padrão não é adequado como base para a integração autenticada.

A F1-115 elimina esse argumento. O método `admitSigned(array $envelope)` resolve a chave no componente interno `PortalSigningKeySource::currentKeyFor('imagine-portal','imagine-sites')`, passado por injeção de dependência durante a construção e **não** no pedido. A implementação padrão `DenyingPortalSigningKeySource` retorna `null`, preservando DENY.

A classe confirma emissor e audiência esperados antes de consultar o resolvedor. Se a chave estiver ausente, possuir menos de 32 bytes ou ocorrer falha no resolvedor, a entrada é negada antes de `SyntheticSignedCommand::verify` consumir qualquer nonce. As verificações HMAC, nonce, autorização atual (duas vezes) e idempotência PostgreSQL anteriores permanecem.

## Evidências

O teste `tests/f111-pg-durable-signed-intake.php` executa nas duas bases descartáveis PostgreSQL 18, PHP 8.3/8.4, verificando que:

- uma instância sem fonte de confiança configurada nega a entrada por padrão;
- remoção de chave e erro de consulta à fonte negam sem consumir nonce;
- após recuperar a fonte sintética, um nonce ainda não consumido pode ser utilizado;
- trocar a chave sintética rejeita assinatura antiga e aceita outra assinada com a chave atual, mantendo o controle de replay entre conexões;
- emissor inesperado é recusado antes de consultar a fonte de chaves.

O teste `tests/f94-pg-authorized-project-admission.php` usa a mesma fonte fictícia para preservar o fluxo integrado com snapshots de autorização e epoch. O workflow F1-94 foi atualizado para disparar quando o contrato de chaves mudar.

## Limites e condições de produção

A origem usada nos testes é uma **fonte em memória** preenchida com chaves públicas fictícias de CI. Não existe vault, autenticação mútua, identidade Portal real, política de rotação/revogação operacional, segregação de tenants, gestão de segredos, HSM, OAuth, resposta assinada por recurso, nem garantia de que uma origem futura seja de fato confiável. A chave simétrica prova apenas conhecimento do segredo compartilhado, não aceitação do cliente. Também não existe transação distribuída entre nonce e projeto.

O adaptador segue limitado a PHP CLI/testing e ao PostgreSQL descartável, sem endpoint HTTP, cliente, deploy, merge, VPS/Cloudflare ou custos. **10/10 gates operacionais de produção permanecem BLOCKED.**
