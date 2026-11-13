# F1-266 — recuperação estrita do bootstrap de Chromium em CI

O candidato integrado F1-265 (#309) executou 97/98 workflows: a única falha ocorreu na inicialização da porta CDP do Chrome, antes de carregar qualquer HTML. Os testes PHP, Worker, Laravel e os inventários F1-240/F1-241 passaram.

Correções isoladas: o processo headless usa `--disable-dev-shm-usage` para evitar dependência de memória compartilhada limitada no runner. No workflow F1-244, a execução só ganha **uma repetição** quando o erro exato é `F1-239 Chromium DevTools port unavailable`, o diretório de imagens existe mas está **vazio** e não é symlink; remove somente esse diretório vazio com `rmdir` e cria outro perfil temporário fresco. Qualquer falha de desenho, contraste, navegação, timeout após iniciar, hash, auditoria de fonte, arquivo já gravado ou exceção não identificada interrompe a execução sem retry.

Os mesmos 18 screenshots, 3 dimensões, testes reais de teclado, 200% de texto e a integridade do manifesto continuam obrigatórios. Nenhum download de Chrome, publicação, cliente, lead, DNS, Cloudflare, VPS ou custo está autorizado. Dez gates permanecem BLOCKED.

Monitorar os testes do PR e repetir depois na branch de integração, sem merge automático.