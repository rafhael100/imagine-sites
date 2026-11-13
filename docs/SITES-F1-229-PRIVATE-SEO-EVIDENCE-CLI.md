# F1-229 — visualizar evidência SEO no terminal (somente laboratório)

A comparação de SEO \`sites:diff-seo-copy-fixture\` já validava os dois pacotes privados padrão/personalizado. Agora reaproveita F1-228 e permite ler o **registro estruturado** sem exportação, gravação, conexão externa ou publicação.

O comando permanece estritamente restrito a PHP CLI + \`APP_ENV=testing\` + \`GITHUB_ACTIONS=true\` + \`SITES_F227_SEO_DIFF=1\`. Somente enumerações fechadas de produto, tema e metadados sociais são aceitas, e ambos os pacotes continuam exigindo leitura física segura, verificação SHA e mesmo escopo empresa/site.

### Uso em CI após exportar as duas metades F1-223

\`\`\`bash
php apps/sites/artisan sites:diff-seo-copy-fixture onepage classic off
php apps/sites/artisan sites:diff-seo-copy-fixture onepage classic off --evidence-json
\`\`\`

A saída tradicional ganha um hash SHA-256 da evidência. Com \`--evidence-json\`, a saída torna-se **somente JSON** determinístico: 8 hashes de arquivos, dois digests de HTML, versão do esquema, campos permitidos \`seoTitle\` e \`seoDescription\`, flags explícitas de não aprovação e correlação de escopo não autenticada. O comando não divulga texto nem URLs e não modifica a pasta privada.

Testes Laravel existentes de F1-227 cobrem 12 pares, consistência JSON↔SHA, ausência de conteúdo e verificação de leitura repetida. **Não representa** aceite do cliente no Portal, identidade de serviço, geração por IA, SEO Engine/Google, Indexação, publicação, CRM ou homologação de VPS/Cloudflare. Não altera `main`/`dev` nem os gates OP01–OP05, P0 e AppSec.
