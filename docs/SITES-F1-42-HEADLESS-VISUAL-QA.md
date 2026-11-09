# F1-42 — Prova visual por Chromium headless no CI (sem hospedagem)

A galeria HTML fictícia e privada da F1-41 pode ser aberta em **navegador de verdade** sem precisar de VPS. Este PR acrescenta um workflow de CI que verifica a existência do Chrome/Chromium já instalado no runner Ubuntu 24.04, instala apenas dependências do Laravel já travadas e executa a geração local do pacote de quatro prévias.

O navegador abre os arquivos `file://` em **viewport desktop e mobile**, gerando quatro screenshots PNG no diretório temporário do runner. O script `ci/f42_browser_smoke.py` valida a assinatura PNG, tamanho/dimensões, diferenciação das quatro imagens, integridade do manifesto SHA-256, CSP/noindex e ausência de referências de rede no HTML exportado.

**Privacidade e custo:** os PNGs não são incluídos no repositório, não são enviados como GitHub artifacts e são excluídos ao término do job. Não são baixados navegadores ou pacotes de sistema: se o runner não tiver Chrome/Chromium pré-instalado, o teste falha e registra o bloqueio para decisão posterior. Não utiliza SaaS de testes visuais, conta de fornecedor, API paga, Cloudflare, Portal/CRM ou dados de clientes. O job utiliza os minutos normais de GitHub Actions para repositório público, não representa promessa de execução gratuita em qualquer política futura.

**Limite probatório:** PNGs distintos comprovam inicialização e renderização básica pelo navegador, mas **não** demonstram ausência de overflow, contraste suficiente, navegação por teclado, conformidade WCAG nem comportamento idêntico em Safari e Firefox. A acessibilidade e a revisão visual humana continuam pendentes.

Apenas protótipo F1-38/39/40/41. Sem URL pública, aceite, publicação ou evento ENTREGUE. `main`, VPS Leaseweb, DNS, Cloudflare e readiness de produção permanecem bloqueados. Este PR depende do F1-41 (#59).
