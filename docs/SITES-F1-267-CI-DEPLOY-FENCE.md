# F1-267 — bloqueio de variantes de deploy em workflows GitHub

Após a introdução do protótipo Cloudflare Worker, foi ampliada a verificação conservadora de CI para reduzir o risco de um workflow publicar acidentalmente código ou alterar infraestrutura.

A auditoria agora busca também variantes de `wrangler` via `pnpm`, `bunx`, `npx`, subcomandos `versions upload/deploy`, `triggers deploy`, `pages deployment create` e `wrangler --env ... deploy`, além de comandos de alteração de infraestrutura (Terraform, Pulumi, GCloud, S3 e push de imagens), e permissões de escrita de repositório/pacotes. Arquivos `.yml` **e** `.yaml` são examinados; symlinks de workflow são recusados.

Um novo workflow de somente leitura executa o scanner F1-67, o guarda F1-21 e o inventário de gates quando qualquer workflow ou scanner for modificado. Testes incluem exemplos explícitos de comandos proibidos e comandos legítimos. O scanner é defesa em profundidade, **não** certificação AppSec nem substituto de review de scripts e permissões.

Todos os dez controles operacionais permanecem `BLOCKED`. Nenhuma publicação, atualização de segredo, API de provedor, cliente, CRM, VPS, DNS ou merge é autorizada.