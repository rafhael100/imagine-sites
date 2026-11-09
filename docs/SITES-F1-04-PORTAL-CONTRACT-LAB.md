# SITES-F1-04 — Laboratório de contrato Portal → Sites

**Situação:** implementação de contrato sintético, sem API HTTP, banco real, conexão Portal, criptografia ou clientes. PR filho F1-03, sem merge/deploy.

## Objetivo
Reificar `CreateProject v1` numa estrutura validável e evoluir a fronteira de autoridade antes de abrir endpoints. O contrato de campos estritos evita ambiguidades de `tenantRef`, `orderRef`, produto e revisão comercial.

## Interface proposta
`SiteProjectCommand`: `schemaVersion`, `operationId`, `tenantRef`, `orderRef`, `contractRef`, `contractRevision`, `productCode` e `idempotencyKey`.
- `productCode` apenas `onepage` ou `landing_page` na fase 1.
- `schemaVersion` fixado em '1'; referências opacas e revisão inteira positiva.
- JSON Schema no caminho `contracts/portal-sites/v1/create-project.schema.json`.
- Payload apenas demonstrativo em `fixtures/`. Não enviar briefing, lead, credencial ou dados pessoais.
- `DenyingPortalProjectAuthorityGateway` nega por padrão. `AllowListedPortalFixture` só existe em `tests/` e **não** comprova autorização/identidade real.
- `SyntheticProjectRegistry` é somente in-memory para demonstrar deduplicação e conflito por ordem/tenant; não garante idempotência durável nem isolamento entre processos.

## Requisitos pendentes para operacionalizar
1. Assinatura/autenticação serviço-serviço e consulta de autoridade, tenant/contract atual e revogação verificável.
2. Persistência PostgreSQL Sites própria, unique constraint, transações, outbox e idempotência durável com concorrência.
3. Contrato formal de resposta/erros, semântica de replay, expiração, ordenação, compatibilidade e esquema de eventos.
4. Testes negativos de troca de empresa, contrato cancelado, token falso, requisição antiga, SSRF, timeout e reprocessamento.
5. Portal envia o contrato apenas com elegibilidade validada; Sites revalida independentemente. Não deduzir quitação pelo `payload`.
6. Aprovação de publicação continua separada e posterior ao aceite expresso da versão exata; este comando **não** publica.

## Gates
P0-DOC03, RF-PEND-01, RF-PEND-04, RV-CT01–08 e validações LGPD permanecem abertos. Sem merge/deploy, dados reais ou despesas.
