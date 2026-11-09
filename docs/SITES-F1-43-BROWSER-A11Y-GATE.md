# F1-43 — Ensaio de teclado e viewport no Chromium offline

Complementa os screenshots F1-42 com **teste do comportamento de navegador** sobre as quatro páginas fictícias exportadas pela F1-41, sem abrir serviço HTTP ou depender da Leaseweb.

O script `ci/f43_cdp.py` utiliza apenas Python stdlib e o Chrome/Chromium já instalado no runner. Inicializa Chrome headless com perfil efêmero, CDP no endereço de loopback e passa **os bytes exatos de cada HTML** para `Page.setDocumentContent` (sem arquivos `file://` ou rede pública). Mantém a CSP e o markup originais. Nada é enviado a outro serviço.

Para cada produto (Onepage/Landing Page) e viewport (1365×900 e 390×844), verifica via JavaScript do DevTools:
- Sem overflow horizontal (largura do documento e body até viewport + 2 px);
- Idioma `pt-BR`, uma `main`, um `h1`, dois grupos de navegação com `aria-current` unitário, links locais e visíveis;
- `iframe sandbox` nomeado, checklist estrutural de sete itens, ausência de formulários/scripts/botões;
- **Eventos reais da tecla Tab** com `Input.dispatchKeyEvent`; os quatro links iniciais devem manter a ordem, o iframe sandbox precisa ser acessível pelo teclado e o foco deve alcançar o link final após percorrer o conteúdo do iframe (até 16 eventos).

É um *smoke test*, não auditoria WCAG, prova de contraste, teclado completo (Shift+Tab/Escape), compatibilidade em Safari/Firefox ou conformidade de produção. Se detectar falha de foco/overflow, a execução deve reprovar e a correção permanecer em branch de laboratório.

Executa no workflow consolidado F1-41 junto ao F1-42, sem novas dependências, uploads, screenshots compartilhados ou acesso a dados reais. `main`, VPS, Cloudflare, Portal/CRM, DNS e readiness BLOCKED mantidos. Este PR depende de F1-42 (#60), que depende da cadeia #59→#56.
