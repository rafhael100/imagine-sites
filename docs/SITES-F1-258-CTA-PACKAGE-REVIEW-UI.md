## F1-258 — Evidência de quatro arquivos na revisão Laravel

- O comparador de CTA agora reconstrói os pacotes privados completos com `SyntheticPrivateReviewPackageV1`, valida sua paridade F1-257 e recusa inconsistência antes de renderizar.
- O painel de revisão mostra o SHA-256 da evidência completa dos pacotes, sem expor seu conteúdo ou conceder permissão operacional.
- Os 24 cenários Laravel confirmam um mesmo hash de paridade entre versões on/off e desktop/mobile, além do SHA de cada HTML.
- Workflow dedicado executa novamente os controles F1-256 e F1-257, mais a regressão de interface Laravel.

Ficção para laboratório com IP local e `APP_ENV=testing`; sem cliente real, acesso público, upload, deploy, CRM, Cloudflare, DNS ou custos. Todos os dez gates de produção continuam bloqueados.