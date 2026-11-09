# F1-40 — Matriz de navegação e segurança da prévia visual (CLI-only)

Esta etapa adiciona testes de contrato de navegação ao protótipo de UI F1-38, com o checklist estrutural F1-39 como pré-requisito. São testes **in-process do kernel Laravel**, não capturas em navegador Chrome nem homologação visual real.

Os testes cobrem as quatro combinações de produto (Onepage ou Landing Page) e viewport (desktop ou mobile), links de produto/dispositivo e âncora local, estados `aria-current`, landmark principal, título único, quadro de verificação estrutural com sete itens, iframe `sandbox` sem URL `src` e SHA-256 dos bytes exatos do `srcdoc` em cada combinação.

Proíbem conexões externas pela marcação HTML do painel, scripts, formulários, inputs, botões de ação, imagens externas e stylesheets externos. Exigem cabeçalhos de defesa, status não publicável, e recusas para métodos POST/PUT/PATCH/DELETE, produtos/dispositivos inválidos, parâmetros estruturados em array, IP remoto e endpoints comerciais.

Não executa JavaScript, navegador real, prova visual de responsividade, contraste, acessibilidade WCAG, autenticação ou envio ao Portal. Todos os exemplos são fictícios. Rota inexistente fora de PHP CLI + `APP_ENV=testing` + duas flags explícitas, conforme F1-38.

Todos os gates P0/OP03/OP05/RF-PEND/AppSec/LGPD continuam bloqueados. `main`, VPS Leaseweb, Cloudflare, DNS e serviços pagos permanecem intocados. PR depende de F1-39 (#57), que depende de F1-38 (#56).
