# F1-37 — Prévia privada e proposta de revisão geradas pelo Laravel (sem VPS)

Comando de laboratório: `APP_ENV=testing SITES_SYNTHETIC_REVIEW=1 php artisan sites:review-fixture onepage` ou `landing_page`, apenas CLI. Sem a flag explicitamente configurada, é recusado.

A suíte produz **somente no armazenamento privado do runner**:
- `index.html`: página estática demonstrativa, CSP/noindex, sem formulários, scripts, links externos ou captação;
- `release-manifest.json`: pacote F1-24 com hash SHA-256 exato, `publishable=false` e `officialClientAcceptance=false`;
- `portal-review-candidate.json`: proposta informativa F1-28/F1-31 de revisão, com revisão 3 e digest idêntico, também sem poder de aceite e sem publicar.

O conteúdo é ficcional: `StructuredCopyCandidate` (F1-29) valida a saída tipada e a marca oficial do laboratório é fornecida **fora** da proposta do gerador; `SyntheticCreditLedger` (F1-30) reserva um crédito abstrato somente em memória; `SyntheticProductionWorkflow` avança por rascunho, revisão interna fictícia e encaminhamento de candidato. Antes de gravar, `InertPreviewAudit` (F1-27) e `SyntheticReleaseBundle` garantem as restrições estritas de demo.

Controles de CLI: ambiente `testing`, flag `SITES_SYNTHETIC_REVIEW=1`, valores permitidos para produto, destino privado sem symlinks, diretório inexistente antes de gravar, nomes de arquivos fixos, permissões locais e nenhum disparo externo. Testes PHP 8.3/8.4 confirmam Onepage/Landing, erros sem flag, caminho inválido, repetição no mesmo destino e symlink.

**Não é uma interface pública nem uma integração operacional**. Não acessa o Portal real, não realiza IA, não autentica revisor/cliente, não implementa envio, upload, CDN ou publicação. Aprovação expressa e evento oficial `ENTREGUE` continuarão exclusivamente no Portal. Nenhuma credencial foi usada. P0/RF-PEND/RV-CT, LGPD/AppSec, OP03/OP05 e readiness continuam bloqueados; main/VPS/Cloudflare intocados.
