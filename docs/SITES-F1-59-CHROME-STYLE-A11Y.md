# F1-59 — Navegador real verifica 12 layouts, links locais e contraste em superfícies sólidas

O screenshot smoke F1-58 apenas conferia que cada uma das doze páginas diferia em pixels, tamanho e identidade de hash. Este passo inspeciona o **HTML interno do próprio site** (não apenas o contêiner de revisão) com Chromium local CDP, sem driver externo ou servidor HTTP.

Para cada produto × viewport × preset, recupera `srcdoc` da prévia, confere SHA-256 exato, carrega o HTML inerte via `Page.setDocumentContent` no navegador e observa as métricas reais: largura, ausência de overflow horizontal, idioma, viewport, H1/main únicos, IDs dos links internos, ausência de tags executáveis, produto correto e preset esperado.

Extrai cores computadas e background sólido de textos representativos (identidade no cabeçalho, navegação, benefícios, CTA, contato, rodapé) e exige razão 4,5:1 com fórmula sRGB do F1-44. **Não mede** de forma completa os textos sobre gradientes, opacidade efetiva, pseudo-elementos, componentes ocultos, visibilidade do foco ou todos os estados. Não equivale a WCAG ou aprovação humana. Um score 4,5 nos elementos testados é somente evidência parcial.

O Chrome usa WebSocket localhost, perfis temporários e HTML ficcional. Sem Internet, serviços externos, capturas publicadas, clientes, formulários, geração de leads ou deploy. Reaproveita galeria F1-58; PR draft sobre #79. Identidade real, contratos, segurança, LGPD, aprovação expressa do cliente e publicação continuam BLOCKED.
