## F1-255 — evidência visual de CTA secundário em navegador real

- Geração de 12 HTMLs fictícios isolados (2 produtos × 3 estilos × CTA off/on), pela cadeia real de draft e revisão interna.
- Captura full-page de 36 telas no Chrome em 1365/390/320 px, com SHA por imagem e manifesto privado.
- Validação de clique por teclado (Enter), área mínima de toque, cores do CTA, ausência de overflow e reflow de texto a 200%.
- Sem conteúdo de clientes, rede remota, formulários, publicação ou integração operacional.
- Artefatos temporários com retenção de três dias, eliminados do runner após o teste.

CI dedicado deve comprovar as 36 capturas no SHA exato. Dez gates de produção seguem bloqueados; este PR permanece draft sem deploy/merge.