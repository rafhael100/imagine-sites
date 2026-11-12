# F1-249 — alvos de navegação e CTA com 44 px no HTML estático

## Falha de usabilidade corrigida

O HTML estático do motor já expõe navegação por âncoras e estilos `focus-visible`, mas a regra padrão `nav a { text-decoration:none; font-weight:600; }` não definia área mínima de toque. Na prática, links curtos podiam ter apenas a altura da linha de texto, especialmente na versão mobile com a navegação adicional `#solucoes`, `#etapas` e `#perguntas`.

## Código funcional

Em `StaticSiteRenderer` os links locais de navegação recebem `display:inline-flex`, alinhamento central e **`min-height:44px`** com padding vertical. O CTA local também declara altura mínima de 44 px, centralização vertical/horizontal e mantém a mesma `href=#contato`. Não cria novas rotas, botões de envio, scripts nem formulários.

## Evidência real

- `tests/f249-nav-touch-targets.php` cobre Onepage e Landing Page × três visuais, com seções estruturadas, validador `InertPreviewAudit`, QA de DOM e testes de CSS, âncoras e SHA-256;
- `ci/f239_demo_chromium_screenshots.py` mede **os retângulos de todos os links de navegação no Chromium**, exigindo altura ≥44 px, largura ≥24 px e ausência de conteúdo além da largura. Confere cinco links locais na Onepage completa e nenhum link de navegação inventado na Landing Page; mantém verificação do CTA e navegação por teclado;
- o mesmo workflow produz as 18 capturas completas em 1365/390/320 px e o manifesto assinala `navTouchTargetsVerified=true` quando aprovado.

As regras de tamanho são uma melhoria verificável de usabilidade, **não** uma certificação WCAG. A alteração cria **novos bytes HTML e novos SHA-256**, que exigem nova revisão interna; não reutiliza aceites de releases anteriores.

Todos os previews continuam fictícios e `noindex`. Não habilita cliente, Portal/CRM real, n8n, IA paga, Cloudflare, DNS, VPS ou publicação. Os dez gates permanecem BLOQUEADOS.
