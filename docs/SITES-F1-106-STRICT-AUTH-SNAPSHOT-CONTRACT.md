# F1-106 — Contrato candidato versionado e decodificação estrita de autorização

## Objetivo

Padronizar a forma do **registro candidato** que poderá ser entregue por um futuro serviço de autorização do Portal, evitando aceitar campos ocultos ou tipos inesperados antes da verificação de origem e de revogação.

## Implementação

- `CurrentPortalProjectAuthorizationSnapshot::fromArray(array $candidate)` exige **exatamente** os seis campos: `schemaVersion`, `boundCommand`, `authorizationRevision`, `status`, `checkedAt` e `validUntil`. O schemaVersion deve ser `sites.portal-project-authorization.v1`.
- `boundCommand` é interpretado por `SiteProjectCommand::fromArray`, que exige todos os campos do pedido, contrato, operação, empresa, produto e idempotência, rejeitando campos extras e tipos incorretos.
- A revisão da autorização e os timestamps precisam ser inteiros; o construtor impõe status elegível/suspenso/revogado, timestamp válido e vigência máxima de 30 segundos.
- `contracts/portal-sites/v1/project-authorization-snapshot.candidate.schema.json` registra a estrutura JSON Schema 2020-12, com referência ao contrato `create-project.schema.json`.

`tests/f102-portal-project-snapshot.php` testa roundtrip JSON, campo `publishable` adicional, validade ausente/excessiva, revisão string, timestamp booleano, tipo incorreto no objeto aninhado, outra empresa e versão desconhecida; executado nas matrizes PHP 8.3/8.4.

## Limitações e segurança

Um objeto com formato correto **não** é uma mensagem assinada, um token ou prova de que o Portal autorizou o pedido. Esta operação é apenas um decoder de estrutura em laboratório, sem rede. Um futuro adaptador autenticado deve validar emissor, audiência, assinatura/credenciais, epoch atual, revogação, autorização por recurso e trilha de auditoria, antes de confiar nos dados. O teste não confirma compatibilidade operacional com o repositório Portal.

Não há integração pública, publicação, dados pessoais, custo ou merge. Os 10 gates de produção continuam `BLOCKED`.
