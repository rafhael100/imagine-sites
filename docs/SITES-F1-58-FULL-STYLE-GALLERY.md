# F1-58 — Galeria visual completa: 12 páginas inertes de laboratório

O Imagine Sites já possuía a revisão visual F1-38..44 e os estilos Classic/Modern/Warm (F1-51/F1-57). O exportador de legado F1-41 continua deliberadamente em quatro páginas Classic. Esta etapa oferece **outro comando, independente**, que gera 2 produtos × 2 visualizações × 3 presets = **12 páginas locais**:

`APP_ENV=testing SITES_SYNTHETIC_HTTP=1 SITES_SYNTHETIC_REVIEW_UI=1 SITES_SYNTHETIC_STYLE_EXPORT=1 php artisan sites:export-style-gallery-fixture`

Cada página é gerada pela própria rota Laravel de prévia interna, em processo CLI, sem iniciar um servidor HTTP. Os sete links de navegação (dois produtos, duas visualizações, três temas) são convertidos para arquivos locais. O HTML preserva seu iframe sandbox e SHA-256 original, acrescenta noindex e CSP; o manifesto `gallery-manifest.json` contém sha256 do HTML externo e `artifactDigest` do HTML interno, todos derivados de bytes exatos. `publishable=false`, `officialClientAcceptance=false`, sem formulários, scripts, URLs externas, clientes ou autorização comercial.

O destino de arquivos é exclusivamente `storage/app/private/sites-f1-58-style-gallery`, recém-criado, com modo 0700 e arquivos 0600; symlinks e tentativas de sobrescrever são recusados. O comando só existe para fixture em CLI/testing com flags explícitas e não é executável sob HTTP de produção.

O CI usa Laravel PHP 8.3/8.4 e Chrom(e|ium) **já presente** no runner para capturar as doze telas em desktop/mobile. Python verifica manifesto, hash, navegação offline, texto de segurança e doze PNGs distintos, **sem subir as imagens como artifacts**, removendo-as antes de finalizar.

**Limitações:** o teste de screenshot não certifica WCAG/UX em todas as resoluções, identidade de marca ou fluxo real com aceite. O cliente não possui editor self-service; a Imagine mantém a produção. F1-54 exige nova revisão interna sempre que mudar o tema de uma release aprovada. Nada é publicado, pago ou transferido a CRM/Portal real. Gates P0/RF-PEND/RV-CT/AppSec/LGPD, autorização, VPS, DNS e readiness continuam BLOCKED.
