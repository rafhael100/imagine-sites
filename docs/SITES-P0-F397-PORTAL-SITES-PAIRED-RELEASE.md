# Imagine Sites F1-397 — Portal e Sites validados com as duas provas no mesmo teste

## Fontes exatas e auditáveis

O teste executa a suíte F1-395 **original do Imagine Portal** da [PR #756](https://github.com/rafhael100/imagine-portal/pull/756) na SHA verde `b444d0c1ecd315770555f9e8a51ee595bbfa5b4e`. Essa suíte gera, com duas chaves Ed25519 efêmeras, prova de liquidação/contrato e recibo de aceite editorial, após verificar um vínculo confiável entre pedido e release. Não copia nem recria o gerador do Portal.

Os bytes produzidos pelo Portal alimentam os verificadores **originais do Imagine Sites** da [PR #476](https://github.com/rafhael100/imagine-sites/pull/476): `IndependentPortalCommercialGateway`, `SignedPortalClientApprovalGate` e `SiteDualProofEvidenceCheck`.

## Cobertura

A matriz PHP 8.3/8.4 verifica, na mesma execução, o cenário positivo com ambas as provas, duas leituras da associação confiável, as testemunhas PSP/contrato/consentimento independentes e a rejeição de:
- cliente aprovou, mas pagamento não foi confirmado pela testemunha PSP;
- cobrança válida, mas contrato ou aceite editorial foi revogado;
- certificado financeiro ou aceite com campos adulterados;
- mesmo tenant, mas outro pedido/contrato, outra versão HTML ou associação de release modificada;
- indisponibilidade do serviço de vínculo e revisão que muda entre as duas verificações;
- fonte padrão não configurada.

Este teste prova **compatibilidade de código**, sem construir novas chaves operacionais ou comunicação HTTPS. Os emissores e verificados foram compilados a partir dos dois repositórios fixados, em runner descartável; a origem Sites utiliza a HEAD exata desta PR.

## Bloqueios para produção

Não há cliente real autenticado via MFA, contrato com parcelas, leitura live da conta Asaas Sandbox, validação de nota fiscal, fonte oficial de vínculo pedido↔release, testemunha de revogação operacional, nonce/outbox ou autorização no executor Cloudflare. A API S2S HTTPS/mTLS da PR Sites #474 continua apenas de laboratório e a revisão jurídica/segurança, backup e migração na VPS Leaseweb permanecem pendentes.

**DRAFT / NO-GO / NO MERGE / NO DEPLOY / NO BILLING / NO PUBLISH.**
