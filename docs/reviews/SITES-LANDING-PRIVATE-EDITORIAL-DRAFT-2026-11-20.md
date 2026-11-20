# Sites — rascunho editorial privado da Landing Page (candidato)

Cadeia isolada: Sites #541/#542 → #560 (base fixa `3b7274d8fd2b4ba1884ef2c8a8d28495bf9d1ce0`) → este draft; Portal #1019 (código fixado em `4a5e1e06376188f85c90bee3d10056ab3ce2302a`).

## Entrega implementada

`PortalLandingPageEditorialDraftCandidate` agora transforma **somente** bytes privados recebidos de `PortalLandingPageApprovedBriefReadbackCandidate` em HTML determinístico e inerte, dentro de perfil próprio de Landing Page. O conteúdo é limitado e validado contra `portal.landing-content.candidate.v1`: `schemaVersion`, `headline`, `subheadline`, `benefits` (1 a 5 textos) e `ctaLabel`. Chaves extras, chaves duplicadas, controles, limites excessivos e esquemas de outro produto são recusados. Todo texto é escapado, sem JavaScript, formulários, links, ativos remotos ou botões operantes. A aparência fixa utiliza a referência Imagine `#F9941D`, bandeira de prévia interna, metadados `noindex` e CTA não interativo.

O retorno contém os bytes do artefato **somente para revisão privada**, SHA-256 do HTML e um ponteiro de revisão com hash separado por domínio. O ponteiro é vinculado a empresa, pedido, contrato, site, versão e epoch de aprovação e digests da fonte e do artefato; não contém os bytes privados do briefing. Releitura do head depois da geração recusa alterações observadas. O teste usa o **encoder real candidato do Portal** pinado, fontes sintéticas e ataques de injeção, replays, alterações de versão/revogação e cross-tenant. O novo template **não usa** o renderer Onepage nem desbloqueia publicação.

## Limites e gates

Não há fonte oficial de atores/aprovações, integração com pedidos pagos, credencial mTLS homologada, persistência de revisão/artefato, revisão/consentimento do cliente, HTTP operacional ou publicação. Releituras não são transação distribuída; revogação posterior é possível. HTML/render não deve ser servido a usuários sem autenticação, autorização e armazenamento próprio aprovados. Os dez gates permanecem **BLOCKED**. Nenhum merge/deploy, VPS, produção, Cloudflare/DNS, Asaas, credencial, custo ou pagamento.

CI draft-only em PHP 8.3/8.4; resultados e SHAs devem ser registrados apenas após confirmação do GitHub Actions. Próxima dependência: ledger oficial de briefing e ator do Portal com grants/RLS homologados, seguido de custódia transacional do artefato e revisão vinculada ao source digest.

## Evidência verificada

Na HEAD `1fa38dd855aaa7260d59f23c67238b8d3643ecf6`, o [workflow específico 44709743707](https://github.com/rafhael100/imagine-sites/actions/runs/44709743707) terminou com **2/2 jobs SUCCESS**, em PHP 8.3 e 8.4. Logs dos jobs registram, em cada versão, `SITES LANDING PRIVATE EDITORIAL DRAFT PASS: 34 checks` e `SITES LANDING PRIVATE CURRENT READBACK PASS: 42 checks`, além de lint PHP aprovado. A CI da HEAD com este documento será checada separadamente; não presumir sucesso futuro.
