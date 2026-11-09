# STATUS-ATUAL — Imagine Sites Engine

**Data de referência:** 2026-11-08.
**Recorte:** F1-01 — domínio puro sintético, branch feat/sites-f1-01-engine-domain-contracts-2026-11-08.
**Ambiente:** sem deploy.

## Conteúdo
- Estrutura independente, AGENTS e documentação de autoridades, contratos, segurança e migração.
- Código PHP puro: release/manifesto, política de elegibilidade lógica para publicar, verificador consultivo de entrega, janela T0+72h/432h e ledger **só em memória**.
- CI de sintaxe/testes sintéticos sem interação com fornecedores.
- **Local em PHP 8.4.24:** 32 checks sintéticos passaram. CI GitHub: a verificar no workflow do PR; não afirmar PASS antecipadamente.

## O que NÃO existe
Não há Laravel operacional, PostgreSQL, RLS, autenticação serviço-serviço, assinatura de aceite, HTTP, APIs de leads, captação redundante, publisher Cloudflare, n8n, AI Agents, integração CRM, backup, domínios ou dados reais. PortalEvidence simula flags, não é prova autêntica. O ledger não assegura idempotência persistente.

## Gates
P0-DOC01..14 abertos/parciais; P0-DOC03 e RF-PEND-01 ABERTOS; RF-PEND-04 NÃO HOMOLOGADA; AUT-P01..10 B normativas, RV-CT01/02 B diretrizes com contratos abertos, RV-CT03..08 pendentes; AGI-11-RB01 DESABILITADO.

## Próximos recortes
F1-02 adaptadores fail-closed com contratos candidatos e testes adversariais; F1-03 fundação Laravel/PostgreSQL isolada quando gates correspondentes permitirem; Portal deve manter sua main sem alterações neste recorte.

Plano de referência: https://github.com/rafhael100/imagine-portal/pull/94
