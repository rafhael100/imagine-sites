# F1-52 — Seis prévias reais em Chromium para os estilos do Imagine Sites

A etapa F1-51 adiciona três estilos fixos; esta etapa valida **visualmente por navegador real** as seis combinações entre Onepage/Landing e Classic/Modern/Warm, sem usar endereço público e sem instalar navegadores novos.

O comando `APP_ENV=testing SITES_F52_GALLERY=1 php tests/f52-style-gallery.php` produz somente em diretório de temp uma galeria com seis arquivos HTML e `gallery-manifest.json`. São briefings fictícios, sem uploads, clientes, scripts, formulários ou links externos; cada variante é validada por `InertPreviewAudit` e `SyntheticReleaseBundle` com SHA-256 exato e `publishable=false`.

O workflow `sites-f1-52-style-gallery.yml` utiliza apenas Chrome/Chromium já instalado no runner e grava seis PNGs locais, conferindo assinatura/dimensões, manifesto e **seis capturas visualmente distintas** via hashes de PNG. Após testar, apaga HTML e PNG, sem publicar artifacts, arquivos de clientes, sites ou screenshots em serviços externos.

Este smoke test **não prova** conformidade WCAG, ausência de overflow em todos os tamanhos, bom design de marca, contraste em todos os estados ou segurança operacional. As paletas requerem revisão visual humana antes de serem oferecidas a clientes.

Gates de identidade/autorização, LGPD, AppSec, domínio de teste, VPS, Cloudflare e publicação continuam BLOCKED. `main`/ `dev` não foram alteradas. PR draft dependente de F1-51 (#72).
