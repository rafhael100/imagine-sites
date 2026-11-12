# F1-219 — Auditoria SEO somente leitura de pacote privado no terminal

A equipe de desenvolvimento pode agora verificar o título, meta description, H1, `noindex`, alertas de tamanho e identidade SHA-256 de uma página **já exportada** sem abrir uma interface web ou acessar um buscador. O novo comando Laravel de laboratório é:

`php artisan sites:inspect-seo-fixture {product} {preset} {order} {palette} {navigation} {social}`

O comando só executa em `PHP_SAPI=cli`, `APP_ENV=testing`, `GITHUB_ACTIONS=true` e `SITES_F219_SEO_READBACK=1`. Os seis seletores são validados contra o catálogo finito F1-204, com social apenas on/off. Não aceita URL, caminho de arquivo, empresa real, credencial, conteúdo livre ou nome de lead. **Não cria arquivos:** exige a exportação privada F1-205 previamente existente.

O leitor verifica permissões e links simbólicos do diretório com `SyntheticPrivateDraftVerifier::verifyDirectory`, lê exatamente os quatro arquivos canônicos e confere os bytes novamente via `SyntheticVisualReviewExportParityV1::inspect`. A paridade exige mesma identidade de release, HTML SHA-256, revisão pendente, cartão social coerente se ativado, H1/título/descrição SEO e ausência de privilégios para indexar, publicar ou aceitar cliente. Em caso de erro retorna código de falha sem exibir dados do arquivo.

A saída de sucesso contém apenas release de laboratório, hash, contagem de caracteres e códigos de alertas editoriais. Não imprime o texto integral, URLs nem dados pessoais. O teste `SyntheticPrivateSeoCliTest`, executado na suíte Laravel PHP8.3/8.4, cobre quatro modelos exportados, repetição sem mutações, ausência de exportação, flag desligada, parâmetros inválidos, troca de robots e diretório symlink.

A conferência é de **laboratório** e não é o Imagine SEO Engine, Google Search Console, indexação pública, publicação, CRM ou aceite de cliente. OP01/02/04 e dez gates de produção continuam bloqueados. Nenhuma mudança em main, VPS, DNS, Cloudflare ou gastos externos.
