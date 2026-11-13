# F1-237 — Teste HTTP com PHP-FPM, Nginx e rotas Laravel já cacheadas

A F1-234 testou rotas cacheadas sob o servidor HTTP interno do PHP (`cli-server`). Esta entrega exercita o runtime mais próximo do servidor previsto: **PHP-FPM 8.4 e Nginx**, em contêineres efêmeros do GitHub Actions, com rede Docker `internal: true` **sem portas publicadas no host**. **Não** se trata de stack de homologação ou configuração para produção.

## Prova automatizada

O Dockerfile específico `ops/fpm-probe/Dockerfile` instala a aplicação Laravel e **executa `php artisan route:cache` com as flags sintéticas ativas** durante o build PHP CLI. Confirma que a rota `/_lab/sites/v1/create-project` está efetivamente compilada. A imagem resultante é iniciada com `php-fpm -F` e usuário não-root 10001; o Nginx independente a acessa apenas pela rede interna do Docker.

Um terceiro contêiner sem privilégios (`curlimages/curl`) valida:
- `GET /up` = **200**, demonstrando servidor Laravel funcionando;
- `POST /_lab/sites/v1/create-project` = **404**;
- `GET /_lab/sites/v1/review` e `seo-copy-review` = **404**;
- `/_lab/sites/v1/unknown-future` e rotas de publicação/integração não autorizadas = **404**.

O experimento preserva `SITES_SYNTHETIC_HTTP=1` em runtime para verificar a negativa decorrente do SAPI, do middleware global F1-233 e da proibição de endpoints operacionais. O fluxo executa build, valida usuário da imagem, roda o probe e destrói contêineres/redes e volumes em `if:always()`.

## Controles e limites

- Todos os serviços usam rede `internal: true`, `cap_drop: ALL`, `no-new-privileges`, sem `ports` ou `network_mode: host`; o workflow confere o Compose renderizado antes do build.
- O Dockerfile usa contexto estrito `Dockerfile.dockerignore`, sem `.env`, certificados, segredos, bibliotecas locais ou chaves. Não envia imagem a registry, não instala na VPS e não muda DNS.
- Nginx/PHP-FPM e os testes rodam em runners descartáveis com fixtures de demonstração; a execução **não demonstra** IAM Portal, TLS/Cloudflare, rotas de produção, observabilidade, retenção ou backups.
- Após a revisão da arquitetura/infra, será necessário um teste de homologação com proxy/TLS/segredos geridos por vault, sem reutilizar este Compose de CI. Os dez gates de produção permanecem `BLOCKED`.

**Resultado esperado:** ampliar a evidência de defesa em profundidade sem criar um serviço operacional ou publicar sites de clientes.
