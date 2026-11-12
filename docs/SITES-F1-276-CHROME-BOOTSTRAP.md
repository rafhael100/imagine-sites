# F1-276 — Corrigir inicialização do Chrome no laboratório de contraste

O candidato F1-275 / PR #324 passou em 106 de 107 workflows. O único erro foi `TimeoutError: Local Chrome DevTools port never became complete` antes de carregar qualquer página, no laboratório F1-59 de CSS, gradiente e contraste.

Correção: Chrome headless passa a usar `--disable-dev-shm-usage` para evitar esgotamento do `/dev/shm` no runner. O workflow captura apenas o texto de stderr/stdout no `RUNNER_TEMP`; quando o primeiro processo falhar **exatamente** por timeout de bootstrap CDP sem ter reportado uma página `PASS F1-59`, repete a execução uma única vez, com novo perfil descartável. Caso contrário, falha imediatamente com código original.

**Não há retry para violações de cor, contraste, HTML, segurança, CSS, responsividade, teclado, hashes ou captura.** O conjunto original de 12 páginas sintéticas e verificação de 320px continua obrigatório. O laboratório não obtém Chrome pela rede nem acessa dados de clientes, Cloudflare, VPS ou produção. Sem merge/deploy; dez gates bloqueados.