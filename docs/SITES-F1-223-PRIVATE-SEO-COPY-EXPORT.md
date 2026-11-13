# F1-223 — Pacote privado do texto SEO personalizado

O novo comando CLI de laboratório `sites:export-seo-copy-fixture {product} {preset} {seo} {social}` exporta exatamente as combinações de textos SEO já exibidas pelo revisor Laravel F1-222: Onepage/Landing Page × Classic/Modern/Warm × título e descrição automático/personalizado × metadados sociais desligados/ligados. São **24 versões de HTML** com hashes SHA-256 próprios.

O código reutiliza a composição canônica do Sites: `SyntheticSeoCopyFixtureV1` monta a origem, exige `SyntheticSeoCopyRevisionPlan` quando houver personalização e verifica que a aprovação da versão anterior não passa à nova. Os textos demonstrativos são fixos e fictícios, exatamente os mesmos exibidos no revisor F1-222. A versão nova volta ao estado `internal_review` pendente, com `publishable=false` e `officialClientAcceptance=false`. Nenhum texto livre, URL pública, logo, imagem externa, formulário ou script é aceito.

A exportação utiliza `SyntheticPrivateReviewPackageV1::fromPendingDraft` e `SyntheticPrivateDraftWriter::create`. Cada pacote contém quatro arquivos privados: index.html, internal-review-dossier.json, internal-review-draft.json e release-manifest.json. O writer aceita somente o padrão fechado `sites-f1-223-{produto}-{tema}-{modoSEO}-{social}`, com criação exclusiva, permissões privadas, rename atômico, verificação dos hashes e recusa de symlink e reexecução.

Execução exige PHP CLI, APP_ENV=testing, GITHUB_ACTIONS=true e `SITES_F223_SEO_EXPORT=1`. A suíte Laravel PHP 8.3/8.4 verifica os 24 pacotes, identidade exata dos bytes com o iframe do revisor F1-222, HTML `noindex`, OG/Twitter coerentes, conteúdo H1 preservado, dossiê pendente, recusa de argumentos inválidos, adulteração, symlink e sobrescrita. Também executa as regressões F1-222, F1-219 e F1-174.

A exportação **não é o Imagine SEO Engine**, não comunica com buscadores, não habilita indexação, publicação, aceite, CRM ou captação real. OP01/02/04 e os 10 gates continuam bloqueados; sem merge, produção, VPS, DNS, Cloudflare ou gastos externos.
