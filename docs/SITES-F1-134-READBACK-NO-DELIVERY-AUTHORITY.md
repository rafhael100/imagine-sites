# F1-134 — Leitura de idempotência PostgreSQL sem confirmação fictícia de lead

## Objetivo

A F1-133 demonstrou que uma restrição física em PostgreSQL 18 pode escolher exatamente um registro por `organization_reference + submission_reference` e preservar o vínculo, site, formulário, destino CRM original e T0 gerado pelo banco. Falta uma decisão de leitura separada, sem confundir o registro técnico com uma entrega ou ACK.

## Implementação de laboratório

`LabPostgresCrmLeadMetadataReadback::inspect` consulta a linha física por empresa e submissão exclusivamente em uma transação aberta sob login restrito `sites_f133_lead_app` no banco descartável `sites_f133_lead_lab`, com `APP_ENV=testing`, `GITHUB_ACTIONS=true` e flag `SITES_F133_LEAD_PG_LAB=1`.

- Sem ambiente de laboratório → `LAB_DISABLED`.
- Sem transação → `TRANSACTION_REQUIRED`.
- Role, banco ou `app.tenant_id` diferentes do contexto sintético do pedido → `UNTRUSTED_LAB_CONTEXT`.
- Sem registro físico → `NO_STORED_METADATA`.
- Vínculo, site, formulário ou destino original divergentes → `PINNED_CONTEXT_CONFLICT`.
- Mesma linha e contexto completo → **`METADATA_MATCH_UNVERIFIED`**, nunca `DELIVERED` ou `ACK`.
- Erro durante a consulta → `INCONCLUSIVE_DATABASE`, com negação sem divulgar dados.

O DTO `SyntheticCrmLeadMetadataReadbackAssessment` retorna apenas código fixo de diagnóstico e mantém **todos** os métodos de capacidade em `false`: `canReserveSubmission`, `canAcknowledgeVisitor`, `canMarkDeliveredToCrm` e `canRetryAutomatically`.

## Evidência

O mesmo job F1-133 de PostgreSQL 18, PHP 8.3/8.4 e duas conexões PDO testa um registro original, alteração de cada uma das quatro dimensões imutáveis, submissão ausente, tentativa fora da empresa/da transação, desligamento de flag e ausência de qualquer permissão operacional. A prova de unicidade física, RLS, bloqueio de permissões, lock SQLSTATE 55P03 e rollback da F1-133 continua ativa.

## Limites

Isto **não** constitui uma autorização de identidade/tenant, consentimento, recebimento físico de lead ou confirmação do Imagine CRM. A GUC SQL de tenant é configurada pela fixture e pode ser falsificada se houver acesso SQL arbitrário. Não existe ledger operacional compartilhado entre dois pontos de entrada, autenticação mútua, prova durável de entrega, criptografia/retenção LGPD, clock T0 confiável fora do teste ou parecer SEG/DBA/AppSec.

OP04 (#36) e todos os dez gates de produção seguem bloqueados. PR draft/empilhado; sem merge em dev/main, deploy, cliente/PII, VPS, DNS, Cloudflare ou novos custos.
