# F1-144 — Adaptador PHP de registro técnico limitado a tenant

Esta etapa faz o código PHP do Sites consumir a função **de laboratório** `f140_lab.try_login_scoped_claim` em PostgreSQL 18, sob uma transação PDO que precisa estar aberta e uma das duas roles técnicas fictícias esperadas. Antes disso, verifica modo CLI/testing e variáveis CI explícitas.

O retorno é um DTO de **diagnóstico restrito**. Mesmo `CLAIM_RECORDED_UNVERIFIED` ou `EXISTING_CLAIM_UNVERIFIED` não permitem aceitar lead, reconhecer visitante, marcar entrega CRM ou reenvio automático. Códigos inesperados resultam em `UNEXPECTED_DATABASE_STATE`, nunca em estado de entrega.

A suíte `tests/f144-pdo-tenant-bound-claim.php` executa PHP 8.3/8.4 contra PostgreSQL 18 descartável, com duas organizações sintéticas e a cadeia F1-140..143: transação obrigatória, isolamento de login, revisão inválida, destino diferente, replay, opt-out CI, revogação da sessão já conectada e zero falsa confirmação.

**Importante:** o login do banco não equivale a IAM do Portal ou autenticação do usuário/cliente; nenhuma dessas interfaces pode ser exposta em HTTP ou deploy. Não há formulário real, PII, consentimento, entrega CRM, token de serviço, evidência de recepção, filas reais ou idempotência intersistemas. Os dez gates e OP04 #36 permanecem bloqueados. Revisões AppSec/DBA e integração operacional estão pendentes, sem custos ou publicação.
