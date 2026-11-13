# F1-224 — Mesma origem de SEO para a tela e o pacote exportado

A F1-222 tinha sua própria montagem de conteúdo SEO fictício no controlador Laravel; a F1-223 criou um gerador canônico para persistir os mesmos metadados em quatro arquivos privados. Sem refatoração, a tela e o exportador poderiam divergir em texto, H1, metadados sociais, hash ou regras de revisão.

A rota privada `/_lab/sites/v1/seo-copy-review` agora chama `SyntheticSeoCopyFixtureV1::build` com o escopo de fixture `f222`, preservando os identificadores de release e os bytes antigos da tela. O exportador F1-223 usa a mesma função com escopo `f223`. Ambos montam a origem, executam o plano `SyntheticSeoCopyRevisionPlan` quando existem metadados personalizados, reiniciam a revisão e exigem `SyntheticPrivateReviewPackageV1` + leitura independente `SyntheticPrivateSeoSnippetReadbackV1`.

O teste de domínio F1-224 compara as 24 combinações de produto, tema, modo SEO e cartão social **entre os dois escopos**: HTML e SHA-256 idênticos, manifests e dossiês com releases separados, `pending`, `publishable=false`, `officialClientAcceptance=false`, noindex, nenhuma URL externa/OG:image/formulário/script, H1 imutável e texto SEO personalizado somente em head. Também nega parâmetros inválidos, escopos não autorizados e campos SEO inseridos pela IA.

O teste está registrado na suíte principal Sites Engine para PHP8.3/8.4. O Laravel mantém todas as regressões da tela F1-222 e da exportação F1-223. Em nenhum caso o resultado concede publicação, indexação, autenticação do cliente ou chamada ao Imagine SEO Engine/CRM. OP01/02/04 e dez gates de produção continuam bloqueados, sem merge, VPS, DNS ou novos custos.
