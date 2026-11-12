# F1-83 — Primeira execução real do Docker Compose de laboratório no GitHub Actions

A F1-73 preparou um Dockerfile e Compose sem portas públicas, e F1-74 apenas validou estaticamente as regras do build context e a configuração renderizada. A F1-83 acrescenta um workflow de CI que efetivamente **constrói a imagem PHP 8.4** e executa os testes Laravel no serviço de laboratório conectado ao PostgreSQL 18 por **rede interna**, com flags sintéticas padrão desligadas.

O teste compara a identidade não privilegiada UID/GID 10001 da imagem, exige ausência de `EXPOSE`, sobe somente a pilha `imagine-sites-lab` (profile opt-in), executa `docker compose run --rm sites-lab-tests`, confirma que o serviço de banco não publica portas no host e destrói os contêineres e volume de teste no passo `if: always()`. Credencial PostgreSQL do runner é um texto público **exclusivamente fictício**, não dado operacional; nenhum arquivo de configuração local ou segredo é incorporado ao contexto de build.

**Limites:** a execução ocorre em uma VM descartável do GitHub Actions, não na VPS Leaseweb. O resultado não prova segurança do host real, disponibilidade de backups, acesso Cloudflare, IAM, publicação, aprovação do cliente, captura CRM ou observabilidade. A imagem é de testes PHP CLI e não é um servidor de sites. Tags das imagens devem ser travadas por digest e auditadas antes de qualquer implementação operacional. Os dez gates seguem BLOCKED.

O workflow não usa credenciais pagas externas, não faz push de imagem, não publica endpoints, não configura DNS nem cria outros serviços. A instalação Composer realiza download de dependências públicas para o runner; nenhuma aquisição de serviços ou compromisso financeiro foi feito.
