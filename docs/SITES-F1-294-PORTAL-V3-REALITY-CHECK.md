# Imagine Sites F1-294 — confronto do emissor v3 Portal histórico x desenvolvimento corrente

**12/11/2026 | OP01 #33 | P0 / fail-closed | Laboratório GitHub Actions — nenhum tráfego de produção**

## Problema real

- O verificador do Imagine Sites já conhece o envelope HMAC v3 (CreateProject), incluindo `keyEpoch` e `commercialEpoch`; o PR #357 documentou explicitamente esse contrato.
- A paridade v3 anterior foi provada contra uma revisão histórica candidata do Portal no SHA **`8d1e20bc5c39dc4106b549c5e979351b5c766615`** que contém `SyntheticSitesCommercialEpochSignedEnvelopeSigner.php` (blob Git fixo `e797e3b9829ae8fdb97156f87142f8f12228db08`) e um emissor de laboratório.
- O código do Portal na branch fast-track atual inspecionada, fixado no SHA **`47514f4eac64a3d7ab0a0924cc890fb5a124d8b8`**, **não possui esse caminho esperado** (`apps/portal/app/Domain/SitesIntegration`). Portanto o teste de paridade antigo não demonstra que o Portal atual já consegue emitir mensagens v3. Isso não exclui outras possíveis implementações em outros caminhos; requer revisão de arquitetura.

## Entrega executável

- `ci/f294_portal_current_v3_gate.py` recebe duas árvores Git de código **público**, confere o SHA exato de cada checkout e SHA do blob HMAC histórico e somente examina a presença das classes da integração no local esperado da revisão corrente.
- Com o emissor ausente ou incompleto, retorna `BLOCKED_CURRENT_V3_NOT_ESTABLISHED`; se os dois arquivos estiverem presentes, retorna `BLOCKED_UNREVIEWED_CURRENT_V3_IMPLEMENTATION`. **Ambos são NO-GO**: presença de código não equivale a integração homologada, credencial real ou revisão AppSec.
- Testes locais `--self-test` cobrem ausente, parcial e presente. O workflow `sites-f1-294-portal-current-v3.yml` baixa o **SHA exato** de cada árvore pública via `actions/checkout` read-only (`persist-credentials:false`), roda a análise, prova `releaseAuthorized=false` e `sitesMayConnectToPortal=false` e apaga checkouts temporários.
- O resultado sucesso do workflow significa **bloqueio seguro demonstrado**. NÃO significa que a integração Portal/Sites funcione. Caso a branch Portal evolua, é necessária nova revisão e atualização explícita do SHA fixado; este relatório não é uma consulta dinâmica ao HEAD de produção.

## Ações para desbloquear de verdade

1. Produzir/aprovar implementação v3 no código **atual do Portal**, submetida a revisão do próprio repositório; não promover automaticamente o protótipo histórico para `dev` ou `main`.
2. Testar emissão v3 e consumo Sites a partir de **dois SHAs específicos aprovados** (não de fixture antiga), com schema canônico F1-292, parser raw JSON F1-293 e negativas de assinatura/replay, troca de tenant, revisão contratual e expiração.
3. Configurar identidade serviço-serviço real, cofre/chaves com rotação, autorização por contrato/tenant, banco nonce durável separado de backups restauráveis, revogação monotônica, homologação HTTPS e evidências AppSec.
4. Fechar branch protection #281, revisão independente e os 10 gates operacionais, sem merge/deploy até que haja autorização específica.

**Sem clientes, endpoints reais, PII, DNS, Cloudflare, VPS, segredos, cobrança, merge ou deploy.**
