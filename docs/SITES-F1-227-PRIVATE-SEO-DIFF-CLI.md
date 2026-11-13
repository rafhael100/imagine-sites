# F1-227 — Comparar no terminal os dois pacotes SEO privados

A equipe Imagine pode comparar uma exportação SEO **automática** e uma **personalizada** já existentes, pelo comando `php artisan sites:diff-seo-copy-fixture {product} {preset} {social}`. O comando não exporta nem altera os arquivos; usa os mesmos pacotes de quatro documentos criados pela F1-223 e a verificação estrita F1-226.

Só aceita os 12 conjuntos fixos (2 produtos × 3 temas × cartão social off/on). Exige PHP CLI, APP_ENV=testing, GITHUB_ACTIONS=true e `SITES_F227_SEO_DIFF=1`. Não aceita URL, empresa, pasta, código arbitrário de cliente ou texto. Ambos os diretórios devem existir, sem symlinks, e cada um precisa passar pela verificação física `SyntheticPrivateDraftVerifier::verifyDirectory`. Os quatro arquivos são lidos e comparados com `SyntheticSeoCopyFixtureV1`, e a análise `SyntheticPrivateSeoCopyDiffV1::inspect` exige diferença somente no título e descrição SEO (com OG/Twitter coerentes), nunca no corpo, H1, CSS ou noindex.

A saída do terminal contém somente os dois hashes SHA-256, os nomes fixos `seoTitle`, `seoDescription` e indicação do cartão social. Não imprime título ou descrição, nem tenta indexar, salvar ou enviar para SEO Engine.

A matriz Laravel PHP8.3/8.4 executa 12 comparações, repete o relatório sem mutação de qualquer arquivo, nega pacote faltando, flag desligada, argumento inválido, arquivo alterado e symlink. O teste roda junto das regressões F1-223/F1-225 no workflow já existente, sem gerar outro job pesado.

A funcionalidade é de laboratório fictício, não SEO público, Google Search Console, imagine-seo-engine, publicação, contrato ou CRM. OP01/02/04 e dez gates de produção continuam bloqueados, sem merge, VPS, DNS, Cloudflare ou gastos.
