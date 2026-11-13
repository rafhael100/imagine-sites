# Imagine Sites — candidato integrado F1-263 para `dev` (12/11/2026)

**Status:** pacote de revisão integral e NÃO mesclado, sem deploy, sem promoção operacional e sem autorização de release.

## Base e escopo rastreáveis

- `dev` fixada em `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`; `main` em `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.
- Origem deste candidato: PR #305 / F1-263, descendente de #300 e #301–#304; em comparação direta com `dev`, estava 1.076 commits à frente, zero atrás antes da documentação deste candidato.
- A API compare do GitHub limita a exibição a 300 arquivos. É obrigatório executar F1-240 e F1-241 neste HEAD para registrar a contagem **exata** de todos os caminhos e os seis pacotes de revisão.

## Funcionalidades incorporadas após F1-258

| Fase / PR | Entrega | Evidência inicial |
| --- | --- | --- |
| F1-259 / #301 | Evidência de CTA sem referências legíveis de empresa/site/release | 6/6 CI aprovados |
| F1-260 / #302 | CTA secundário usa paleta de marca com contraste e paridade de arquivos | 22/22 CI aprovados |
| F1-261 / #303 | Revisor Laravel: quatro paletas, 96 visualizações e proteção contra query incompleta | 24/24 CI aprovados |
| F1-262 / #304 | Pré-validação privada de contato, consentimento não verificado e honeypot | 8/8 CI aprovados |
| F1-263 / #305 | Pré-admissão de lead CRM com privacy gate, vínculo e replay de submissão | CI específica aprovada; conferir conclusão de todos os workflows antes de qualquer integração |

## Revisão necessária

1. Validar no SHA exato os workflows PHP, Laravel, PostgreSQL 18, segurança, infraestrutura, F1-240 e F1-241, sem inferir o resultado de PRs anteriores.
2. Auditar todos os arquivos sensíveis em seis pacotes: visual/renderizador, banco/segurança, integrações CRM/Portal/Agentes, infraestrutura/CI, testes e governança.
3. Exigir administradores reais para rulesets de `dev`/`main`: ambas as branches ainda mostram `protected:false` na API. Issue #281.
4. Revisar independentemente AppSec, DBA, Portal, CRM, consentimento, revogação, retenção, T0 durável, 72h/432h, segurança, autenticação e publicação externa.
5. Preservar `config/operational-readiness.json` com os dez gates negados; CI verde não é produção e não concede direitos.

## Proibições operacionais

Nenhum acesso a dados de clientes, leads, tokens, domínio/DNS, Cloudflare, VPS, CRM operacional, fornecedores pagos ou deploy. Não mesclar este candidato sem administrador e revisões exigidas. O fluxo de contato continua apenas diagnóstico; intenção de consentimento não é consentimento legal verificado.