# F1-225 — Conferência somente leitura de pacote SEO personalizado exportado

Após criar um pacote privado na F1-223, a equipe Imagine pode conferir seu título e descrição SEO, o estado de revisão e os hashes pelo terminal, sem exportá-lo novamente. O comando fixo é `php artisan sites:audit-seo-copy-fixture {product} {preset} {seo} {social}`, com os mesmos quatro seletores do exportador (Onepage/Landing × Classic/Modern/Warm × automático/personalizado × cartão social off/on).

O comando exige `PHP_SAPI=cli`, `APP_ENV=testing`, `GITHUB_ACTIONS=true` e `SITES_F225_SEO_AUDIT=1`; não recebe nome de cliente, caminho, URL, corpo de HTML nem dados pessoais. Não cria diretórios nem altera os quatro arquivos. Primeiro verifica limites físicos de acesso, symlinks, permissões e SHA dos arquivos por `SyntheticPrivateDraftVerifier::verifyDirectory`. Depois lê os quatro documentos e monta uma versão esperada sintética por `SyntheticSeoCopyFixtureV1`, usando o mesmo texto da revisão Laravel F1-222. O `SyntheticPrivateSeoSnippetReadbackV1` exige correspondência exata de HTML, H1, title, description, robots=noindex e opção OG/Twitter. Uma segunda verificação física reduz deriva acidental durante o readback (sem prometer instantâneo atômico contra filesystem hostil).

O relatório terminal só mostra release sintético, SHA-256, comprimentos de título/descrição e códigos de alerta editorial; não imprime o texto integral, chave, URL ou campo pessoal. Se falhar, retorna código de erro sem modificar o pacote nem declarar indexação.

A matriz PHP8.3/8.4 executa os testes do leitor junto do exportador F1-223, cobrindo 24 combinações, duas leituras idênticas sem escrita, ausência de pacote, flag desligada, parâmetros inválidos, HTML adulterado e diretório symlink. Também permanecem as regressões da revisão SEO F1-222 e do leitor anterior F1-219.

É leitura privada fictícia, não Google, Search Console, Imagine SEO Engine operacional, indexação, aprovação do cliente ou publicação. OP01/OP02/OP04 e dez gates continuam bloqueados, sem merge, deploy, VPS, DNS, Cloudflare nem gastos.
