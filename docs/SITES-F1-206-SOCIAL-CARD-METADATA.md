# F1-206 — Metadados opcionais para prévia de compartilhamento social

O motor estático de Onepage e Landing Page passa a oferecer uma opção técnica para preparar metadados de apresentação de páginas no formato Open Graph/Twitter. Como o site ainda é uma prévia fictícia **não publicável**, o recurso não cria URL pública, imagem, conexão com redes sociais, indexação nem permissão de publicação.

`SiteBrief` recebe `socialPreviewMetadata=false` por padrão. Quando ativado, `StaticSiteRenderer` insere sete metadados de texto controlado: `og:type=website`, `og:locale=pt_BR`, título e descrição em `og:title`/`og:description`, `twitter:card=summary` e título/descrição correspondentes. Os textos são derivados exclusivamente do título e da descrição já validados e escapados no HTML, sem URLs, imagens, JavaScript ou entrada arbitrária de metadados. O `robots=noindex,nofollow` original e a CSP offline permanecem inalterados.

Com o recurso desligado, os HTMLs antigos permanecem **byte a byte idênticos**. O auditor `InertPreviewAudit` aceita apenas os cinco meta originais ou os doze resultantes, na ordem fixa, exigindo equivalência de título e descrição e rejeitando tags extras, duplicação de robots, redirect, `og:image` remoto ou divergências introduzidas no texto social.

`SyntheticStructuredDraftAssembly` recebe a escolha de maneira separada da cópia de IA. As revisões de tema, conteúdo, ordem, visual composto e edição textual preservam a decisão de metadados; nenhuma revisão exclusivamente de texto pode ativá-la ou desativá-la incidentalmente.

O teste de domínio F1-206 cobre 24 variações de produto, tema, ordem e paleta, determinismo, QA, hash SHA-256, escaping de aspas e `&`, preservação nas revisões e cinco adulterações de metadados. Executa na suíte PHP 8.3/8.4 existente. Ainda é necessário implementar uma opção explícita no revisor e uma etapa autenticada de SEO/publicação antes de produzir qualquer URL ou compartilhamento real.

PR draft, nenhuma alteração em main, sem cliente, CRM, domínio, VPS, gastos ou deploy. OP01/OP02/OP04 e os dez gates de produção continuam bloqueados.
