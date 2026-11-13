# F1-284 — Teste real de teclado para “Pular para o conteúdo”

A operação de acessibilidade F1-282/F1-283 agora possui um teste no Chromium headless para validar comportamento que testes estáticos de HTML não conseguem comprovar completamente.

- Gera prévias privadas de Onepage e Landing Page utilizando o motor PHP real, com FAQ expansível e link de salto ativados.
- Confere SHA-256, `noindex`, manifesto privado de quatro arquivos, ausência de formulários/scripts e alvo `main#principal`.
- Abre cada página em três larguras (1365, 390 e 320 px), verificando que o link de salto é o primeiro link da página e que começa visualmente fora da tela.
- Envia evento real de tecla **Tab** ao navegador, verifica que o link recebe foco e se torna visível com dimensão mínima de 44 px.
- Envia **Enter** e verifica que a navegação permanece local e salta para o fragmento `#principal`, sem rolagem horizontal.
- O processo usa somente `about:blank` e DevTools no loopback; não há URL pública, screenshot persistido ou dado de cliente.

É evidência automática parcial, não certificação WCAG. Nenhum teste permite publicação, CRM, aceite do Portal ou alteração dos dez gates `BLOCKED`.