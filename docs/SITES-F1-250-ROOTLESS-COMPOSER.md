# F1-250 — instalação Composer sem scripts/plugins privilegiados nos laboratórios Docker

## Risco identificado

Os Dockerfiles **apenas de CI** em `ops/staging/Dockerfile` e `ops/fpm-probe/Dockerfile` executavam `composer install` como **root** sem desabilitar scripts/plugins. O `composer.json` do Laravel declara `post-autoload-dump` com execução de `php artisan package:discover`; a ação de um plugin ou script de dependência seria executada com privilégios elevados na etapa de construção, antes de mudar para UID 10001.

## Correção

- Os dois Dockerfiles agora executam instalação com **`--no-scripts --no-plugins`**, mantendo lockfile e `--prefer-dist`.
- A descoberta explícita `php artisan package:discover --ansi` ocorre **depois** da instrução `USER 10001:10001`, com `APP_ENV=testing`. As pastas de cache já são criadas e passam à propriedade de UID 10001.
- No FPM, `php artisan route:cache` e a confirmação da rota sintética compilada também são executados somente **como UID 10001**, antes de executar o PHP-FPM restrito.
- O teste `ci/f250_rootless_composer_build_fence.py` examina ambos os Dockerfiles e executa negativos que simulam remoção das flags, mudança para root, `COPY .`, descoberta de pacotes como root e cache de rota antes da troca de usuário.
- A nova CI de 5 minutos executa a auditoria estática, enquanto os workflows **F1-83** (Laravel/PostgreSQL Docker CLI) e **F1-237** (Nginx/PHP-FPM sem portas públicas) executam *builds reais* no mesmo head da PR para detectar incompatibilidades.

**Limites:** a análise de flags e do usuário reduz o risco na construção, mas não substitui auditoria das dependências, CVEs, assinatura de imagens ou SBOM. São imagens descartáveis de CI, não artefatos para produção. Não modifica VPS, Cloudflare, DNS, cliente, Portal/CRM, credenciais nem libera os dez gates `BLOCKED`.
