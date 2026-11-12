# F1-158 — FAQ estruturado do candidato de conteúdo IA até a revisão privada

A F1-157 adicionou FAQ opcional ao HTML estático, mas não havia caminho desde o contrato sintético de produção de texto até esse campo. A F1-158 introduz uma versão **opt-in** do contrato de cópia, sites.ai-copy.v2, com a lista FAQ, mantendo o sites.ai-copy.v1 estritamente fechado e inalterado.

- A versão v2 exige exatamente seis campos: schemaVersion, headline, description, callToAction, benefits e faq.
- FAQs devem ser lista de até seis elementos, cada elemento com exatamente question/answer; texto em UTF-8, sem tags, URLs, controles ou estrutura arbitrária. Aplicam-se também os limites do SiteBrief e do orçamento de bytes.
- O produto, a marca e o estilo permanecem obtidos de fontes definidas fora do candidato IA; o texto não tem campos de organização, autorização, publicação, consentimento ou credenciais.
- SyntheticStructuredDraftAssembly preserva a lista FAQ ao passar por SiteBrief, StaticSiteRenderer, validação semântica e pacote de revisão.
- O fluxo permanece em estado internal_review, com artefato não publicável, digest próprio e aprovação humana posterior obrigatória. Não há chamada a fornecedor de IA nem liberação automática.

O teste F1-158 roda PHP 8.3/8.4, com seis combinações de produtos e paletas, v1/v2 compatíveis, alterações maliciosas de esquema, injeção HTML/URLs, limites e negação de autoaprovação. Não cria endpoint nem executa coleta de leads reais. OP01, OP02 e OP04 e todos os gates de produção seguem bloqueados; sem merge, deploy, VPS ou despesas.
