# F1-245 — ensaio ponta a ponta do fluxo de produção de sites

Até F1-244, os seis exemplos HTML navegáveis estavam sendo renderizados por `StaticSiteRenderer` a partir de um `SiteBrief` construído manualmente no script de galeria. O motor contém um pipeline mais completo e restritivo para receber conteúdo estruturado do agente e produzir rascunho com evidências: `StructuredCopyCandidate` → `SyntheticStructuredDraftAssembly` → `ReleaseArtifact` → `SyntheticReleaseBundle` → `SyntheticProductionWorkflow::INTERNAL_REVIEW`. Esse pipeline não estava sendo exercitado pelo material de demonstração.

## Entrega funcional

`ci/f238_generate_demo_gallery.php` passa a gerar todos os seis arquivos por **`SyntheticStructuredDraftAssembly::build()`**, usando a fixture fictícia versionada `sites.ai-copy.v4`, o produto, a marca e o estilo de **fonte separada** e explícita, nunca definidos pelo texto do gerador.

O pipeline agora verifica, para cada produto e estilo:
- versão, campos, limites e texto puro do conteúdo estruturado (incluindo serviços, FAQ e etapas);
- renderização HTML, auditoria de marcação inerte e validação estrutural do DOM;
- pacote privado com manifesto digest SHA-256, `publishable=false` e identificadores de teste;
- máquina de estados que **apenas apresenta rascunho à revisão humana** e requer autoridade separada do Portal para qualquer aceite;
- que o agente não pode aprovar por conta própria, que a revisão obsoleta é recusada e que alteração de digest anula o aceite anterior.

O manifesto da galeria identifica `generationPath=synthetic_structured_copy_to_internal_review`, `draftState=internal_review`, `internalReviewRequired=true` e `portalAuthorityRequired=true`. Esses rótulos são **metadados de demonstração**, nunca prova de identidade real.

## Evidência automática

`ci/f245_verify_copy_review_gate.php` refaz a montagem por 6 combinações, compara SHA-256 dos artefatos aos HTMLs reais da galeria, valida o bundle e os estados de revisão e executa negativos para tentativa de incluir no conteúdo supostamente gerado por IA: `publishable`, `officialClientAcceptance`, `tenantRef`, `productCode`, `brand`, `stylePreset`, HTML executável, URL remota, versão obsoleta e digest errado. A CI das galerias HTML (PHP 8.3/8.4) e visual Chromium executa o teste para impedir regressões.

Isso **não conecta** nenhum serviço de IA, dados pessoais, Portal real, CRM, n8n, Cloudflare ou VPS. Não emite evento `ENTREGUE`, não publica e não altera cliente/contrato; os dez gates continuam `BLOCKED`. A rotina gera somente material fictício para revisar o que o motor executa.
