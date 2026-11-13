# Imagine Sites — candidato integrado F1-299 para revisão em dev

**13/11/2026 | DRAFT · NO-GO · base dev 71b8ffe51139d0e70ed32591b07f295fb7bf5fe3**

## Entregas consolidadas e antecedentes
- PR #361: integra F1-293/294 e contratos de HMAC v3; candidata a revisão, não integração operacional.
- PR #362 / F1-295: verificador de presença real de estrutura de emissor v3, recusando stubs; **5/5 workflows success** no seu SHA anterior.
- PR #363 / F1-296: hash de pacote completo privado (HTML + NUL + manifesto) e verificação do readback; **19/19 workflows success** no SHA funcional.
- PR #364 / F1-297: criação em staging privado, hash antes da promoção atômica e sem sobrescrita de pasta; **18/18 workflows success** no SHA documentado.
- PR #365 / F1-298: comando `sites:verify-fixture-bundle` recebe digests de HTML/pacote e verifica os dois arquivos em disco sem escrever; **19/19 workflows success** no SHA da PR.
- PR #366 / F1-299: Laravel in-process exclusivamente CLI/testing verifica raw JSON HMAC v3 e nega replay, duplicatas, downgrade, epochs, cross-tenant; cobertura de rotas cacheadas em PHP cli-server e Nginx/PHP-FPM. **30/30 workflows success** no SHA `41263b69d77b4b1ea9709642a2cf91e640bc2835`.

Todas as quantidades são evidências históricas de seus SHAs próprios. **O SHA exato deste candidato integrado ainda requer reexecução e revisão independente.**

## Auditoria integral (indispensável)
- F1-240: enumerar TODOS os caminhos do diff usando Git (não os primeiros 300 da API de comparação), confirmar base `dev` exata, SHA de cabeça, riscos e dez gates falsos.
- F1-241: gerar seis pacotes com hashes e correspondência exata ao inventário F1-240, incluindo AppSec, DBA, Portal/CRM, infraestrutura, UX/acessibilidade e testes.
- Reexecutar PHP 8.3/8.4, Laravel, PostgreSQL 18 descartável, Chromium, contratos v3, Worker e negativas de deploy, Nginx/PHP-FPM, conteúdo e artefatos. Ausência de falhas não equivale à assinatura de review.
- Conferir visual Imagine original no painel administrativo (logomarca PNG e laranja/grafite); não modificar paletas dos templates dos clientes.

## Operação permanece bloqueada
`config/operational-readiness.json` deve permanecer `BLOCKED`, `non_operational=true`, **dez gates falsos**. Issues:
- #281: proteção real das branches e revisões independentes;
- #33: IAM/mTLS ou alternativa validada, chave gerenciada, emissor **atual** v3 no Portal, revogação e autorização comercial;
- #34: banco PostgreSQL isolado, RLS, restore e nonce durável;
- #35: Cloudflare/SSL, permissão de domínio, manifesto assinado, observação independente do conteúdo servido;
- #36: CRM e armazenamento secundário físico, idempotência global, T0 original 432h e LGPD;
- #37: AI Agents/n8n com aprovação humana, limites e conteúdo seguro;
- #38: homologação Leaseweb/Cloudflare e gate de go-live aprovado.

**Não fazer merge em dev/main, não criar deploy, DNS, Cloudflare, VPS, cliente, PII, segredos, cobrança ou custos.** Somente CI sintético e documentação de revisão de código. Um futuro go-live exige evidências independentes de ambiente, segurança e autorização explícita.
