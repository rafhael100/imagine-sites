# F1-365 — HTTP de laboratório entre processos PHP e PostgreSQL 18

**Estado:** candidato isolado para revisão de arquitetura e segurança. Esta etapa testa tráfego HTTP real na interface de loopback do GitHub Actions, mas NÃO cria API Laravel, serviço disponível na VPS, autenticação produtiva, gateway público, pagamento ou publicação.

## Objetivo

A F1-364 já garante no laboratório a admissão de comando assinado, controle durável de nonce, transação com admissão e outbox no PostgreSQL 18. Porém o fluxo era chamado diretamente no mesmo processo CLI. A F1-365 acrescenta uma fronteira HTTP e uma segunda execução PHP por requisição. A intenção é eliminar a suposição de que a integração funciona só porque duas bibliotecas executam no mesmo processo.

## Caminho executado

1. O cliente de teste chama as classes candidatas fixadas do Portal (commit bf1b2794de4d6000f120ffa2b1e6dd557f34c35e) para preparar e assinar pedidos fictícios Onepage e Landing Page.
2. Usa classes candidatas fixadas do Sites (commit 21732e9558b730e38a6845adb593d29f309ff6a9) para atestações fictícias de Portal e autoridade de revogação.
3. Faz uma requisição HTTP POST JSON a **127.0.0.1:18965**, exclusivamente no GitHub Actions, com envelope assinado, duas atestações e referência fictícia de site.
4. Um router do servidor embutido do PHP, bloqueado para chamadas remotas/hosts inesperados, aceita somente a rota experimental /v1/lab/sites/admit e o endpoint de saúde /health. Existe limite de 8192 bytes, validação de método e Content-Type e proibição de outros caminhos.
5. O router chama um **processo PHP CLI separado**, com caminho de comando fixo (sem shell), passando JSON por stdin. Esse processo utiliza o adaptador F1-364 sem modificar sua trava CLI; verifica assinatura, fontes fictícias, vínculo de empresa, nonce persistido e insere o pedido mais evento em transação única no banco descartável.
6. A resposta HTTP informa somente queued, duplicate_ignored ou erro genérico. Nunca devolve SQL, credenciais, stack trace, dados pessoais, aceite de cliente nem estado publicável.

## Casos negativos

- Método, tipo de conteúdo, formato e tamanho incorretos; host não confiável; caminho desconhecido
- Reutilização de uma mesma assinatura/nonce por requisição distinta, com rejeição por replay persistente
- Reenvio com assinatura e nonce novos, mas idempotência comercial idêntica, sem segundo projeto/outbox
- Alteração de empresa dentro de envelope já assinado, contrato revogado e assinatura autorizada para empresa diversa da conexão SQL
- Tentativa de path traversal no identificador fictício de site
- Erro transacional intencional do outbox, seguido de retry com mesmo nonce após rollback, sem deixar admissão nem nonce órfão
- Consultas SQL finais do runner conferem quantidades de pedidos, eventos e nonces duráveis

## Configuração e isolamento

Os três arquivos tests/fixtures/f365-loopback-router.php, f365-loopback-worker.php e tests/f365-loopback-http-client.php são executados no **mesmo job temporário PG18** das F1-360..364, matriz PHP 8.3/8.4. A conexão de rede é explicitamente 127.0.0.1, a porta é fixa e o GitHub Actions remove o processo após o teste. O script exige APP_ENV=testing, GITHUB_ACTIONS=true, SITES_F365_HTTP_LAB=1, demais flags de banco e host/DB descartáveis. Todas as chaves e senhas são string fictícia de fixture, nunca devem ser usadas fora do CI público.

**Não substituir as travas por um simples GITHUB_ACTIONS=true em serviços implantados.** Uma variável de ambiente não autentica origem de requisições. O teste HTTP comprova apenas a sequência técnica em um host, não a identidade do Portal real, isolamento de redes, TLS/mTLS, secrets manager, contrato financeiro, revogação fora do domínio do restore, domínio homologado nem um checkout funcional.

## Critérios ainda pendentes

OP01 (#33), OP02 (#34), OP03 (#35), OP04, OP05 e infraestrutura (#38) continuam pendentes. A publicação exige revisão DBA/AppSec, autenticação e origem dos contratos, direitos do titular, reconciliação dos eventos, verificação de release, Cloudflare/DNS e gate formal. **PR draft/NO-GO; sem merge/dev/main, VPS, Cloudflare, cliente ou custo operacional.**
