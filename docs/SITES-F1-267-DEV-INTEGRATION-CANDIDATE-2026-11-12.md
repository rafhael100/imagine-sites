# Imagine Sites — candidato F1-267 para revisão completa de integração

**Draft, sem merge e sem deploy — 12/11/2026.** Base `dev`: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`; `main` intocada.

- Consolida a árvore de desenvolvimento até F1-265 (#309), com prévia privada Cloudflare Worker e verificação Ed25519 somente de laboratório.
- F1-266 / #310: correção de inicialização Chrome CDP em runner restrito, com repetição única somente para falha específica de bootstrap e diretório vazio. CI 6/6 verde no próprio PR.
- F1-267 / #311: proteção adicional de CI que identifica variantes de comandos Wrangler e infraestrutura, permissões de escrita e symlinks em `.yml` e `.yaml`; CI dedicado aprovado no próprio PR.
- O candidato F1-265 anterior #309 tinha 97/98 checks, com uma falha de porta CDP no runner; não contar como aprovação. O novo candidato requer novo CI **inteiro** no HEAD exato.
- Executar inventário completo F1-240, pacotes F1-241, PHP/Laravel, PostgreSQL 18, Chrome, CI security e Node Worker/assinaturas, validar SHAs e os dez gates `BLOCKED`.
- Solicitar revisões independentes AppSec/DBA/Portal/CRM e administração GitHub de `dev/main` segundo issue #281 antes de qualquer merge.

Sem dados reais, publicação, provedor, CRM ativo, chaves de produção, domínio, VPS ou despesas. Nenhum workflow ou assinatura sintética constitui autoridade operacional.