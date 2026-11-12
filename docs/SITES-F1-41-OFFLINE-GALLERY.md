# F1-41 — Galeria visual HTML totalmente offline (Laravel em laboratório)

O novo comando `php artisan sites:export-review-gallery-fixture` gera **quatro páginas HTML independentes e um manifesto SHA-256** apenas no espaço temporário privado de um runner, sem disponibilizar arquivos na internet.

O comando exige simultaneamente PHP CLI, `APP_ENV=testing`, `SITES_SYNTHETIC_HTTP=1`, `SITES_SYNTHETIC_REVIEW_UI=1` e `SITES_SYNTHETIC_EXPORT=1`. O consumidor é a rota interna F1-38+F1-39 validada por F1-40; o comando faz uma requisição Laravel **dentro do próprio processo**, sem criar serviço web nem chamadas de rede.

A galeria é formada por `onepage-desktop.html`, `onepage-mobile.html`, `landing_page-desktop.html` e `landing_page-mobile.html`. Os links de navegação do laboratório são reescritos para arquivos locais equivalentes. O HTML permanece ficcional e contém iframe sandbox com a prévia de bytes exatos, CSP adicional via meta tag, noindex e indicação de que aceite oficial e publicação NÃO aconteceram.

O manifesto `gallery-manifest.json` informa, para cada arquivo, SHA-256 e tamanho, sempre com `syntheticOnly=true`, `publishable=false` e `officialClientAcceptance=false`. O diretório não pode existir antes; symlinks e tentativas de sobrescrita são recusados. Arquivos são criados com permissões restritas. A CI cria, valida e apaga a galeria no runner; **nenhum artifact é publicado ou enviado como anexo do GitHub**.

A galeria poderá ser aberta localmente em um navegador após execução autorizada em um ambiente isolado. Não há ainda captura automatizada em Chromium/Firefox, certificação de acessibilidade, aceite do cliente, frontend de produção ou servidor web da Imagine. Mesmo sendo uma demonstração local, não substitui teste de navegador real e revisão humana.

`main`, VPS Leaseweb, Cloudflare, Portal/CRM, DNS e pagamentos permanecem intocados. Gates OP01/OP03/OP05, P0/RF-PEND, AppSec/LGPD e readiness BLOCKED.
