# F1-68 — Revisão de conteúdo sem reaproveitar aprovação anterior

A etapa F1-54 já invalidava o aceite de **estilo**. F1-68 trata alteração de **conteúdo** de modo independente para brand, headline, description, callToAction e benefits. Cada alteração exige nova referência de release, HTML estático com SHA-256 distinto, geração de bundle inerte, retorno ao estado drafting e nova submissão/revisão interna antes que possa sequer existir nova proposta informativa ao Portal.

Limites: operação **somente em memória e dados sintéticos**; rótulos de papéis não são autenticação de pessoas. O serviço não consulta Portal, CRM ou direitos reais, não registra aceite expresso, não publica site e não habilita endpoint. Produto e estilo são preservados — mudanças desses parâmetros exigem outras operações explícitas.

Critérios de teste: 2 produtos × 3 temas × 5 campos, rejeição de no-op, mudança de produto/tema, versão antiga, papel de agente, referência de release reutilizada, HTML de origem adulterado mesmo com digest recomposto, orçamento exaurido e fluxo sem revisão. Novo candidato continua publishable=false e officialClientAcceptance=false, e a proposta antiga jamais coincide com o snapshot revisado. Os cenários rodam em PHP 8.3 e 8.4 no workflow existente.

Não é homologação operacional, prova de identidade, revisão de LGPD/AppSec ou autorização de produção. Dependência imediata do PR #88; PR em rascunho, sem merges, gastos, credenciais, publicação ou mudança da main.
