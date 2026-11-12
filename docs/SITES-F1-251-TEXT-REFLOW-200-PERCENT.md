# F1-251 — reflow do texto a 200% no Chromium real

## Problema coberto

As 18 prévias completas F1-244/F1-249 demonstram responsividade normal em 1365, 390 e 320 pixels, alvos de toque mínimos e navegação por teclado. Isso não prova que usuários que aumentam o tamanho do texto consigam ler e navegar sem rolagem horizontal. O risco cresce em páginas de serviço com títulos, FAQ, cards de solução e barras de navegação.

## Implementação de teste real

No script Chromium `ci/f239_demo_chromium_screenshots.py`, depois de produzir **cada uma das 18 capturas full-page originais** e verificar o Enter em link local, uma segunda verificação no próprio navegador altera somente o estilo do documento temporário com `document.documentElement.style.fontSize='200%'`.

O script lê a fonte raiz calculada antes/depois, exigindo crescimento mínimo de 1,9×; verifica que a largura de rolagem de `html` e `body` não exceda a viewport, limita a altura total para evitar layouts quebrados, verifica que cabeçalhos permaneçam visíveis e que **todos os links da navegação e o CTA** conservem tamanho mínimo e não ultrapassem o limite horizontal.

Os 18 resultados são registrados em `screenshot-manifest.json` com `textReflow200PercentVerified=true`. O CI exige essa evidência no artefato `imagine-sites-fullpage-responsive-fictional`, sem criar screenshot adicional de cliente ou modificar os arquivos estáticos publicados (que continuam inexistentes).

**Limites:** esta é uma prova de reflow via aumento de fonte raiz em Chromium, não uma simulação completa da função zoom do Safari/iPhone, leitor de tela, WCAG 1.4.4/1.4.10 ou auditoria independente de acessibilidade. Não há navegação a URL real, scripts executados no site, dados pessoais, Portal/CRM, infraestrutura de publicação ou custos. Dez gates de produção permanecem BLOQUEADOS.
