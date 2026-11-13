# F1-286 — Recuperação estrita de inicialização do Chrome na galeria CTA

O candidato F1-284 reportou uma única falha na suíte F1-255: a porta DevTools do Chrome não ficou pronta antes da primeira captura. Não houve falha documentada de layout, contraste ou integridade de páginas.

- Chromium usa `--disable-dev-shm-usage` no ambiente compartilhado do GitHub Actions, evitando dependência do espaço limitado de memória compartilhada.
- O workflow F1-255 registra a primeira tentativa, preserva o código de saída e só repete a execução **uma vez** se o erro tiver exatamente o identificador `F1-255 browser debug port unavailable` e o diretório de screenshots existir, não for symlink e estiver **vazio**.
- Essa condição garante que nenhuma página renderizada ou parcialmente inspecionada pode ocultar uma falha real de QA por nova tentativa.
- A repetição remove somente o diretório de resultados ainda vazio usando `rmdir` e inicia outro perfil local. Qualquer segunda falha interrompe o CI.
- Mantêm-se as 36 capturas reais, verificação 200% de texto, teclado, toque e integridade das doze páginas fictícias.

Não são permitidos download de navegador, publicação, DNS, Cloudflare, VPS, leads, clientes, custos ou mudança dos dez gates `BLOCKED`.