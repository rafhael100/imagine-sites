# STATUS-ATUAL — Imagine Sites Engine

Data 2026-11-08; F1-13 laboratório HTTP in-process de contrato, branch filha de F1-12.

PRs #1–12: domínio, Laravel, contrato, PostgreSQL/RLS, geração HTML, prévia privada, manifesto/aceite, publisher simulado, dupla captação fictícia, HMAC sintético e nonce PostgreSQL, todos draft/sem merge; últimos CIs PASS.

F1-13: rota POST _lab/sites/v1/create-project registrada **somente** no Kernel PHP CLI com APP_ENV=testing e flag explícita; assinatura HMAC e store/autoridade DENY por padrão, mocks para testes adversariais. CI PHP 8.3/8.4 a conferir.

A rota não recebe dados reais, não faz SQL ou rede, não cria projeto e não é API produtiva. O 202 de teste significa somente validação sintética.

Gates P0/RF-PEND/RV-CT/AppSec/LGPD continuam abertos; nenhuma Cloudflare/DNS/VPS, merge ou deploy; AGI-11-RB01 desabilitado.
