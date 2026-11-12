# F1-159 — Seção de serviços em destaque e conteúdo estruturado v3

O motor de Onepage e Landing Page recebe uma seção opcional **Soluções em destaque** com até quatro cartões, cada um composto de título e descrição. A implementação é responsiva, usa marcação semântica com h2/h3 e mantém templates anteriores sem alterações quando o campo não existe.

O SiteBrief aceita highlights estruturados em lista; título e descrição têm limites de 100/300 bytes, texto UTF-8, nenhuma URL, caracteres de controle ou campos extras e títulos repetidos são rejeitados. O StaticSiteRenderer escapa o texto, usa CSS fixo sem assets externos, formulários ou scripts e preserva FAQ/estilos já existentes.

A origem de conteúdo sintético agora pode propor sites.ai-copy.v3, schema de sete campos: schemaVersion, headline, description, callToAction, benefits, faq e highlights. Não permite escolher produto, marca, empresa, contrato ou aprovação. Os contratos v1/v2 permanecem estritamente compatíveis e fechados; v3 exige pelo menos um cartão e até quatro. SyntheticStructuredDraftAssembly, SyntheticStyleRevisionPlan e SyntheticContentRevisionPlan preservam os highlights e obrigam novo digest e nova revisão para quaisquer alterações.

A matriz F1-159 executa PHP 8.3/8.4 para ambos os produtos e três paletas. Verifica v1/v2/v3, HTML inerte, QA semântico, reprodutibilidade, bundle não publicável, impossibilidade de aprovação por IA e tentativas de injeção. Não há API de IA, publicação, cliente real, PII ou integração CRM. OP01/OP02/OP04, revisão independente e dez gates de produção continuam bloqueados. PR draft, sem merge, deploy, VPS ou despesa.
