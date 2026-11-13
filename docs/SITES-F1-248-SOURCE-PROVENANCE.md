# F1-248 — proveniência auditável do rascunho, das fontes e do pacote HTML

## Problema resolvido

O candidato F1-246 já valida o digest SHA-256 dos seis arquivos HTML entre PHP 8.3 e 8.4, mas o manifesto não identificava a **versão exata dos bytes do conteúdo estruturado e do código do renderer** que gerou cada rascunho. Sem essa trilha, uma alteração na copy ou no template pode produzir uma visualização parecida que não corresponde ao conjunto editorial revisado.

## Implementação efetiva

`src/Production/SyntheticDraftProvenanceV1.php` representa evidência **somente sintética** de:
- digest SHA-256 dos bytes originais de `sites.ai-copy.v4` (com a origem exata do arquivo fictício);
- digest SHA-256 dos bytes do `StaticSiteRenderer.php`;
- produto e preset, tenant/site/release/workflow (todos identificadores fictícios);
- revisão do fluxo e estado `internal_review`;
- SHA-256 do HTML e do pacote privado content-addressed;
- campos imutáveis `syntheticOnly=true`, `internalReviewRequired=true`, `portalAuthorityRequired=true`, `officialClientAcceptance=false`, `publishable=false`.

Ao construir o registro, o componente **monta novamente** `StructuredCopyCandidate` a partir do JSON de origem e compara headline, descrição, CTA, benefícios, FAQ, serviços e etapas aos campos do briefing de fato renderizado. A revisão precisa estar pendente no estado correto, o QA deve passar, o manifesto do pacote deve coincidir com o HTML e todos os digests precisam ser íntegros.

`matchesDraft()` rejeita fontes alteradas ou pacote/rascunho divergente, independentemente de a aparência da página parecer igual. O gerador da galeria passa a incluir `sourceCopySha256`, `rendererSourceSha256`, `sourceProvenanceSha256`, `privateBundleSha256` e `workflowRevision` nos metadados verificáveis das seis páginas.

## Testes de regressão e compatibilidade

`ci/f248_verify_source_provenance.php` reproduz as seis variantes do pipeline F1-245, reconstitui o registro a partir dos mesmos bytes e compara com a galeria gerada. Os negativos tentam trocar a fonte do template, substituir o texto de origem, forjar `publishable=true` e trocar hashes/revisões. O `ci/f246_php_manifest_parity.py` também passa a exigir os novos campos na **comparação byte a byte** de manifestos PHP 8.3/8.4.

Os workflows de galeria HTML e capturas Chromium executam a verificação em CI isolado. Não há acesso a IA paga, cliente, segredo, domínio, VPS, Cloudflare, Portal ou CRM.

**Limite:** SHA-256 é um identificador de bytes, **não uma assinatura confiável**, uma prova de direitos autorais/licença, nem evidência de autenticação do Portal ou aceite do cliente. Não autoriza publicação, cadastro, indexação, entrega oficial ou alteração de qualquer um dos dez gates `BLOCKED`.
