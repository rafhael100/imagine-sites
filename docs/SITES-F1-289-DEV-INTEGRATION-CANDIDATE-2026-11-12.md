# Imagine Sites — candidato integrado F1-289 (apex, redirect e SEO) para revisão em dev

**12/11/2026 — PR DRAFT, sem merge, deploy ou produção.**

## Base e encadeamento

- dev fixa: 71b8ffe51139d0e70ed32591b07f295fb7bf5fe3.
- PR #349: integração avançada A11Y/CRM com ADR-015 e F1-287, SHA 4d7a7982bed62b622d519ab316d55ff0ddce5419; **117/117 CI green**, inventário completo F1-240 de 753 caminhos e 213 sensíveis, seis pacotes F1-241.
- PR #350: F1-288 redirect www → apex estritamente candidato, sem HTTP 30x ou tráfego; valida métodos, caminhos e descarte de query. Check independente em PHP 8.3/8.4.
- PR #352: F1-289 sitemap/URLs canônicas de revisão privada com indexable=false e mayServe=false, sem modificar HTML noindex ou APIs reais.
- ADR-015 aprovado como política editorial apex-first para novos sites; preservar site legado já indexado até migração SEO específica e aprovada.

## Auditoria deste HEAD (indispensável antes de revisão humana)

1. Aguardar TODOS os workflows PHP, Laravel, PostgreSQL 18, Chrome, Docker, segurança e integração no SHA exato deste candidato.
2. Executar F1-240 de git diff completo sem truncar a 300 arquivos e F1-241 seis pacotes SHA-256; não substituir evidências por CI dos PRs anteriores.
3. Solicitar revisão AppSec, DBA, Portal/CRM, frontend/acessibilidade, SEO/canonical/redirect e infraestrutura.
4. Confirmar proteção e regra de revisores independentes em dev/main, issue #281; sem merge até conclusão.
5. Conservar config/operational-readiness.json status BLOCKED, non_operational true e dez gates false. Aprovação de CI não modifica estes estados.

## Dependências operacionais não resolvidas

- Autenticidade serviço-serviço Portal e revogação, RLS/backup restaurável em banco real, aprovação expressa do titular, geração real de IA com orçamento e licenças, publicação Cloudflare verificada independentemente, dupla captação física e confirmação CRM, domínio/TLS e critérios LGPD/segurança.
- Este candidato não acessa credenciais, VPS, Cloudflare, DNS, domínios de cliente ou dados reais. Política futura pode ser aplicada somente após staging, GSC, testes de redirects/canonicals por URL, restore/rollback e go-live autorizado.

**Estado: CODE REVIEW CANDIDATE / NO-GO PRODUÇÃO.**
