# F1-377 — Requisição HTTP real por Nginx → Laravel PHP-FPM → PostgreSQL18

**Resultado esperado:** comprovar transporte HTTP e persistência durável reais em **um ambiente totalmente descartável do GitHub Actions**. Não é uma API de clientes, e a autoridade comercial do Portal continua fictícia.

## Arquitetura testada

O GitHub Actions estende a topologia F1-373/F1-376 com o overlay `ops/homologation/compose.ci-http.yaml`: Nginx expõe apenas `127.0.0.1:18081`, o PHP-FPM usa a rede privada de contêineres para acessar `sites-db` em PostgreSQL18, e o banco não publica porta alguma no host.

A rota `POST /_ci/sites/v1/admit` **só é registrada** quando o processo PHP-FPM roda em `APP_ENV=testing` no CI, com `GITHUB_ACTIONS=true`, `SITES_F377_CI_HTTP_LAB=1` e chave **pública/fictícia** do laboratório. Se faltar qualquer condição, nem a rota existe. A suíte inicializa os mesmos contêineres com a flag desligada e comprova HTTP 404 antes de recriar apenas o PHP-FPM com a flag habilitada. A rota anterior `/_lab/sites/v1/create-project` continua inacessível em PHP-FPM.

O corpo JSON possui somente `siteRef` e envelope canônico `signed`. A assinatura HMAC, janela de validade e nonce são verificados por `SyntheticSignedCommand`. O serviço Laravel da F1-375 registra nonce, comando e outbox na mesma transação PostgreSQL, e a role autenticada `sites_f360_b` valida vínculo tenant→SQL em `session_user`. A autorização é aprovada exclusivamente por `CIHttpPortalAuthority`, um **mock permissivo de teste** que não consulta contratos, usuários, pagamentos ou revogação real. Esse mock nunca é registrado em ServiceProvider de produção.

## Verificações

- Requisições HTTP reais para Onepage e Landing Page geram status sintético `queued` e um evento outbox por operação
- Repetição exata do nonce recebe HTTP 403; retry com novo nonce e mesma operação é idempotente
- Alteração do contrato sob MAC antigo, comando expirado, tenant diferente, siteRef inseguro, content type inválido, marcador de transporte ausente e corpo grande são recusados
- Novo conteúdo sob mesma chave idempotente gera HTTP 409, sem sobrescrever contrato
- Falha no outbox gera HTTP 503, sem resposta de compra autorizada, e a repetição comprova rollback do nonce
- Uma consulta administrativa **separada do processo Laravel** confere exatamente três admissões, três eventos outbox e cinco nonces válidos no banco descartável, sem entradas indevidas de outro tenant ou admissão órfã
- Todos os contêineres e volumes são destruídos após a execução

## Segurança e limitações

O header `X-Imagine-CI-Transport: f377-local` é apenas um marcador de CI, **não um fator de autenticação**. O HMAC é assinado por chave fictícia incluída no código de teste; isso não autentica o Portal real. O uso de `APP_ENV=testing`, a flag de CI e o acesso loopback são proteções contra registro acidental, não um substituto para IAM/mTLS e perímetro de produção.

O endpoint não fica disponível no Compose de homologação padrão e **não deve ser habilitado na Leaseweb**. Falta implementar a API operacional autenticada com chaves seguras em vault, autoridade de contrato/pagamento do Portal, revogação externa, TLS, migrações versionadas, política de backups, aceite de cliente, rollback, auditoria LGPD/AppSec e testes de segurança independentes.

**PR DRAFT/NO-GO.** Nenhuma compra real, cliente, DNS, Cloudflare, VPS, merge ou publicação são afetados.
