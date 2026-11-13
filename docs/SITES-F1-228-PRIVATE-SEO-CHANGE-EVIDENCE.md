# F1-228 — evidência reproduzível para revisão privada de SEO

Esta entrega cria \`SyntheticPrivateSeoChangeEvidenceV1\`, um registro canônico **somente em memória** a partir de dois pacotes privados já verificados (SEO padrão e SEO personalizado). O registro não contém título, descrição, HTML, referências de empresa/site em claro, URL, PII ou chave.

- Compara exclusivamente o conteúdo SEO de \`<head>\` via F1-226 e revalida os **quatro arquivos** e suas somas SHA-256 nos dois pacotes.
- Nega comparação entre empresas/sites, troca de conteúdo visível, mudança de robots/noindex, troca de metadados sociais e revisão sem diferença real.
- Emite JSON estável com versão de esquema, digest de correlação **não autenticado**, hashes de revisão/HTML/arquivos, campos alterados e sinalizadores explicitamente **false** para aceite, publicação, indexação e escrita no SEO Engine.
- \`stillMatchesVerifiedPair()\` revalida ambos os pacotes de origem antes de comparar a evidência; um hash de evidência isolado **não** confirma quem aprovou.
- Exercita 12 pares sintéticos (2 produtos × 3 estilos × social on/off) e casos negativos de adulteração/reversão/cross-tenant, sem gravação em disco, rede ou APIs.

## Executar em CI ou ambiente isolado

\`\`\`bash
php -l src/Production/SyntheticPrivateSeoChangeEvidenceV1.php
php -l tests/f228-private-seo-change-evidence.php
php tests/f228-private-seo-change-evidence.php
\`\`\`

**Limites:** nenhuma aprovação real, assinatura HMAC de cliente, identidade Portal, hospedagem, sitemap, Google/Search Console, publicação, integração CRM/SEO Engine, VPS ou deploy. \`main\` e \`dev\` inalteradas; F1-228 é candidata em PR draft. Gates OP01–OP05, P0/AppSec/LGPD e homologação operacionais continuam bloqueados.
