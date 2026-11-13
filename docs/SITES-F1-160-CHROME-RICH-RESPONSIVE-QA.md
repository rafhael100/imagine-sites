# F1-160 — Prévia rica validada de verdade no Chromium a 320/390/1365 pixels

O mecanismo ganhou FAQ (F1-157/158) e cartões responsivos de soluções (F1-159). O teste de template PHP garante escape e semântica, mas não mostra se uma pergunta sem espaços ou um título de serviço muito longo sai da tela em um navegador móvel.

A F1-160 acrescenta CSS restrito aos elementos novos com overflow-wrap: anywhere nos títulos h3 de FAQ e highlights. Não modifica os artefatos sem essas seções.

A suíte f160-private-rich-gallery.php gera 18 HTMLs fictícios não publicáveis e um manifesto de hashes SHA-256: 2 produtos × 3 paletas × 3 variantes (FAQ, highlights, ambas), incluindo títulos artificiais longos. O Python f160_rich_cdp.py reaproveita o CDP sem dependências externas já testado na F1-43 e abre, em Chromium **já presente no runner**, o HTML em 320 px, 390 px e 1365 px. São 54 análises de viewport verificando largura, ausência de overflow horizontal, limites dos cards/títulos, headings, ancoragem local, nenhuma tag ativa, metadados noindex/CSP e contraste de texto estático >= 4,5:1. Não usa screenshots persistentes, serviços pagos ou instalação de browser.

Nenhuma renderização de navegador, WCAG automático ou contraste parcial equivale a auditoria humana completa ou autorização de publicar um site. Sem dados pessoais, cliente, domínio, CRM ou rede pública. Testes e artefatos ficam apenas no runner temporário, eliminados em finally. OP01/OP02/OP04, 10 gates operacionais e revisão independente permanecem pendentes.
