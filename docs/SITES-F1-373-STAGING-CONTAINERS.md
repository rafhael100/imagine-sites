# F1-373 — Imagens de contêiner para ensaio local de homologação Imagine Sites

**Situação:** preparação da aplicação Laravel 13 para contêineres, sem disponibilização da VPS nem autenticação com o Portal real. PR em rascunho/NO-GO.

## O que foi preparado

O diretório `ops/homologation` traz um `Dockerfile` com múltiplas etapas para PHP 8.3 FPM e Nginx; o Composer instala dependências travadas em `composer.lock` sem os pacotes de desenvolvimento. A estrutura relativa `apps/sites/../../src` é preservada em `/opt/imagine/apps/sites` e `/opt/imagine/src`, mantendo as classes do motor de Sites fora da aplicação.

O `compose.yaml` sobe **apenas dois serviços**, PHP-FPM e proxy Nginx, com uma rede privada interna entre os serviços, uma rede de entrada do proxy e uma única porta do host **127.0.0.1:18081**. O servidor não escuta em `0.0.0.0`, não publica o PHP-FPM, desativa debug, usa armazenamento de sessão/cache em memória, filesystem de execução somente leitura e controles de não elevação. Nginx nega acesso direto a arquivos PHP, caminhos ocultos e impõe cabeçalhos `noindex`, `no-store` e `nosniff`.

A aplicação exige `SITES_STAGING_APP_KEY` injetada externamente e nunca inclui chaves reais. O banco está deliberadamente configurado para um hostname inexistente (`sites-database-not-provisioned`) e o ambiente **não registra rota operacional de contratação**. O endpoint `/up` serve apenas para testar o processo PHP/Laravel, não readiness de SQL, revisão/aceite do cliente, CRM, pagamento ou publisher.

O workflow `.github/workflows/sites-f1-373-staging-container.yml` constrói os dois contêineres em um runner efêmero, usando apenas uma `APP_KEY` pública e fictícia de CI, valida o `/up` e testa que criação de projeto, rotas de laboratório, acesso direto a `index.php` e `.env` estão fechados, com limpeza ao fim. Não há deployment automatizado nem gastos com terceiros.

## Próximos gates de verdade

Antes de qualquer instalação em Leaseweb, ainda serão necessárias: rede e DNS técnico privados, TLS, autenticação oficial Portal↔Sites, banco PostgreSQL operacional com roles sem privilégios, backups externos/restauração, segredo no vault, emissão de contratos e pagamentos oficiais do Portal, regras LGPD, rastreabilidade, observabilidade, testes de carga e aprovação AppSec/DBA. O banco de demonstração das F1-360–372 não deve ser transposto diretamente para produção.

**Não executar esse Compose como publicação**: o acesso HTTP é intencionalmente local, sem TLS, login, gateway de autorização ou workflow comercial. Nenhuma rota de cliente ficou disponível. `dev`, `main`, VPS, Cloudflare e DNS permanecem intocados.
