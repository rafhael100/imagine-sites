# F1-38 — Interface visual de revisão em laboratório

Somente ambiente **CLI Laravel testing** com dados inteiramente fictícios. A rota de teste GET `/_lab/sites/v1/review` oferece alternância Onepage/Landing Page e modo desktop/mobile, sem JavaScript, formulários ou editor self-service.

**Portões de segurança:** registro da rota dentro do gate já existente `PHP_SAPI=cli`, `APP_ENV=testing` e `SITES_SYNTHETIC_HTTP=1`. Requer adicionalmente `SITES_SYNTHETIC_REVIEW_UI=1` e IP de loopback. Não pode ser acessada via PHP-FPM ou servidores HTTP públicos.

**Integridade:** o HTML apresentado no iframe `sandbox` é o resultado exato do `StaticSiteRenderer`, e seu SHA-256 é exibido. Conteúdo passa por `StructuredCopyCandidate`, `InertPreviewAudit`, `SyntheticProductionWorkflow`, `SyntheticReviewCandidateGuard` e `SyntheticReleaseBundle`. A marca, o site e os IDs são fictícios. O fluxo mostra revisão interna simulada e aceite oficial ausente. Não executa IA, não envia ao Portal, não grava banco, não publica e não marca ENTREGUE.

**Headers:** CSP bloqueia scripts, formulários, network fetch e incorpora a prévia sandboxed; frame-ancestors none, X-Frame-Options DENY, X-Robots-Tag noindex, Cache-Control no-store, Permissions-Policy restritiva e Referrer-Policy no-referrer.

**Testes:** workflow PHP 8.3/8.4 comprova rota ausente sem flag, modos de produto e viewport, hash byte-a-byte do srcdoc, negação para query inválida, IP não-loopback, flag desligada e rotas comerciais inexistentes.

**Limitações:** é uma UI in-process e não site navegável pela internet; não possui identidade real, autenticação do cliente, captura de screenshots de navegador, persistência, aceite, API ou custos. Antes de qualquer homologação real, será necessário revisão de segurança, identidade, domínio técnico de teste e autorização. Gates P0/OP03/OP05/AppSec/LGPD e config/operational-readiness.json continuam BLOCKED. A branch main, VPS e Cloudflare permanecem intocados.
