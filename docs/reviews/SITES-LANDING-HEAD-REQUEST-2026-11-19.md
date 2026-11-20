# Landing Page — requisição canônica de head (candidata, sem fonte oficial)

Data: 19/11/2026. Continuação isolada de [Sites #552](https://github.com/rafhael100/imagine-sites/pull/552), base SHA `2d8f9cd639dde4ba016220383b6dfcbccc103a2c`; compatibilidade negativa com o parser Onepage do [Portal #1003](https://github.com/rafhael100/imagine-portal/pull/1003), SHA `3ddd391d47317ce4e181ec9edca3a3427f2e8261`. Nenhuma branch anterior alterada.

## Código e proteção de produto

`PortalLandingPageHeadRequestCandidate::encode()` prepara somente o envelope de seletores: `schemaVersion=sites.landing-page-current-head.fetch.v1`, `productCode=landing_page` e referências `tenantRef/orderRef/siteRef`, em JSON canônico limitado a 1024 bytes. Trata-se de um primeiro contrato próprio para Landing Page, **não** de reutilização do fluxo Onepage. A nova fixture demonstra que o parser Onepage real no Portal **recusa** a requisição Landing Page; checks negativos preservam recusa de referências inválidas, replay byte-a-byte e ausência de campos de autoridade no wire.

## Segurança e limites

O contrato define apenas formato de pedido. Não existe parser Landing Page no Portal, leitor de briefing Landing Page autenticado, transporte, rota, aprovação, revisão, consentimento, publicação ou aceite. Nem o mesmo tenant declarado no JSON, nem o digest ou produto do envelope representam autorização. O contrato de publicação do Sites deve continuar separado da autoridade de pagamento e aprovação do Portal. Não alterar identidade visual Imagine por essa fixture.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.** Dez gates operacionais bloqueados. Sem dados reais, credenciais, VPS, produção, DNS, Cloudflare, Asaas ou gastos. Próximo elo: criar no Portal um parser específico Landing Page, depois fonte oficial versionada e revogável com identidade de serviço, CAS, isolamento de tenant e auditoria.
