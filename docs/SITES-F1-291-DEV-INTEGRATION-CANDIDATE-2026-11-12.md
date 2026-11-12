# Imagine Sites — F1-291, candidato integral para integração em dev

**Data:** 12/11/2026 | **Estado:** PR DRAFT, NO-GO produção | **Base dev fixada:** `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`

## Consolidação exata

- PR #353: F1-287 (origens apex sem `www`), F1-288 (redirecionamento somente candidato), F1-289 (URL canônica e sitemap privado). SHA `27bfd56b4b2ab85f5c188cea04667bc32e1f75f3`, **119/119 workflows GitHub Actions success**, **762 arquivos / 215 sensíveis** na auditoria Git F1-240/F1-241.
- PR #354: F1-290 — recupera interface Imagine Portal Node original no shell privado de revisão Sites, tokens aprovados laranja/grafite, logo original de objeto Git `678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77`, ajustes de CSP e testes de fronteira. Após correções de contraste e teste de logotipo local, **30/30 workflows success** no SHA `342c388af6ca59291763f186267a7f891e5698ba`.
- PR #355: F1-291 — testes de interface via Chromium CDP, quatro telas (1365/820/390/320), logo real, foco, noindex, sandbox, responsividade; artefato temporário de screenshots gerados com dados fictícios em CI, sem deploy ou servidor HTTP.
- Logo original, tokens e CSS são usados somente no painel interno; Onepages/Landing Pages de clientes seguem paletas próprias, com bytes de HTML renderizado e digests preservados.

## Antes de qualquer merge em dev

1. Rodar **todos** os workflows e inventários F1-240/F1-241 no HEAD exato deste novo candidato, sem reaproveitar os sucessos históricos de PRs encadeados.
2. Gerar seis pacotes de revisão independentes e exigir análise humana dos caminhos sensíveis: AppSec, banco/RLS, contratos Portal/CRM, frontend/UX, segurança de CSP/logo, DNS/SEO e CI.
3. Configurar branch protection/rulesets exigindo revisão independente em `dev/main` (issue #281, `protected:false` na última consulta). Não realizar merge unilateral.
4. Confirmar `config/operational-readiness.json` **BLOCKED**, `non_operational=true` e dez gates false; a alteração de design não autoriza acesso de cliente, publicação, backup real ou CRM.
5. Antes do go-live em ambiente externo: homologar Leaseweb/Cloudflare, credenciais isoladas, contratos reais do Portal e evidências de aprovação, RLS e restauração, captação dupla/CRM e observação pública independente, conforme #33–#38.

## Sem ações externas

Nenhum DNS, TLS, Cloudflare, VPS, CRM real, consumidor, dados privados, chave de produção, instalação paga, merge ou deploy foram utilizados. Evidências de screenshots do CI expiram e não substituem inspeção humana e testes com tecnologias assistivas.

**Gate final:** CODE REVIEW CANDIDATE — NO-GO para publicação.
