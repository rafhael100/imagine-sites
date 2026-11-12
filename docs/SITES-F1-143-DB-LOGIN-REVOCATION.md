# F1-143 — Revogação de escopo do login PostgreSQL (laboratório)

## Avanço sobre F1-142

A F1-142 restringiu o registro técnico pela organização vinculada ao login de banco. A F1-143 acrescenta `active` e `revision` ao mapeamento persistente da fixture, com trigger que recusa repetição/retrocesso da revisão, alteração de organização/login, exclusão e reativação silenciosa.

A função `try_login_scoped_claim` passa a consultar o escopo com `FOR SHARE` **dentro da mesma transação** do claim de metadados. Uma revogação administrativa concorrente deve aguardar o término dessa transação. Quando aplicada, até uma sessão já conectada é recusada com `DB_LOGIN_SCOPE_DENIED`.

O teste PostgreSQL 18 em PHP 8.3/8.4 demonstra contenção real (SQLSTATE 55P03), revogação sem reconexão, tentativa de forjar GUC, impossibilidade de reativação e preservação da operação da outra organização. Tudo em banco descartável de CI, usando identificadores e credenciais fictícios.

## Limites de segurança

Não se trata de credencial ou IAM de serviço real, identidade de usuário do Portal, autenticação de OAuth, emissão de assinatura, confirmação do CRM ou consentimento. O controle é somente de um login isolado de banco e requer um mapeamento confiável mantido exclusivamente por uma autoridade segura. Pools de conexão com a mesma role para empresas diferentes não podem usar esse esquema como isolamento de tenant. Sem registros reais/PII, deploy, VPS, gastos ou merge em dev/main.

OP04 (#36) e todos os dez gates de produção seguem bloqueados até IAM real, teste adversarial AppSec/DBA e homologação.
