# F1-173 — Teste real de responsividade das etapas no Chrome

A F1-169 introduziu a seção ordenada de etapas em Onepage e Landing Page; F1-170 permitiu revisá-la no Laravel. Esta etapa verifica a experiência visual da própria página no Chromium, para impedir quebra de layout especialmente em títulos sem espaços e telas de 320px.

Uma rotina PHP produz 12 HTMLs fictícios em diretório privado temporário: 2 produtos × 3 temas × 2 composições (somente etapas ou página completa com etapas, serviços e FAQ). O manifesto inclui SHA-256 de cada página e marcações syntheticOnly=true, publishable=false, officialClientAcceptance=false. As páginas passam antes pela mesma auditoria estrutural e empacotamento não publicável do motor.

O script Python ci/f173_process_cdp.py reaproveita o CDP nativo usado em F1-160, abre cada HTML diretamente em Chromium do runner GitHub Actions (sem servidor HTTP) e inspeciona telas de 1365, 390 e 320px: **36 verificações de viewport**. Confere três li de uma lista ol, h3, quebra de texto, largura sem overflow, visibilidade das seções, âncoras locais, CSS de contraste 4,5:1, lang pt-BR, CSP/noindex e ausência de formulários/scripts.

Este teste é evidência automatizada e não substitui revisão humana de usabilidade ou WCAG. Todas as páginas são sintéticas, internas e descartáveis; não há publicação, cliente, CRM, DNS, deploy ou novos custos. OP01/02/04 e dez gates operacionais permanecem bloqueados.
