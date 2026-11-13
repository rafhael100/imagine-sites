# F1-244 — verificação visual COMPLETA, inclusive conteúdo abaixo da primeira tela

A entrega anterior F1-239 gerava 12 screenshots de apenas **uma viewport** (1365×900 desktop, 390×844 celular). Com o conteúdo enriquecido F1-243, isso ocultava as seções de serviços, etapas e FAQ, porque ficam abaixo da área visível. O controle visual não demonstrava os recursos que a galeria anunciava.

## Mudança funcional

O script `ci/f239_demo_chromium_screenshots.py` agora renderiza as seis páginas geradas por `StaticSiteRenderer` em **três larguras reais do Chromium**, totalizando **18 imagens PNG completas**:

| Perfil | Largura testada | Altura inicial |
| --- | ---: | ---: |
| Desktop | 1365 px | 900 px |
| Celular | 390 px | 844 px |
| Celular estreito | 320 px | 740 px |

Após renderizar cada documento inerte offline em `Page.setDocumentContent`, calcula-se a altura total dos elementos com o DOM real; `Page.captureScreenshot` usa `clip` limitada à **altura completa** da página. O script rejeita páginas com altura não superior à primeira viewport ou acima de 12.000 pixels, garantindo que o arquivo cubra seções abaixo da dobra sem abrir páginas externas.

Além da captura visual, o Chromium agora confere:
- nenhuma barra horizontal inesperada nas três larguras;
- **todos os links locais com destino válido**, sem destinos externos e nomes vazios;
- CTA com área clicável mínima de 44 × 44 pixels, inteiramente dentro da largura visível;
- presença de serviços, processo e FAQ no DOM em todos os dispositivos;
- nenhum script, formulário ou iframe e meta `noindex, nofollow`;
- **uso real da tecla Enter** para ativar o primeiro link local, confirmando navegação por teclado;
- integridade do PNG, dimensões reais de página completa, hash SHA-256 único e inventário completo de 18 imagens.

O manifesto de screenshots passa a `imagine-sites.synthetic-demo-screenshots.v2`, preserva a referência SHA-256 do manifesto de origem, e inclui `fullPage=true`, viewport e confirmação do teste de teclado.

## Resultado disponível no GitHub Actions

No workflow **Sites F1-244 full-page responsive Chrome screenshots 1365/390/320 (fictional)**, o artefato temporário chama-se `imagine-sites-fullpage-responsive-fictional`, com retenção de sete dias. Inclui 18 PNGs e o manifesto de verificação, sem dados de clientes.

**Limites:** isso é QA automatizado de amostras estáticas fictícias, não certificado WCAG, revisão de texto ou publicação. As páginas não incluem formulários nem scripts, não chegam à Cloudflare/CRM/Portal, e os dez gates operacionais permanecem bloqueados. Não existe acesso ou deploy na VPS.
