# Onepage: candidato de handoff para revisão interna, sem autoridade

**19/11/2026.** Branch filha **isolada** da #544 (`8e291d42a75790a63dab66a17c6d1383ab0e5ff5`); complementa as #541–#544 sem tocar suas heads, banco de dados, main ou Portal.

`OnepageReviewHandoffCandidate::build()` recebe apenas o **par exato** do candidato de exportação do Portal, o plano selecionado e o editorial separado. Revalida o manifesto/bytes do briefing via `BriefSnapshotInput`, as referências exatas de tenant/site/revisão via `BriefRenderPlanInput`, e produz o artefato no renderer **real** de Sites via `BriefRenderProvenance`. Não aceita HTML/digest fornecido pelo chamador. O resultado é apenas um ponteiro JSON privado para **revisão interna pendente**, comprometido por SHA-256 com separação de domínio, sem texto de briefing, HTML ou autorização positiva. `assertMatches()` exige recomposição byte a byte com as mesmas entradas e evita reaproveitar a revisão sobre outra release.

O teste offline faz replay determinístico, recusa flags de autoridade do Portal, bytes/digests adulterados, tenant/site/snapshot/revisão divergentes, editorial trocado, `landing-page` fingindo `onepage`, referências de pedido mudadas e falsificação do ponteiro. CI PHP 8.3/8.4, sem PostgreSQL, rede operacional ou clientes reais. Os números e links só devem ser adicionados após observar os jobs da HEAD exata.

**Não resolvido / NÃO operacional:** autenticação da origem Portal, identidade/aceite do comprador, autorização financeira, prova independente de aprovação e revogação, CAS transacional, entrega privada, revisão editorial oficial, publicação técnica, DNS, rollback e aceite final. O SHA-256 prova *consistência local*, não assinatura, titularidade nem consentimento. Todos os dez gates seguem bloqueados. Não implantar nem conectar esta classe à rota de pedidos/publicação até homologação dos controles independentes.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.**
