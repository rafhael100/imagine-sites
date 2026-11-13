# F1-71 — Composição determinística de rascunho privado a partir de copy estruturada

A classe `SyntheticStructuredDraftAssembly` conecta as peças existentes da linha F1-29/F1-39/F1-63: candidata `sites.ai-copy.v1` não confiável → `StructuredCopyCandidate` → `SiteBrief` → `StaticSiteRenderer` → auditorias HTML e estrutural → `ReleaseManifest`/`SyntheticReleaseBundle` → `SyntheticProductionWorkflow` até **internal_review**, sem aprovação de ninguém.

Parâmetros oficiais produto, marca, tema visual, referências de tenant/site/fluxo/release são recebidos **separadamente** da candidata da IA. A candidata não pode substituir esses parâmetros nem introduzir chaves extras, instruções HTML, URLs ou atributos de publicação. O pacote permanece `publishable=false` e `officialClientAcceptance=false`; a nova proposta é somente um rascunho para revisão interna. Não existe integração de identidade operacional, Portal, CRM, provedor de IA, custo ou endpoint HTTP.

Teste PHP para os 2 produtos × 3 estilos, seis artefatos determinísticos, hashes e manifestos idênticos em execuções repetidas, auditorias inertes, proibição de aprovação pelo agente, controle de release, inputs adulterados e copy editorial insuficiente (apenas alerta; nunca aprova). A evidência é técnica de laboratório, não homologação ou conformidade. Dependência do PR agregado #92. Nenhum merge ou publicação.
