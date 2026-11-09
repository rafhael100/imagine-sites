# SITES-F1-07 — Integração Laravel → renderizador estático com prévia privada

**Tipo:** laboratório F1-07, filho do PR #6. Nenhuma rota de publicação, URL de cliente, cloud, HTTP, base ou integração real.

O composer.json de `apps/sites` passou a mapear o domínio `Imagine\\Sites\\Domain\\` e o renderizador `Imagine\\Sites\\Production\\` existentes na raiz do repositório. A alteração de `autoload` não modifica versões do `composer.lock`; o CI valida o estado com `composer validate --strict`.

O comando Artisan `sites:preview-fixture {product}` só funciona quando `APP_ENV=testing` **e** `SITES_SYNTHETIC_PREVIEW=1` estiverem configurados. Aceita exclusivamente `onepage` e `landing_page`, sempre gerando conteúdo da fixture fictícia de F1-06.

O HTML é escrito em `apps/sites/storage/app/private/`, **não** em public/ e não é persistido em repositório. CI cria, confere a saída e remove os arquivos descartáveis, sem subir artefatos. É somente fluxo de ensaio, não publicação, envio de dados ou prévia entregue ao cliente.

Testes Laravel confirmam: denegação por padrão, flag explícita, output privado, hash do conteúdo, dois produtos e bloqueio de path traversal. O endpoint de health `/up` permanece único e não prova integração externa.

Próximos gates: contrato Portal↔Sites real S2S, validação/verificação de aceite, storage de releases, proteção RLS por tenant e publicação observada; jamais habilitar geração com briefings reais, URLs ou dados privados sem P0/RF-PEND, política LGPD e autorização específica.

**Sem merge/deploy automático, Cloudflare/DNS, CRM, Agents, custos ou produção. AGI-11-RB01 desabilitado.**
