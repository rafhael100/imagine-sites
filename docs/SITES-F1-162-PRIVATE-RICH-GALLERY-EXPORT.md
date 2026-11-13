# F1-162 — Galeria privada da revisão rica (Onepage/Landing)

A F1-161 habilitou um caminho de revisão exclusivamente Laravel/CLI para visualizar FAQ e cartões de serviços. A F1-162 permite aos revisores gerar um **conjunto estático offline** dessas prévias — sem iniciar um servidor HTTP ou permitir acesso externo.

O comando de laboratório sites:export-rich-gallery-fixture só executa em PHP CLI, APP_ENV=testing, com quatro flags explícitas (SITES_SYNTHETIC_HTTP, SITES_SYNTHETIC_REVIEW_UI, SITES_SYNTHETIC_RICH_REVIEW e SITES_SYNTHETIC_RICH_EXPORT) e os modos legados de exportação desligados. Ele executa a **mesma rota interna F1-161** em processo, para 2 produtos × 2 dispositivos × 3 temas, valida headers de segurança, a presença dos dois componentes, o srcdoc e seu SHA-256. Converte os sete links de navegação em referências locais e inclui CSP/noindex para uso fora do Laravel.

Todos os 12 arquivos HTML e gallery-manifest.json recebem SHA-256 e tamanhos exatos, com syntheticOnly=true, publishable=false, officialClientAcceptance=false. Os treze arquivos são gravados em uma pasta privada temporária com permissão 0600, verificados, movidos por rename entre diretórios irmãos no mesmo sistema de arquivos e lidos novamente. Não substitui destino preexistente, não segue symlinks no destino, não recebe caminho arbitrário e não cria endpoints para publicação.

Testes Laravel em PHP 8.3/8.4 conferem links locais, hash da prévia, ausência de formulário/script, isolamento de fonte, exportação repetida recusada, flags desligadas, symlink e compatibilidade com a antiga galeria. **Não é homologação pública**, autenticação, aprovação do cliente, nem prova final de produção. OP01/OP02/OP04 e 10/10 gates continuam BLOCKED; sem merge dev/main, VPS, deploy, PII ou novas despesas.
