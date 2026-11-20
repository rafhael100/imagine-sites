# Landing Page — contrato privado de bytes do briefing aprovado (candidato)

Encadeamento: Sites #541/#542 → Portal #1010 → Sites #555 → Portal #1012 → esta PR.

O decoder Sites consume o envelope de `LandingPagePrivateBriefEnvelopeCandidate` do Portal
pinado em HEAD imutável. Exige expectativa **independente** de tenant, pedido,
site, contrato, revisão, versão atual do conteúdo, aprovação e epoch. A igualdade
canônica do JSON, SHA-256 dos bytes originais e produto `landing_page` impedem
trocar o briefing aprovado, mascarar como Onepage, adicionar privilégios ou
reutilizar versões anteriores. O DTO Sites impede serialização acidental de
briefings privados. Nenhum dado real vai a logs ou repositório público.

**NO-GO:** a referência de aprovação nesta prova é sintética. Faltam origem
transacional oficial de aprovação/revogação/ator do Portal, mTLS autenticado,
endpoint privado, autorização por tenant, perfil/editorial e renderizador próprios
de Landing Page, revisão independente, publicação técnica e aceite de cliente.
A equivalência de bytes é consistência, **não prova de autorização**, entrega
ou segurança de produção. O atual `BriefSnapshotInput` é exclusivamente
Onepage e não foi reutilizado aqui.

Sem merge/deploy ou alteração de main, VPS, credenciais, produção, DNS,
Cloudflare, Asaas e fornecedores. Os dez gates permanecem BLOCKED.
