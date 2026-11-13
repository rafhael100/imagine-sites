# F1-116 — Rechecagem da chave Portal antes do COMMIT do projeto

## Falha que esta etapa mitiga

Na F1-115, a chave HMAC de verificação passou a ser obtida de uma fonte separada do chamador. Entretanto, uma chave válida **no começo** da operação poderia ser revogada ou substituída durante a transação PostgreSQL. A validação inicial do envelope, isoladamente, não impede que a admissão seja confirmada depois dessa revogação.

## Implementação

`LabPinnedPortalSigningKeyAuthorityGateway` envolve o gateway de autorização candidato. Em cada avaliação, consulta novamente a fonte injetada de chave Portal, com emissor e audiência fixos `imagine-portal` → `imagine-sites`. Exige chave ainda válida e correspondência com a chave que passou na verificação HMAC original; usa `hash_equals` de digests SHA-256. Chave ausente, erro da origem ou rotação/revogação produzem `PolicyDecision(false)` sem entrar no gateway de autorização delegado.

`LabSignedProjectIntake` passa esse wrapper para `LabAuthorizedProjectAdmissionStore`, que já avalia o gateway em **dois pontos**: antes de iniciar a transação e imediatamente antes do commit. Assim, uma mudança detectada na segunda avaliação causa rollback de projeto e admissão.

## Testes

- `tests/f94-pg-authorized-project-admission.php`: fonte de chave fictícia revoga a chave exatamente na terceira consulta (HMAC, autorização inicial, pré-commit) durante a criação de um novo projeto. A suíte exige ausência de ambas as linhas SQL, rollback, e posterior criação com chave/restauração e nonce novo.
- `tests/f111-pg-durable-signed-intake.php`: o mesmo cenário é executado como replay idempotente com nonce consumido em **outra base PostgreSQL 18 descartável**, exigindo negação no commit, registro original inalterado, nonce rejeitado em segundo uso e recuperação somente com novo nonce assinado.
- O workflow de PostgreSQL 18 executa a matriz de PHP 8.3 e 8.4, além dos testes existentes de nonce e admissão.

## Limites operacionais

A fonte de chave dos testes é inteiramente **fictícia**. Uma fonte atrasada ou comprometida pode continuar apresentando o mesmo segredo mesmo após revogação; essa verificação não é garantia distribuída de revogação em tempo real. Mudança depois da última consulta/commit continua possível. A identidade real do serviço Portal, vault, mTLS, políticas de chaves, logs, retenção, aprovação imutável, deploy e publicação seguem pendentes.

Os bancos são descartáveis e não compartilham commit distribuído. A role de aplicação não deve ser exposta para consultas SQL arbitrárias. **10/10 gates de produção permanecem BLOCKED**, sem merge em dev/main, VPS/Cloudflare, dados reais, publicação ou novas despesas.
