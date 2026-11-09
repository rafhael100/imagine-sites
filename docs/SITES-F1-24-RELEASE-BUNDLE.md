# SITES-F1-24 — Pacote imutável de prévia para futura publicação (somente laboratório)

**Escopo real:** Laravel e domínio PHP geram um *pacote de prévia não publicável*, a partir de briefing fictício. O objetivo é preparar a futura separação entre produção de HTML, validação de versão, aprovação e publicação. Nenhum dos arquivos gerados deve ser enviado à Cloudflare ou anunciado como site ativo.

## Entregas

- `SyntheticReleaseBundle`: une `ReleaseManifest` e `ReleaseArtifact`, confirma SHA-256 do HTML exato, constrói `index.html` e `release-manifest.json` com metadados versionados e flags obrigatórias `syntheticOnly=true`, `publishable=false`, `officialClientAcceptance=false`.
- O verificador revalida tamanho e hash dos bytes, identidade tenant/site/release e as flags. Alterar conteúdo, tenant, manifesto ou `publishable` invalida o pacote.
- `ReleaseManifest` passa a exigir IDs opacos canônicos (sem barra, barra invertida, whitespace, sequências de diretório ou controles), alinhados com `SiteProjectCommand`.
- O pacote privado rejeita formulários, scripts, `javascript:`, handlers de evento HTML e prévias sem CSP estrita/`noindex` mesmo que o hash seja recalculado. **Isso não substitui sanitização e revisão de segurança reais para conteúdo de clientes.**
- `sites:package-fixture`: comando Laravel em PHP CLI, APP_ENV=testing e `SITES_SYNTHETIC_BUNDLE=1`; grava apenas arquivos com nomes fixos no `storage/app/private` do runner, com permissões restritas. Nunca define site público ou domínio.
- CI PHP 8.3/8.4 exercita Onepage e Landing Page, testes hostis de alteração de HTML, versionamento e bloqueio por padrão.

## Limites e próximos gates

O renderer atual produz **prévia de demonstração** (`noindex`, sem formulário, sem API de leads ou publicação). O bundle desta etapa é deliberadamente **não operacional**; não contém instruções de deploy para Workers Static Assets, credenciais, DNS, URL ou `ENTREGUE`. O formato final de artefato de produção precisará ser distinto do bundle sintético e dependerá de assinaturas do Portal, aprovação expressa, política de formulário/CRM, origem observada, segurança e licenças de conteúdo.

Nenhuma API de IA/n8n/Cloudflare/CRM/VPS foi ativada. Gates P0/RF-PEND/RV-CT/LGPD/AppSec e quotas continuam abertos; AGI-11-RB01 desabilitado. PR em branch filha de `dev`, sem deploy e sem promoção de `main`.
