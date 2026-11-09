# SITES-F1-13 — Kernel HTTP Laravel para contrato Portal→Sites, laboratório estritamente CLI

**Escopo:** CI do próprio Laravel processa requisições JSON sintéticas em processo, sem expor porta de rede, API pública, cliente, Cloudflare ou VPS.

A rota `POST /_lab/sites/v1/create-project` só é registrada se **todas** as condições forem verdadeiras: PHP_SAPI=cli, APP_ENV=testing e `SITES_SYNTHETIC_HTTP=1`; também verifica IP loopback. Por padrão, não existe nem no `route:list`. O teste em Laravel executa HTTP internamente, sem iniciar servidor. Nenhum endpoint operacional `/api/*` ou de contrato, login, site ou leads é habilitado.

A requisição passa pelos seguintes controles sintéticos: estrutura assinada HMAC do F1-11 com TTL/nonce e chave pública falsa, replay store **DENY padrão**, autoridade Portal **DENY padrão** e, nos casos positivos de teste apenas, implementações injetadas explicitamente de store e contrato fictícios. A resposta 202 `contract_validated_not_executed` **não cria projeto, não grava dados e não publica nada**.

Testes cobrem default DENY, contrato fictício assinado, replay, adulteração, tenant diferente e acesso não-loopback. A suíte roda em PHP 8.3/8.4 tanto com o recurso desabilitado quanto ativado apenas no runner.

**Impedimentos de produção:** sem TLS/mTLS, secret vault, tokens reais, certificado do Portal, revalidação de contrato, store persistente conectada, filas/outbox, RLS operacional, identidade de usuário, banco de pedidos, migrações, monitoramento ou deploy. Rota com key conhecida somente em laboratório não é API utilizável. A arquitetura exige gate formal antes de construir endpoint de produção.

Gates P0-DOC01–14, RF-PEND-01/RF-PEND-04, RV-CT, LGPD e AppSec abertos. Sem merge/deploy automático, credenciais, custos ou servidores. AGI-11-RB01 desabilitado.
