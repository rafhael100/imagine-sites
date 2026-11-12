# F1-125 — Admitir HMAC v3 com revisão comercial autenticada e revalidada no PostgreSQL

## Objetivo

A F1-124 provou o envelope HMAC v3 entre classes independentes Portal/Sites. Entretanto, essa verificação ainda não participava da **admissão transacional PostgreSQL** do Site. A F1-125 integra a validação de `commercialEpoch` na entrada CLI/testing e na rechecagem anterior ao COMMIT, usando fontes fictícias separadas para prova de conceito.

## Fluxo no laboratório

- `SITES_F125_REQUIRE_COMMERCIAL_EPOCH_SIGNED=1` seleciona **exclusivamente** o v3. Não pode coexistir com flags de modo v1/v2; ausência de modo continua DENY. O modo v3 nunca faz downgrade silencioso.
- `PortalProjectCommercialEpochSource::currentEpochFor(SiteProjectCommand)` modela uma futura consulta autenticada e vinculada ao pedido/contrato. `DenyingPortalProjectCommercialEpochSource` é a implementação padrão, que devolve `null`.
- Antes de chamar uma fonte por recurso, `LabSignedProjectIntake` autentica o envelope recebido usando o HMAC v3 e um `LabPreflightNoPersistReplayStore` (apenas pré-verificação, **não reserva nonce**), obtendo assim um comando autenticado sinteticamente.
- Em seguida consulta a revisão comercial corrente por comando e exige que ela seja igual ao `commercialEpoch` **assinado pelo Portal**. Só então verifica novamente o envelope com o `ReplayStore` PostgreSQL real, consumindo o nonce uma vez.
- `LabPinnedCommercialEpochAuthorityGateway` revalida a mesma revisão na admissão inicial e imediatamente antes do COMMIT, por meio do `LabAuthorizedProjectAdmissionStore` já existente. Mudança detectada implica DENY e rollback de projetos/admissões; a revisão da chave HMAC é verificada separadamente pelas F1-115..117.
- Tentativas negadas antes do registro de nonce deixam a mensagem disponível; se a mudança ocorrer após o registro em outra base, um retry exige **nova assinatura/nonce**.

## Testes

`tests/f111-pg-durable-signed-intake.php` agora inclui F1-125 com duas bases efêmeras PostgreSQL 18 e PDOs independentes: uma para o ledger de projetos e outra para nonces. A matriz PHP 8.3/8.4 demonstra:
- fonte comercial padrão DENY;
- mensagem v3 antiga recusada quando revisão comercial muda antes de consumir nonce;
- HMAC v3 atual cria uma única admissão, com replay idempotente;
- assinatura/época comercial adulterada e v2 apresentado em modo v3 recusados;
- revisão comercial alterada exatamente antes do COMMIT nega e faz rollback;
- nonce previamente consumido continua inutilizável e a recuperação exige novo envelope v3 com revisão atual.

Os testes não criam endpoint público, não usam credencial real e não alteram infraestrutura operacional.

## Limitações

A pré-verificação usa um armazenador de nonce **não persistente**, exclusivamente antes da verificação do `ReplayStore` durável; não pode ser usado isoladamente como controle de replay. Fonte comercial é fixture em memória, não IAM oficial. O HMAC é simétrico com chave fictícia. Revisões podem estar obsoletas, restauradas de backup ou mudar após o COMMIT. A GUC de tenant SQL não equivale a identidade confiável se um cliente possuir acesso SQL arbitrário. Os bancos de nonce e projeto não compartilham transação distribuída. São necessárias fontes de autorização reais, canal S2S mutuamente autenticado, vault, registros auditáveis, teste HTTPS em homologação, revisão independente AppSec e processo de aprovação de produção.

**10/10 gates de produção BLOCKED.** PR draft empilhado, sem merge em dev/main, publicação, cliente/PII, VPS, DNS, Cloudflare ou custos.
